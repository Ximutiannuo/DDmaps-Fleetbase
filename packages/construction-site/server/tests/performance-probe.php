<?php
require __DIR__ . '/../../../../api/vendor/autoload.php';
$app = require __DIR__ . '/../../../../api/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Fleetbase\ConstructionSite\Services\RoadTrafficService;
use Fleetbase\ConstructionSite\Services\SiteRoadGraphService;
use Fleetbase\ConstructionSite\Support\TopologyCache;

// Synthetic disconnected roads exercise topology + assignment without changing business data.
// Results describe this fixture and machine, not an asserted production capacity.
$graph = new SiteRoadGraphService();
foreach ([50, 200, 500] as $count) {
    $segments = []; $drivers = [];
    for ($i = 0; $i < $count; $i++) {
        $lat = 59.5 + floor($i / 20) * .002; $lng = -135.8 + ($i % 20) * .002;
        $segments[] = ['uuid' => 'benchmark-' . $count . '-' . $i, 'name' => 'Road ' . $i, 'status' => 'open',
            'geometry' => ['type' => 'LineString', 'coordinates' => [[$lng, $lat], [$lng + .001, $lat]]]];
        $drivers[] = ['uuid' => 'driver-' . $i, 'lat' => $lat, 'lng' => $lng + .0005, 'accuracy' => 10, 'speed' => 4, 'is_fresh' => true];
    }
    foreach (['cold' => new RoadTrafficService($graph), 'cached' => new RoadTrafficService($graph, new TopologyCache())] as $mode => $service) {
        $service->assess($segments, $drivers); $times = [];
        for ($run = 0; $run < 7; $run++) {
            $start = hrtime(true); $result = $service->assess($segments, $drivers); $times[] = (hrtime(true) - $start) / 1e6;
        }
        sort($times);
        echo json_encode(['roads' => $count, 'drivers' => $count, 'mode' => $mode, 'p50_ms' => round($times[3], 2),
            'p95_ms' => round($times[6], 2), 'sections' => count($result['road_segments'])], JSON_UNESCAPED_SLASHES) . "\n";
    }
}
