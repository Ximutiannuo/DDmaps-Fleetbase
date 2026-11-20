<?php

// Real Laravel/MySQL fixtures; the outer transaction always rolls back.
require __DIR__ . '/../../../../api/vendor/autoload.php';
$app = require __DIR__ . '/../../../../api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Fleetbase\ConstructionSite\Events\RoadNetworkChanged;
use Fleetbase\ConstructionSite\Http\Controllers\RoadImportController;
use Fleetbase\ConstructionSite\Http\Controllers\RoadSectionController;
use Fleetbase\ConstructionSite\Http\Controllers\RoadSegmentController;
use Fleetbase\ConstructionSite\Http\Controllers\RoadTrafficController;
use Fleetbase\ConstructionSite\Http\Controllers\TrackingController;
use Fleetbase\ConstructionSite\Models\EntryRegistration;
use Fleetbase\ConstructionSite\Models\RoadSegment;
use Fleetbase\ConstructionSite\Models\RoadTrafficControl;
use Fleetbase\ConstructionSite\Services\RoadTrafficService;
use Fleetbase\ConstructionSite\Services\SiteRoadGraphService;
use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\LaravelMysqlSpatial\Types\LineString;
use Fleetbase\LaravelMysqlSpatial\Types\MultiPolygon;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\LaravelMysqlSpatial\Types\Polygon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

