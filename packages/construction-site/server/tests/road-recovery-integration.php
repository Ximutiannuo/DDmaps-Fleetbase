<?php

// Actual Laravel/MySQL operations, isolated by an outer transaction that always rolls back.
require __DIR__ . '/../../../../api/vendor/autoload.php';
$app = require __DIR__ . '/../../../../api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Fleetbase\ConstructionSite\Events\RoadNetworkChanged;
use Fleetbase\ConstructionSite\Http\Controllers\RoadRecoveryController;
use Fleetbase\ConstructionSite\Http\Controllers\RoadSectionController;
use Fleetbase\ConstructionSite\Http\Controllers\RoadSegmentController;
use Fleetbase\ConstructionSite\Http\Controllers\RoadTrafficController;
use Fleetbase\ConstructionSite\Http\Controllers\TrackingController;
use Fleetbase\ConstructionSite\Models\EntryRegistration;
use Fleetbase\ConstructionSite\Models\RoadSegment;
use Fleetbase\ConstructionSite\Models\RoadTrafficControl;
use Fleetbase\ConstructionSite\Services\OsmRoadImportService;
use Fleetbase\ConstructionSite\Services\RoadTrafficService;
use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\LaravelMysqlSpatial\Types\LineString;
use Fleetbase\LaravelMysqlSpatial\Types\MultiPolygon;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\LaravelMysqlSpatial\Types\Polygon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

