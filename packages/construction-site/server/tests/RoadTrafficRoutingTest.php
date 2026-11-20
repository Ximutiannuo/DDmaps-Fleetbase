<?php

use Fleetbase\ConstructionSite\Services\RoadTrafficService;
use Fleetbase\ConstructionSite\Services\SiteRoadGraphService;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/Services/SiteRoadGraphService.php';
require_once __DIR__ . '/../src/Services/RoadTrafficService.php';

final class RoadTrafficRoutingTest extends TestCase
{
    private function road(string $uuid, array $coordinates, float $multiplier = 1): array
    {
        return ['uuid' => $uuid, 'name' => $uuid, 'status' => 'open', 'traffic_multiplier' => $multiplier,
            'geometry' => ['type' => 'LineString', 'coordinates' => $coordinates]];
    }

    private function drivers(int $count, ?float $speed = null): array
    {
        return array_map(fn ($id) => ['uuid' => "driver-$id", 'lat' => 60.6, 'lng' => -135.725, 'speed' => $speed, 'accuracy' => 10], range(1, $count));
    }

    public function testCountsAndLowSpeedSupplementProduceDifferentTrafficLevels(): void
    {
        $service = new RoadTrafficService(new SiteRoadGraphService());
        self::assertSame('unknown', $service->classify([])['congestion']);
        self::assertSame('light', $service->classify($this->drivers(3))['congestion']);
        self::assertSame('moderate', $service->classify($this->drivers(6))['congestion']);
        self::assertSame('heavy', $service->classify($this->drivers(10))['congestion']);
        self::assertSame('standstill', $service->classify($this->drivers(3, 0))['congestion']);
        self::assertSame('heavy', $service->classify($this->drivers(3, 1))['congestion']);
        self::assertSame('none', $service->classify($this->drivers(2, 0))['congestion']);
    }

    public function testManualOverrideRetainsAutomaticEstimateAndCanBeReleased(): void
    {
        $service = new RoadTrafficService(new SiteRoadGraphService());
        $control = ['congestion_mode' => 'manual', 'manual_congestion' => 'none'];
        $result = $service->classify($this->drivers(10), $control);
        self::assertSame('none', $result['congestion']);
        self::assertSame('heavy', $result['automatic_congestion']);
        self::assertSame(10, $result['driver_count']);
        $control['congestion_mode'] = 'auto';
        self::assertSame('heavy', $service->classify($this->drivers(10), $control)['congestion']);
    }

    public function testConfigurableThresholdsAndMissingSpeed(): void
    {
        $service = new RoadTrafficService(new SiteRoadGraphService());
        $result = $service->classify($this->drivers(3), ['light_threshold' => 4, 'moderate_threshold' => 8, 'heavy_threshold' => 12]);
        self::assertSame('none', $result['congestion']);
        self::assertNull($result['average_speed_kmh']);
    }

    public function testNearestRoadDeduplicationAndAccuracyFiltering(): void
    {
        $service = new RoadTrafficService(new SiteRoadGraphService());
        $roads = [$this->road('a', [[-135.73, 60.6], [-135.72, 60.6]]), $this->road('b', [[-135.73, 60.60015], [-135.72, 60.60015]])];
        $drivers = $this->drivers(1);
        $drivers[] = $drivers[0];
        $drivers[] = ['uuid' => 'inaccurate', 'lat' => 60.6, 'lng' => -135.725, 'speed' => 0, 'accuracy' => 150];
        $snapshot = $service->assess($roads, $drivers);
        self::assertSame(1, $snapshot['road_segments'][0]['driver_count']);
        self::assertSame(0, $snapshot['road_segments'][1]['driver_count']);
    }