function sectionCheck(bool $condition, string $description): void
{
    if (!$condition) throw new RuntimeException($description);
    echo "PASS $description\n";
}
function rejectsSection(callable $action, int $status, string $description): void
{
    try { $action(); throw new RuntimeException('Unexpected accepted action: ' . $description); }
    catch (HttpException $e) { sectionCheck($e->getStatusCode() === $status, $description); }
}
$company = (string) Str::uuid(); $other = (string) Str::uuid();
session(['company' => $company]);
Event::fake([RoadNetworkChanged::class, Fleetbase\ConstructionSite\Events\SiteNotificationCreated::class]);
DB::beginTransaction();
try {
    foreach ([$company, $other] as $uuid) DB::table('companies')->insert(['uuid' => $uuid, 'name' => 'Section deletion fixture']);
    $polygon = fn ($lat) => new MultiPolygon([new Polygon([new LineString([
        new Point($lat, -135.74), new Point($lat, -135.71), new Point($lat + .02, -135.71), new Point($lat + .02, -135.74), new Point($lat, -135.74),
    ])])]);
    $area = ServiceArea::create(['company_uuid' => $company, 'name' => 'Deletion GCC', 'status' => 'active', 'border' => $polygon(60.59)]);
    $outside = ServiceArea::create(['company_uuid' => $company, 'name' => 'Other GCC', 'status' => 'active', 'border' => $polygon(60)]);
    $roads = [];
    foreach (['main' => [[-135.73, 60.6], [-135.72, 60.6]], 'a' => [[-135.727, 60.6], [-135.727, 60.603]], 'b' => [[-135.723, 60.6], [-135.723, 60.603]]] as $name => $coords) {
        $roads[$name] = RoadSegment::create(['company_uuid' => $company, 'name' => $name, 'status' => 'open', 'geometry' => ['type' => 'LineString', 'coordinates' => $coords]]);
    }
    $traffic = app(RoadTrafficService::class); $graph = app(SiteRoadGraphService::class);
    $sections = app(RoadSectionController::class); $roadController = app(RoadSegmentController::class); $admin = app(RoadTrafficController::class);
    $request = fn ($body) => Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body));
    $networkRequest = Request::create('/', 'GET', ['service_area_uuid' => $area->uuid]);
    $initial = $traffic->snapshot($company);
    $middle = collect($initial['road_segments'])->first(fn ($s) => $s['road_segment_uuid'] === $roads['main']->uuid && $s['start_junction_key'] && $s['end_junction_key']);
    $settings = ['service_area_uuid' => $area->uuid, 'target_key' => $middle['target_key'], 'status' => 'partial', 'congestion_mode' => 'manual',
        'manual_congestion' => 'heavy', 'light_threshold' => 4, 'moderate_threshold' => 8, 'heavy_threshold' => 12, 'note' => 'Keep this note'];
    $admin->saveControl($request($settings), $traffic);
    $payload = ['service_area_uuid' => $area->uuid, 'target_key' => $middle['target_key'], 'expected_geometry' => $middle['geometry']];
    $stale = $payload; $stale['expected_geometry']['coordinates'][0][0] += .001;
    rejectsSection(fn () => $sections->remove($request($stale), $traffic), 409, 'stale geometry cannot remove a different road extent');
    $wrongArea = $payload; $wrongArea['service_area_uuid'] = $outside->uuid;
    rejectsSection(fn () => $sections->remove($request($wrongArea), $traffic), 422, 'deletion rejects a section outside the selected GCC');
    $sections->remove($request($payload), $traffic);
    $deleted = $traffic->snapshot($company);
    $main = collect($deleted['road_segments'])->where('road_segment_uuid', $roads['main']->uuid);
    sectionCheck($main->where('is_removed', true)->count() === 1 && $main->where('is_removed', false)->count() === 2 && !$roads['main']->fresh()->trashed(), 'deleting the middle section retains its parent and both neighboring sections');
    sectionCheck($deleted['revision'] !== $initial['revision'], 'deletion changes the road-network revision');
    $control = RoadTrafficControl::where('company_uuid', $company)->where('target_key', $middle['target_key'])->firstOrFail();
    sectionCheck($control->status === 'partial' && $control->manual_congestion === 'heavy' && $control->note === 'Keep this note' && $control->removed_at !== null, 'deletion stores a tombstone without discarding existing traffic settings');
    $adminNetwork = $admin->traffic($networkRequest, $traffic)->getData(true);
    sectionCheck(!in_array($middle['target_key'], array_column($adminNetwork['road_segments'], 'target_key')) && count($adminNetwork['removed_sections']) === 1, 'admin network hides deleted geometry and exposes persistent recovery');
    $registration = EntryRegistration::create(['company_uuid' => $company, 'status' => 'active', 'tracking_enabled' => true]);
    $driverRequest = Request::create('/'); $driverRequest->attributes->set('entryRegistration', $registration);
    $driver = app(TrackingController::class);
    $driverNetwork = $driver->roadNetwork($driverRequest, $traffic)->getData(true);
    sectionCheck(!in_array($middle['target_key'], array_column($driverNetwork['road_segments'], 'target_key')) && !isset($driverNetwork['removed_sections']), 'driver network never displays deleted sections or administrator recovery records');
    $routeRequest = Request::create('/', 'POST', ['start_latitude' => 60.6, 'start_longitude' => -135.729, 'end_latitude' => 60.6, 'end_longitude' => -135.721]);
    $routeRequest->attributes->set('entryRegistration', $registration);
    sectionCheck($driver->route($routeRequest, $graph, $traffic)->getStatusCode() === 422 && $registration->fresh()->active_route === null, 'driver route cannot cross the deleted section and clears its invalid route');
    $sections->remove($request($payload), $traffic);
    sectionCheck(RoadTrafficControl::where('company_uuid', $company)->where('is_removed', true)->count() === 1, 'repeated deletion is idempotent');
    rejectsSection(fn () => $admin->saveControl($request($settings), $traffic), 404, 'a stale traffic save cannot reactivate a deleted section');
    $newGeometry = ['type' => 'LineString', 'coordinates' => [[-135.73, 60.6], [-135.72, 60.601]]];
    rejectsSection(fn () => $roadController->updateSegment($request(['geometry' => $newGeometry]), $roads['main']->uuid), 422, 'whole-road geometry edits require restoring deleted pieces first');
    $roadController->updateSegment($request(['name' => 'Renamed main']), $roads['main']->uuid);
    sectionCheck($roads['main']->fresh()->name === 'Renamed main', 'parent road can still be renamed with a deleted section');

    $before = $graph->topology(RoadSegment::where('company_uuid', $company)->get()->toArray());
    $newRoad = (new RoadSegment())->forceFill(['company_uuid' => $company, 'name' => 'OSM crossing fixture', 'status' => 'open',
        'geometry' => ['type' => 'LineString', 'coordinates' => [[-135.725, 60.599], [-135.725, 60.602]]], 'source' => 'osm',
        'routing' => ['node_keys' => ['fixture:bottom', 'fixture:top'], 'direction' => 0]]);
    $newRoad->save();
    $after = $graph->topology(RoadSegment::where('company_uuid', $company)->get()->toArray());
    $inherit = new ReflectionMethod(RoadImportController::class, 'inheritControls');
    $inherit->invoke(app(RoadImportController::class), $company, $before, $after, $graph);
    $split = collect($traffic->snapshot($company)['road_segments'])->where('road_segment_uuid', $roads['main']->uuid);
    sectionCheck($split->where('is_removed', true)->count() === 2 && $split->where('is_removed', false)->count() === 2, 'new imported crossing subdivides the tombstone without reviving either child');
    sectionCheck(RoadTrafficControl::where('company_uuid', $company)->where('removal_origin_key', $middle['target_key'])->where('is_removed', true)->count() === 3, 'import inheritance preserves the removal origin on both child controls');
    $wrongRestore = $payload; $wrongRestore['service_area_uuid'] = $outside->uuid;
    rejectsSection(fn () => $sections->restore($request($wrongRestore)), 422, 'recovery rejects the wrong GCC');
    session(['company' => $other]);
    try { $sections->restore($request($payload)); throw new RuntimeException('Cross-company recovery accepted'); }
    catch (Illuminate\Database\Eloquent\ModelNotFoundException $e) { sectionCheck(true, 'another company cannot restore a deleted section'); }
    session(['company' => $company]);
    $sections->restore($request($payload)); $sections->restore($request($payload));
    $restored = collect($traffic->snapshot($company)['road_segments'])->where('road_segment_uuid', $roads['main']->uuid);
    sectionCheck($restored->where('is_removed', true)->isEmpty() && $restored->where('status', 'partial')->count() === 2 && $restored->where('congestion', 'heavy')->count() === 2, 'recovery restores all child pieces and preserves their pre-deletion traffic settings');
    sectionCheck(count($admin->traffic($networkRequest, $traffic)->getData(true)['removed_sections']) === 0, 'recovery removes its persistent recovery-list entry');
    sectionCheck($driver->route($routeRequest, $graph, $traffic)->getStatusCode() === 200, 'restored route becomes reachable again');
    $roadController->deleteSegment($request([]), $roads['main']->uuid); $roadController->deleteSegment($request([]), $roads['main']->uuid);
    sectionCheck(RoadSegment::withTrashed()->where('uuid', $roads['main']->uuid)->first()->trashed()
        && collect($traffic->snapshot($company)['road_segments'])->where('road_segment_uuid', $roads['main']->uuid)->isEmpty(), 'whole-road deletion removes all its sections and tolerates retries');
    echo "Road section deletion integration passed; rolling back fixtures.\n";
} catch (Throwable $e) {
    $failure = $e;
} finally {
    DB::rollBack();
}
if (isset($failure)) { fwrite(STDERR, $failure->getMessage() . "\n" . $failure->getTraceAsString() . "\n"); exit(1); }
