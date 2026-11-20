<?php

// Boot the real Laravel API and MySQL schema; all business fixtures are rolled back.
require __DIR__ . '/../../../../api/vendor/autoload.php';
$app = require __DIR__ . '/../../../../api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Fleetbase\ConstructionSite\Events\RoadNetworkChanged;
use Fleetbase\ConstructionSite\Http\Controllers\RoadImportController;
use Fleetbase\ConstructionSite\Http\Controllers\RoadSegmentController;
use Fleetbase\ConstructionSite\Models\RoadSegment;
use Fleetbase\ConstructionSite\Models\RoadTrafficControl;
use Fleetbase\ConstructionSite\Services\OsmRoadImportService;
use Fleetbase\ConstructionSite\Services\RoadTrafficService;
use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\LaravelMysqlSpatial\Types\LineString;
use Fleetbase\LaravelMysqlSpatial\Types\MultiPolygon;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\LaravelMysqlSpatial\Types\Polygon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function importCheck(bool $condition, string $description): void
{
    if (!$condition) throw new RuntimeException($description);
    echo "PASS $description\n";
}
$company = (string) Str::uuid();
$otherCompany = (string) Str::uuid();
$tokens = []; $cacheKeys = [];
session(['company' => $company]);
Event::fake([RoadNetworkChanged::class, Fleetbase\ConstructionSite\Events\SiteNotificationCreated::class]);
Http::preventStrayRequests();
DB::beginTransaction();
try {
    foreach ([$company, $otherCompany] as $uuid) DB::table('companies')->insert(['uuid' => $uuid, 'name' => 'Road import integration fixture']);
    $area = ServiceArea::create(['company_uuid' => $company, 'name' => 'Road import fixture', 'status' => 'active',
        'border' => new MultiPolygon([new Polygon([new LineString([
            new Point(60.60, -135.73), new Point(60.60, -135.72), new Point(60.61, -135.72), new Point(60.61, -135.73), new Point(60.60, -135.73),
        ])])])]);
    $makeRequest = fn ($body) => Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($body));
    $way = fn ($id, $coords, $tags = []) => ['type' => 'way', 'id' => $id, 'nodes' => [$id * 10, $id * 10 + 1],
        'tags' => array_merge(['highway' => 'service', 'name' => 'Fixture ' . $id], $tags),
        'geometry' => array_map(fn ($p) => ['lon' => $p[0], 'lat' => $p[1]], $coords)];
    Http::fake(['*' => Http::response(['elements' => [
        $way(1, [[-135.729, 60.601], [-135.721, 60.601]], ['oneway' => 'yes']),
        $way(2, [[-135.729, 60.604], [-135.721, 60.604]], ['access' => 'private']),
        $way(3, [[-135.729, 60.607], [-135.721, 60.607]]),
        $way(4, [[-135.725, 60.606], [-135.725, 60.608]]),
    ]], 200)]);
    $manual = RoadSegment::create(['company_uuid' => $company, 'name' => 'Closed manual section', 'status' => 'open',
        'geometry' => ['type' => 'LineString', 'coordinates' => [[-135.729, 60.607], [-135.721, 60.607]]]]);
    $manualSection = app(RoadTrafficService::class)->snapshot($company)['road_segments'][0];
    RoadTrafficControl::create(['company_uuid' => $company, 'service_area_uuid' => $area->uuid, 'target_key' => $manualSection['target_key'],
        'road_segment_uuid' => $manual->uuid, 'geometry' => $manualSection['geometry'], 'status' => 'construction',
        'congestion_mode' => 'manual', 'manual_congestion' => 'heavy']);
    $controller = app(RoadImportController::class); $importer = app(OsmRoadImportService::class);
    $preview = $controller->preview($makeRequest(['service_area_uuid' => $area->uuid]), $importer)->getData(true);
    $tokens[] = $preview['preview_token'];
    $borderHash = sha1($area->border->toJson());
    $borderArray = json_decode($area->border->toJson(), true);
    $cacheKeys[] = 'osm-roads:' . sha1(config('construction-site.road_import.endpoint') . ':' . $company . ':' . sha1(json_encode($borderArray)));
    importCheck(count($preview['candidates']) === 4 && !$preview['cached'], 'synthetic service-area polygon produces an OSM preview without creating road records');
    importCheck(RoadSegment::where('company_uuid', $company)->count() === 1, 'preview is read-only for business data');
    importCheck($preview['candidates'][2]['availability'] === 'overlap', 'preview detects an existing closed manual road');
    $cached = $controller->preview($makeRequest(['service_area_uuid' => $area->uuid]), $importer)->getData(true); $tokens[] = $cached['preview_token'];
    importCheck($cached['cached'] && count(Http::recorded()) === 1, 'repeated preview uses cached Overpass data');
    $payload = ['service_area_uuid' => $area->uuid, 'preview_token' => $preview['preview_token'], 'candidate_keys' => array_column($preview['candidates'], 'key')];
    $result = $controller->commit($makeRequest($payload), $importer)->getData(true);
    importCheck($result['created_count'] === 3 && $result['skipped_count'] === 1, 'commit persists selected new ways and skips the closed manual overlap');
    $imported = RoadSegment::where('company_uuid', $company)->where('source', 'osm')->get();
    $oneway = $imported->firstWhere('name', 'Fixture 1');
    $private = $imported->firstWhere('name', 'Fixture 2');
    importCheck($oneway->routing['direction'] === 1 && count($oneway->routing['node_keys']) === 2, 'database preserves one-way direction and OSM node IDs');
    importCheck($private->status === 'closed', 'restricted private roads are imported closed');
    $manualAfter = collect(app(RoadTrafficService::class)->snapshot($company)['road_segments'])->where('road_segment_uuid', $manual->uuid);
    importCheck($manualAfter->count() === 2 && $manualAfter->every(fn ($s) => $s['status'] === 'construction' && $s['congestion'] === 'heavy'), 'new junction splits an existing manual section while both children inherit closure and congestion');
    $repeat = $controller->commit($makeRequest($payload), $importer)->getData(true);
    importCheck($repeat['created_count'] === 0 && RoadSegment::where('company_uuid', $company)->count() === 4, 'retrying the same commit cannot create duplicates');
    $traffic = app(RoadTrafficService::class);
    $section = collect($traffic->snapshot($company)['road_segments'])->firstWhere('road_segment_uuid', $oneway->uuid);
    importCheck($section['source'] === 'osm' && $section['routing']['direction'] !== 0, 'driver traffic snapshot preserves imported routing metadata');
    RoadTrafficControl::create(['company_uuid' => $company, 'service_area_uuid' => $area->uuid, 'target_key' => $section['target_key'], 'road_segment_uuid' => $oneway->uuid,
        'status' => 'construction', 'geometry' => $section['geometry']]);
    $update = ['name' => 'Administrator name', 'geometry' => ['type' => 'LineString', 'coordinates' => [[-135.729, 60.601], [-135.725, 60.6012], [-135.721, 60.601]]],
        'source' => 'manual', 'source_key' => 'tampered', 'routing' => ['direction' => 0]];
    app(RoadSegmentController::class)->updateSegment($makeRequest($update), $oneway->uuid);
    $edited = $oneway->fresh();
    importCheck($edited->source === 'osm' && $edited->routing['direction'] === 1 && count($edited->routing['node_keys']) === 3, 'geometry editing keeps provenance and direction while aligning node identities');
    $newSection = collect($traffic->snapshot($company)['road_segments'])->firstWhere('road_segment_uuid', $edited->uuid);
    importCheck($newSection['target_key'] === $section['target_key'] && $newSection['status'] === 'construction', 'editing an ordinary bend preserves section control identity and closure');
    $controller->commit($makeRequest($payload), $importer);
    importCheck($edited->fresh()->name === 'Administrator name', 'reimport never overwrites administrator edits');
    session(['company' => $otherCompany]);
    try { $controller->commit($makeRequest($payload), $importer); throw new RuntimeException('Cross-company token accepted'); }
    catch (ValidationException $e) { importCheck(true, 'preview tokens cannot be used by another company'); }
    session(['company' => $company]);
    $area->border = new MultiPolygon([new Polygon([new LineString([
        new Point(60.60, -135.73), new Point(60.60, -135.719), new Point(60.61, -135.719), new Point(60.61, -135.73), new Point(60.60, -135.73),
    ])])]); $area->saveQuietly();
    try { $controller->commit($makeRequest($payload), $importer); throw new RuntimeException('Changed boundary accepted'); }
    catch (ValidationException $e) { importCheck(true, 'changed GCC boundary invalidates the old preview'); }
    Cache::forget('road-preview:' . $preview['preview_token']);
    try { $controller->commit($makeRequest($payload), $importer); throw new RuntimeException('Expired token accepted'); }
    catch (ValidationException $e) { importCheck(true, 'expired preview cannot create roads'); }
    echo "Road import integration passed; rolling back fixtures.\n";
} catch (Throwable $e) {
    $failure = $e;
} finally {
    DB::rollBack();
    foreach ($tokens as $token) Cache::forget('road-preview:' . $token);
    foreach ($cacheKeys as $key) Cache::forget($key);
}
if (isset($failure)) {
    fwrite(STDERR, $failure->getMessage() . "\n");
    exit(1);
}