    public function testCrossingAndTJunctionsAreIdentifiedEvenWhenClosed(): void
    {
        $graph = new SiteRoadGraphService();
        $roads = [$this->road('horizontal', [[-135.73, 60.6], [-135.72, 60.6]]), $this->road('vertical', [[-135.725, 60.597], [-135.725, 60.603]])];
        $roads[1]['status'] = 'construction';
        $junctions = $graph->junctions($roads);
        self::assertCount(1, $junctions);
        self::assertEqualsWithDelta(-135.725, $junctions[0]['longitude'], 0.000001);
        $roads[1]['geometry']['coordinates'][0] = [-135.725, 60.6];
        self::assertCount(1, $graph->junctions($roads));
    }

    public function testFastestRouteCanBeLongerThanTheShortestRoad(): void
    {
        $graph = new SiteRoadGraphService();
        $roads = [
            $this->road('direct', [[-135.73, 60.6], [-135.72, 60.6]], 4),
            $this->road('detour', [[-135.73, 60.6], [-135.73, 60.602], [-135.72, 60.602], [-135.72, 60.6]]),
        ];
        $route = $graph->routeFromSegments($roads, 60.6, -135.73, 60.6, -135.72);
        self::assertContains('detour', $route['segment_uuids']);
        self::assertNotContains('direct', $route['segment_uuids']);
        $roads[0]['traffic_multiplier'] = 1;
        $normal = $graph->routeFromSegments($roads, 60.6, -135.73, 60.6, -135.72);
        self::assertContains('direct', $normal['segment_uuids']);
        self::assertGreaterThan($normal['total_distance'], $route['total_distance']);
        self::assertSame('travel_time', $route['optimization']);
    }

    public function testDurationIncludesCongestionAndPartialClosure(): void
    {
        $graph = new SiteRoadGraphService();
        $road = $this->road('direct', [[-135.73, 60.6], [-135.72, 60.6]], 2);
        $road['status'] = 'partial';
        $route = $graph->routeFromSegments([$road], 60.6, -135.73, 60.6, -135.72);
        self::assertEqualsWithDelta($route['total_distance'] / (20 / 3.6) * 3, $route['total_duration'], 0.001);
    }

    private function junctionRoads(): array
    {
        return [
            $this->road('west', [[-135.73, 60.6], [-135.725, 60.6]]),
            $this->road('east', [[-135.725, 60.6], [-135.72, 60.6]]),
            $this->road('detour', [[-135.73, 60.6], [-135.73, 60.601], [-135.72, 60.601], [-135.72, 60.6]]),
        ];
    }

    public function testJunctionWaitingTimeChangesRouteChoice(): void
    {
        $graph = new SiteRoadGraphService();
        $roads = $this->junctionRoads();
        $junction = collectDummyJunction($graph->junctions($roads), -135.725);
        $junction['delay_seconds'] = 120;
        $junction['status'] = 'open';
        $route = $graph->routeFromSegments($roads, 60.6, -135.73, 60.6, -135.72, [], [$junction]);
        self::assertContains('detour', $route['segment_uuids']);
        self::assertNotContains('east', $route['segment_uuids']);
    }

    public function testClosedIntersectionForcesDetourAndNoAlternativeReturnsNull(): void
    {
        $graph = new SiteRoadGraphService();
        $roads = $this->junctionRoads();
        $junction = collectDummyJunction($graph->junctions($roads), -135.725);
        $junction['status'] = 'construction';
        $route = $graph->routeFromSegments($roads, 60.6, -135.73, 60.6, -135.72, [], [$junction]);
        self::assertContains('detour', $route['segment_uuids']);
        self::assertNotContains('west', $route['segment_uuids']);
        self::assertNull($graph->routeFromSegments(array_slice($roads, 0, 2), 60.6, -135.73, 60.6, -135.72, [], [$junction]));
    }

    public function testJunctionDelayIsChargedOnceInReturnedDuration(): void
    {
        $graph = new SiteRoadGraphService();
        $roads = array_slice($this->junctionRoads(), 0, 2);
        $junction = $graph->junctions($roads)[0];
        $junction['status'] = 'open';
        $junction['delay_seconds'] = 30;
        $route = $graph->routeFromSegments($roads, 60.6, -135.73, 60.6, -135.72, [], [$junction]);
        self::assertEqualsWithDelta($route['total_distance'] / (20 / 3.6) + 30, $route['total_duration'], 0.001);
    }