function recoveryCheck(bool $condition, string $description): void
{
    if (!$condition) throw new RuntimeException($description);
    echo "PASS $description\n";
}
function recoveryReject(callable $action, int $status, string $description): void
{
    try { $action(); } catch (Throwable $e) {
        $actual = $e instanceof HttpException ? $e->getStatusCode() : ($e instanceof ModelNotFoundException ? 404 : ($e instanceof ValidationException ? 422 : null));
        if ($actual !== $status) throw $e;
        recoveryCheck(true, $description);
        return;
    }
    throw new RuntimeException('Unexpected accepted action: ' . $description);
}
$company = (string) Str::uuid(); $other = (string) Str::uuid();
session(['company' => $company]);
Event::fake([RoadNetworkChanged::class, Fleetbase\ConstructionSite\Events\SiteNotificationCreated::class]);
$request = fn (array $data) => Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($data));
DB::beginTransaction();
try {
    foreach ([$company, $other] as $uuid) DB::table('companies')->insert(['uuid' => $uuid, 'name' => 'Road recovery fixture']);
    $polygon = fn ($lat) => new MultiPolygon([new Polygon([new LineString([
        new Point($lat, -135.74), new Point($lat, -135.71), new Point($lat + .02, -135.71), new Point($lat + .02, -135.74), new Point($lat, -135.74),
    ])])]);
    $area = ServiceArea::create(['company_uuid' => $company, 'name' => 'Recovery GCC', 'status' => 'active', 'border' => $polygon(60.59)]);
    $outside = ServiceArea::create(['company_uuid' => $company, 'name' => 'Other GCC', 'status' => 'active', 'border' => $polygon(60)]);
    $road = function (string $name, array $coords, array $keys) use ($company, $area) {
        $segment = RoadSegment::create(['company_uuid' => $company, 'name' => $name, 'status' => 'open', 'geometry' => ['type' => 'LineString', 'coordinates' => $coords]]);
        $segment->forceFill(['source' => 'osm', 'source_key' => 'test-osm:' . $segment->uuid,
            'source_meta' => ['way_id' => 1181140403, 'service_area_uuid' => $area->uuid, 'tags' => ['oneway' => 'yes']],
            'routing' => ['direction' => 1, 'layer' => '0', 'node_keys' => $keys]])->save();
        return $segment;
    };
    $main = $road('Original imported road', [[-135.73,60.6],[-135.727,60.6],[-135.725,60.6],[-135.723,60.6],[-135.72,60.6]], ['osm:1','osm:2','osm:added','osm:3','osm:4']);
    $branchA = $road('Branch A', [[-135.727,60.6],[-135.727,60.603]], ['osm:2','osm:a']);
    $branchB = $road('Branch B', [[-135.723,60.6],[-135.723,60.603]], ['osm:3','osm:b']);
    $traffic = app(RoadTrafficService::class); $admin = app(RoadTrafficController::class);
    $recovery = app(RoadRecoveryController::class); $sections = app(RoadSectionController::class); $roads = app(RoadSegmentController::class);
    $snapshot = $traffic->snapshot($company);
    $parts = collect($snapshot['road_segments'])->where('road_segment_uuid', $main->uuid)->values();
    recoveryCheck($parts->count() === 3, 'imported road is divided at its two shared junctions');
    $middle = $parts->first(fn ($s) => abs(min(array_column($s['geometry']['coordinates'], 0)) - -135.727) < 1e-8 && abs(max(array_column($s['geometry']['coordinates'], 0)) - -135.723) < 1e-8);
    $removed = $parts->first(fn ($s) => abs(min(array_column($s['geometry']['coordinates'], 0)) - -135.73) < 1e-8);
    recoveryCheck($middle !== null && $removed !== null, 'fixture resolves the middle section and its adjacent removed section');
    $admin->saveControl($request(['service_area_uuid' => $area->uuid, 'target_key' => $middle['target_key'], 'status' => 'closed', 'congestion_mode' => 'manual',
        'manual_congestion' => 'heavy', 'light_threshold' => 4, 'moderate_threshold' => 8, 'heavy_threshold' => 12, 'note' => 'Preserve closure']), $traffic);
    $sections->remove($request(['service_area_uuid' => $area->uuid, 'target_key' => $removed['target_key'], 'expected_geometry' => $removed['geometry']]), $traffic);
    $original = $main->fresh()->toArray();
    $roads->deleteSegment($request([]), $main->uuid);
    $networkRequest = Request::create('/', 'GET', ['service_area_uuid' => $area->uuid]);
    $deleted = $admin->traffic($networkRequest, $traffic)->getData(true);
    recoveryCheck(count($deleted['deleted_roads']) === 1 && $deleted['deleted_roads'][0]['uuid'] === $main->uuid, 'administrator sees the original whole-road recovery record');
    recoveryCheck(count($deleted['removed_sections']) === 0 && !in_array($main->uuid, array_column($deleted['road_segments'], 'road_segment_uuid')), 'whole-deleted parent is absent from active sections and partial recovery until restored');
    $importer = app(OsmRoadImportService::class);
    $candidate = ['key' => $original['source_key'], 'name' => 'New OSM name', 'geometry' => $original['geometry'], 'length_meters' => 500,
        'restricted' => false, 'routing' => $original['routing'], 'availability' => 'new'];
    $marked = $importer->markExisting([$candidate], [$branchA->toArray(), RoadSegment::withTrashed()->findOrFail($main->uuid)->toArray()])[0];
    recoveryCheck($marked['availability'] === 'deleted' && $marked['existing_road_uuid'] === $main->uuid && $marked['existing_road_name'] === $original['name'], 'deleted source is recoverable even when an overlapping road appears first');
    $body = ['service_area_uuid' => $area->uuid, 'expected_geometry' => $original['geometry']];
    recoveryReject(fn () => $recovery->restore($request(array_replace($body, ['service_area_uuid' => $outside->uuid])), $main->uuid, $traffic), 422, 'recovery rejects the wrong GCC');
    $stale = $body; $stale['expected_geometry']['coordinates'][0][0] += .001;
    recoveryReject(fn () => $recovery->restore($request($stale), $main->uuid, $traffic), 409, 'recovery rejects a stale geometry confirmation');
    session(['company' => $other]);
    recoveryReject(fn () => $recovery->restore($request($body), $main->uuid, $traffic), 404, 'another company cannot restore the road');
    session(['company' => $company]);
    $overlap = $road('New duplicate road', $original['geometry']['coordinates'], ['osm:x1','osm:x2','osm:x3','osm:x4','osm:x5']);
    recoveryReject(fn () => $recovery->restore($request($body), $main->uuid, $traffic), 422, 'recovery cannot add an overlapping parallel route that bypasses existing controls');
    $roads->deleteSegment($request([]), $overlap->uuid);
    $road('New crossing', [[-135.725,60.6],[-135.725,60.603]], ['osm:added','osm:added-end']);
    $response = $recovery->restore($request($body), $main->uuid, $traffic)->getData(true);
    recoveryCheck($response['road_segment']['uuid'] === $main->uuid && !$main->fresh()->trashed(), 'explicit recovery restores the same UUID');
    $restored = $main->fresh();
    recoveryCheck($restored->name === $original['name'] && $restored->status === $original['status'] && $restored->source_key === $original['source_key']
        && $restored->source_meta === $original['source_meta'] && $restored->routing === $original['routing'] && $restored->geometry === $original['geometry'], 'recovery preserves names, metadata, road status, geometry and one-way routing');
    $after = $traffic->snapshot($company);
    $mainParts = collect($after['road_segments'])->where('road_segment_uuid', $main->uuid);
    $closed = $mainParts->filter(fn ($s) => min(array_column($s['geometry']['coordinates'], 0)) >= -135.727 - 1e-8 && max(array_column($s['geometry']['coordinates'], 0)) <= -135.723 + 1e-8);
    recoveryCheck($closed->count() === 2 && $closed->every(fn ($s) => $s['status'] === 'closed' && $s['manual_congestion'] === 'heavy' && $s['note'] === 'Preserve closure'), 'new child sections inherit the original closure, congestion and note');
    recoveryCheck($mainParts->where('is_removed', true)->count() === 1, 'whole-road recovery does not reactivate a separately deleted section');
    $active = $admin->traffic($networkRequest, $traffic)->getData(true);
    recoveryCheck(!in_array($main->uuid, array_column($active['deleted_roads'], 'uuid')) && count($active['removed_sections']) === 1, 'partial recovery reappears separately after its parent is restored');
    $count = RoadTrafficControl::where('company_uuid', $company)->count();
    $recovery->restore($request($body), $main->uuid, $traffic);
    recoveryCheck(RoadSegment::where('company_uuid', $company)->where('source_key', $original['source_key'])->count() === 1
        && RoadTrafficControl::where('company_uuid', $company)->count() === $count, 'repeated recovery creates neither duplicate roads nor duplicate controls');
    $roads->updateSegment($request(['name' => '北门连接道']), $main->uuid);
    $renamed = $traffic->snapshot($company);
    recoveryCheck($renamed['revision'] !== $after['revision'] && collect($renamed['road_segments'])->where('road_segment_uuid', $main->uuid)->every(fn ($s) => $s['road_name'] === '北门连接道'), 'rename updates every section and changes the driver network revision');
    recoveryCheck(collect($renamed['junctions'])->contains(fn ($j) => str_contains($j['name'], '北门连接道')), 'junction labels also use the new name');
    recoveryCheck($main->fresh()->source_meta === $original['source_meta'] && RoadTrafficControl::where('company_uuid', $company)->where('is_removed', true)->count() === 1, 'renaming retains import metadata and partial deletions');
    recoveryReject(fn () => $roads->updateSegment($request(['name' => '']), $main->uuid), 422, 'blank road names are rejected');
    $roads->deleteSegment($request([]), $branchB->uuid);
    $changed = collect($traffic->snapshot($company)['road_segments'])->where('road_segment_uuid', $main->uuid);
    recoveryCheck($changed->filter(fn ($s) => $s['status'] === 'closed' && !$s['is_removed'])->count() === 2, 'removing a crossing retains existing closed section boundaries');
    $registration = EntryRegistration::create(['company_uuid' => $company, 'status' => 'active', 'tracking_enabled' => true]);
    $driverRequest = Request::create('/'); $driverRequest->attributes->set('entryRegistration', $registration);
    $driverNetwork = app(TrackingController::class)->roadNetwork($driverRequest, $traffic)->getData(true);
    recoveryCheck(!isset($driverNetwork['deleted_roads']) && !isset($driverNetwork['removed_sections']), 'driver responses do not expose administrator recovery data');
    recoveryCheck(Event::dispatched(RoadNetworkChanged::class)->isEmpty(), 'network changes wait for commit and do not publish rolled-back fixtures');
    echo "Road recovery integration passed; rolling back fixtures.\n";
} catch (Throwable $e) {
    $failure = $e;
} finally {
    DB::rollBack();
}
if (isset($failure)) { fwrite(STDERR, $failure->getMessage() . ' at ' . $failure->getFile() . ':' . $failure->getLine() . "\n"); exit(1); }
