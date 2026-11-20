<?php
// Explicitly disposable browser fixture: a saved road plus a new import candidate.
require __DIR__ . '/browser-fixture.php';

use Fleetbase\ConstructionSite\Models\RoadSegment;
use Fleetbase\ConstructionSite\Services\OsmRoadImportService;
use Fleetbase\FleetOps\Models\ServiceArea;
use Fleetbase\LaravelMysqlSpatial\Types\LineString;
use Fleetbase\LaravelMysqlSpatial\Types\MultiPolygon;
use Fleetbase\LaravelMysqlSpatial\Types\Point;
use Fleetbase\LaravelMysqlSpatial\Types\Polygon;
use Illuminate\Support\Facades\Cache;

$area = ServiceArea::firstOrCreate(['company_uuid' => $company->uuid, 'name' => 'GCC 道路回归验收'], [
    'status' => 'active', 'border' => new MultiPolygon([new Polygon([new LineString([
        new Point(60.60, -135.73), new Point(60.60, -135.72), new Point(60.61, -135.72),
        new Point(60.61, -135.73), new Point(60.60, -135.73),
    ])])]),
]);
$ways = [];
foreach ([['id' => 99001, 'lat' => 60.602, 'name' => '已保存的验收道路'],
    ['id' => 99002, 'lat' => 60.606, 'name' => '待导入的验收道路']] as $way) {
    $ways[] = ['type' => 'way', 'id' => $way['id'], 'nodes' => [$way['id'] * 10, $way['id'] * 10 + 1],
        'tags' => ['highway' => 'service', 'name' => $way['name']],
        'geometry' => [['lon' => -135.729, 'lat' => $way['lat']], ['lon' => -135.721, 'lat' => $way['lat']]],
    ];
}
$osm = ['elements' => $ways, 'fetched_at' => now()->toIso8601String()];
$border = json_decode($area->border->toJson(), true);
$key = 'osm-roads:' . sha1(config('construction-site.road_import.endpoint') . ':' . $company->uuid . ':' . sha1(json_encode($border)));
Cache::put($key, $osm, 3600);
$saved = app(OsmRoadImportService::class)->candidates($osm, $border, $area->uuid)[0];
if (!RoadSegment::where('company_uuid', $company->uuid)->where('source_key', $saved['key'])->exists()) {
    (new RoadSegment())->forceFill(['company_uuid' => $company->uuid, 'name' => $saved['name'],
        'geometry' => $saved['geometry'], 'status' => 'open', 'source' => 'osm',
        'source_key' => $saved['key'], 'source_meta' => $saved['source_meta'], 'routing' => $saved['routing']])->save();
}
echo "Disposable GCC ready: one saved road and one new OSM import candidate; no external OSM request needed.\n";