    public function testClosedJunctionKeepsApproachUsableAndRejectsDestinationInsideClosure(): void
    {
        $graph = new SiteRoadGraphService();
        $roads = array_slice($this->junctionRoads(), 0, 2);
        $junction = $graph->junctions($roads)[0];
        $junction['status'] = 'closed';
        $route = $graph->routeFromSegments($roads, 60.6, -135.728, 60.6, -135.73, [], [$junction]);
        self::assertNotNull($route);
        self::assertContains('west', $route['segment_uuids']);
        self::assertNull($graph->routeFromSegments($roads, 60.6, -135.73, 60.6, -135.725, [], [$junction]));
    }

    public function testExistingPolygonAndLineAvoidAreasStillForceDetours(): void
    {
        $graph = new SiteRoadGraphService();
        $roads = $this->junctionRoads();
        foreach ([
            ['type' => 'Polygon', 'coordinates' => [[[-135.7252, 60.5998], [-135.7248, 60.5998], [-135.7248, 60.6002], [-135.7252, 60.6002], [-135.7252, 60.5998]]]],
            ['type' => 'LineString', 'coordinates' => [[-135.725, 60.5998], [-135.725, 60.6002]]],
        ] as $geometry) {
            $route = $graph->routeFromSegments($roads, 60.6, -135.73, 60.6, -135.72, [['geometry' => $geometry]]);
            self::assertNotNull($route);
            self::assertContains('detour', $route['segment_uuids']);
            self::assertNotContains('west', $route['segment_uuids']);
        }
    }

    private function sectionRoads(): array
    {
        return [
            $this->road('main', [[-135.73, 60.6], [-135.726, 60.6], [-135.724, 60.601], [-135.722, 60.6], [-135.718, 60.6]]),
            $this->road('branch-a', [[-135.726, 60.6], [-135.726, 60.598]]),
            $this->road('branch-b', [[-135.722, 60.6], [-135.722, 60.598]]),
        ];
    }

    public function testSectionsStopAtJunctionsAndKeepInteriorBends(): void
    {
        $graph = new SiteRoadGraphService();
        $topology = $graph->topology($this->sectionRoads());
        $main = array_values(array_filter($topology['road_segments'], fn ($s) => $s['road_segment_uuid'] === 'main'));
        self::assertCount(3, $main);
        self::assertCount(2, $topology['junctions']);
        $middle = array_values(array_filter($main, fn ($s) => $s['start_junction_key'] && $s['end_junction_key']))[0];
        self::assertCount(3, $middle['geometry']['coordinates']);
        self::assertContains([-135.724, 60.601], $middle['geometry']['coordinates']);
        self::assertGreaterThan(300, $middle['length_meters']);
        $reordered = $graph->topology(array_reverse($this->sectionRoads()));
        self::assertSame(array_column($topology['road_segments'], 'target_key'), array_column($reordered['road_segments'], 'target_key'));
    }

    public function testSectionClosureAndCountsDoNotSpreadToNeighboringSections(): void
    {
        $graph = new SiteRoadGraphService();
        $service = new RoadTrafficService($graph);
        $roads = $this->sectionRoads();
        $drivers = $this->drivers(3);
        foreach ($drivers as &$driver) $driver['lng'] = -135.729;
        unset($driver);
        $initial = $service->assess($roads, $drivers);
        $main = array_values(array_filter($initial['road_segments'], fn ($s) => $s['road_segment_uuid'] === 'main'));
        $middle = array_values(array_filter($main, fn ($s) => $s['start_junction_key'] && $s['end_junction_key']))[0];
        $snapshot = $service->assess($roads, $drivers, [['target_key' => $middle['target_key'], 'status' => 'construction', 'congestion_mode' => 'manual', 'manual_congestion' => 'heavy']]);
        $main = array_values(array_filter($snapshot['road_segments'], fn ($s) => $s['road_segment_uuid'] === 'main'));
        self::assertSame(3, array_sum(array_column($main, 'driver_count')));
        self::assertCount(1, array_filter($main, fn ($s) => $s['status'] === 'construction'));
        self::assertCount(1, array_filter($main, fn ($s) => $s['congestion'] === 'light' && $s['driver_count'] === 3));
        self::assertCount(1, array_filter($main, fn ($s) => $s['congestion'] === 'heavy' && $s['driver_count'] === 0));
        self::assertNull($graph->routeFromSegments($snapshot['road_segments'], 60.6, -135.73, 60.6, -135.718, [], $snapshot['junctions']));
        self::assertNotNull($graph->routeFromSegments($snapshot['road_segments'], 60.6, -135.73, 60.6, -135.726, [], $snapshot['junctions']));
        self::assertNotNull($graph->routeFromSegments($snapshot['road_segments'], 60.6, -135.722, 60.6, -135.718, [], $snapshot['junctions']));
    }

    public function testLegacyRoadControlsRemainDefaultsWithExplicitSectionOverride(): void
    {
        $service = new RoadTrafficService(new SiteRoadGraphService());
        $roads = $this->sectionRoads();
        $roads[0]['status'] = 'closed';
        $initial = $service->assess($roads, [], [['target_key' => 'road:main', 'congestion_mode' => 'manual', 'manual_congestion' => 'moderate']]);
        $main = array_values(array_filter($initial['road_segments'], fn ($s) => $s['road_segment_uuid'] === 'main'));
        self::assertCount(3, $main);
        self::assertSame(['closed', 'closed', 'closed'], array_column($main, 'status'));
        self::assertSame(['moderate', 'moderate', 'moderate'], array_column($main, 'congestion'));
        $updated = $service->assess($roads, [], [
            ['target_key' => 'road:main', 'congestion_mode' => 'manual', 'manual_congestion' => 'moderate'],
            ['target_key' => $main[0]['target_key'], 'status' => 'open', 'congestion_mode' => 'auto'],
        ]);
        $main = array_values(array_filter($updated['road_segments'], fn ($s) => $s['road_segment_uuid'] === 'main'));
        self::assertSame(['open', 'closed', 'closed'], array_column($main, 'status'));
        self::assertSame(['unknown', 'moderate', 'moderate'], array_column($main, 'congestion'));
    }

    public function testIsolatedLoopIsOneSectionWithoutFakeJunctions(): void
    {
        $graph = new SiteRoadGraphService();
        $topology = $graph->topology([$this->road('loop', [[-135.73, 60.6], [-135.72, 60.6], [-135.72, 60.602], [-135.73, 60.602], [-135.73, 60.6]])]);
        self::assertCount(1, $topology['road_segments']);
        self::assertCount(0, $topology['junctions']);
        self::assertCount(5, $topology['road_segments'][0]['geometry']['coordinates']);
    }

    public function testMissingAndExpiredSamplesStayUnknownUntilFreshObservation(): void
    {
        $service = new RoadTrafficService(new SiteRoadGraphService());
        $empty = $service->classify([]);
        self::assertSame('no_data', $empty['data_status']);
        self::assertSame('unknown', $empty['automatic_congestion']);
        $stale = array_map(fn ($driver) => array_merge($driver,
            ['is_fresh' => false, 'observed_at' => '2026-10-08T06:00:00+00:00']), $this->drivers(10, 0));
        $result = $service->classify($stale);
        self::assertSame('stale', $result['data_status']);
        self::assertSame(0, $result['driver_count']);
        self::assertSame('unknown', $result['congestion']);
        self::assertNull($result['average_speed_kmh']);
        self::assertSame('2026-10-08T06:00:00+00:00', $result['last_observed_at']);
        $manual = $service->classify($stale, ['congestion_mode' => 'manual', 'manual_congestion' => 'heavy']);
        self::assertSame('heavy', $manual['congestion']);
        self::assertSame('unknown', $manual['automatic_congestion']);
        self::assertSame('stale', $manual['data_status']);
        $fresh = $service->classify(array_merge($stale, $this->drivers(1, 5)));
        self::assertSame('observed', $fresh['data_status']);
        self::assertSame(1, $fresh['driver_count']);
        self::assertSame('none', $fresh['congestion']);
    }

    public function testMultiVertexRoadStepIncludesAllEdgesAndLegTime(): void
    {
        $graph = new SiteRoadGraphService();
        $road = $this->road('curved', [[-135.73, 60.6], [-135.727, 60.601], [-135.723, 60.601], [-135.72, 60.6]], 2);
        $route = $graph->routeFromSegments([$road], 60.6, -135.73, 60.6, -135.72);
        self::assertCount(2, $route['steps']);
        self::assertEqualsWithDelta($route['total_distance'], $route['steps'][0]['distance'], 0.001);
        self::assertEqualsWithDelta($route['total_distance'], array_sum(array_column($route['steps'], 'distance')), 0.001);
        self::assertEqualsWithDelta($route['total_duration'], array_sum(array_column($route['legs'], 'duration')), 0.001);
        self::assertEqualsWithDelta($route['total_distance'], $route['steps'][0]['distance_end'], 0.001);
        self::assertEqualsWithDelta($route['total_distance'] / (20 / 3.6) * 2, $route['total_duration'], 0.001);
    }

    public function testTurnStepsFollowTravelDirectionAndJunctionDelayIsCountedOnce(): void
    {
        $graph = new SiteRoadGraphService();
        $roads = [
            $this->road('west', [[-135.73, 60.6], [-135.725, 60.6]]),
            $this->road('north', [[-135.725, 60.6], [-135.725, 60.603]]),
            $this->road('south', [[-135.725, 60.6], [-135.725, 60.597]]),
        ];
        $junction = $graph->junctions($roads)[0];
        $junction['delay_seconds'] = 30;
        $junction['status'] = 'open';
        $left = $graph->routeFromSegments($roads, 60.6, -135.73, 60.603, -135.725, [], [$junction]);
        $right = $graph->routeFromSegments($roads, 60.6, -135.73, 60.597, -135.725, [], [$junction]);
        self::assertSame('turn', $left['steps'][1]['maneuverType']);
        self::assertSame('left', $left['steps'][1]['modifier']);
        self::assertSame('right', $right['steps'][1]['modifier']);
        self::assertEqualsWithDelta(30, array_sum(array_column($left['legs'], 'delay_seconds')), 0.001);
        self::assertEqualsWithDelta($left['total_distance'], array_sum(array_column($left['steps'], 'distance')), 0.001);
    }

    public function testClosedSectionEndpointCannotSnapIntoOpenNeighbor(): void
    {
        $graph = new SiteRoadGraphService();
        $roads = $this->sectionRoads();
        $service = new RoadTrafficService($graph);
        $snapshot = $service->assess($roads, []);
        $middle = array_values(array_filter($snapshot['road_segments'], fn ($s) => $s['road_segment_uuid'] === 'main' && $s['start_junction_key'] && $s['end_junction_key']))[0];
        $snapshot = $service->assess($roads, [], [['target_key' => $middle['target_key'], 'status' => 'closed']]);
        // This destination is inside the closed section, only ~45 m from its junction.
        self::assertNull($graph->routeFromSegments($snapshot['road_segments'], 60.6, -135.73, 60.6003, -135.7254, [], $snapshot['junctions']));
        self::assertNull($graph->routeFromSegments($snapshot['road_segments'], 60.6003, -135.7254, 60.6, -135.73, [], $snapshot['junctions']));
        self::assertNotNull($graph->routeFromSegments($snapshot['road_segments'], 60.6, -135.73, 60.6, -135.726, [], $snapshot['junctions']));
    }
}

function collectDummyJunction(array $junctions, float $longitude): array
{
    foreach ($junctions as $junction) {
        if (abs($junction['longitude'] - $longitude) < 0.000001) return $junction;
    }
    throw new RuntimeException('Expected test junction not found');
}
