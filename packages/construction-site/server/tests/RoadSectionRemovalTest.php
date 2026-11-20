<?php

use Fleetbase\ConstructionSite\Services\RoadTrafficService;
use Fleetbase\ConstructionSite\Services\SiteRoadGraphService;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/Services/SiteRoadGraphService.php';
require_once __DIR__ . '/../src/Services/RoadTrafficService.php';

final class RoadSectionRemovalTest extends TestCase
{
    private function road(string $uuid, array $coords, ?array $keys = null): array
    {
        return ['uuid' => $uuid, 'name' => $uuid, 'status' => 'open', 'geometry' => ['type' => 'LineString', 'coordinates' => $coords],
            'routing' => $keys ? ['node_keys' => $keys, 'direction' => 0] : null, 'source' => $keys ? 'osm' : 'manual'];
    }

    private function roads(): array
    {
        return [$this->road('main', [[-135.73, 60.6], [-135.72, 60.6]]),
            $this->road('cross-a', [[-135.727, 60.6], [-135.727, 60.603]]),
            $this->road('cross-b', [[-135.723, 60.6], [-135.723, 60.603]])];
    }

    private function removal(array $section): array
    {
        return ['target_key' => $section['target_key'], 'road_segment_uuid' => $section['road_segment_uuid'],
            'geometry' => $section['geometry'], 'is_removed' => true, 'status' => 'partial', 'congestion_mode' => 'manual', 'manual_congestion' => 'heavy'];
    }

    private function middle(RoadTrafficService $service, array $roads): array
    {
        foreach ($service->assess($roads, [])['road_segments'] as $section) {
            if ($section['road_segment_uuid'] === 'main' && $section['start_junction_key'] && $section['end_junction_key']) return $section;
        }
        throw new RuntimeException('Fixture has no middle section');
    }

    public function testRemovingMiddleSectionPreservesNeighborsAndDisconnectsRouting(): void
    {
        $graph = new SiteRoadGraphService(); $service = new RoadTrafficService($graph); $roads = $this->roads();
        $middle = $this->middle($service, $roads);
        $result = $service->assess($roads, [['uuid' => 'gps', 'lat' => 60.6, 'lng' => -135.725, 'accuracy' => 10]], [$this->removal($middle)]);
        $main = array_values(array_filter($result['road_segments'], fn ($s) => $s['road_segment_uuid'] === 'main'));
        self::assertCount(3, $main);
        self::assertCount(1, array_filter($main, fn ($s) => $s['is_removed']));
        self::assertCount(2, array_filter($main, fn ($s) => !$s['is_removed'] && $s['status'] === 'open'));
        self::assertSame(0, array_sum(array_column($main, 'driver_count')));
        self::assertNull($graph->routeFromSegments($result['road_segments'], 60.6, -135.729, 60.6, -135.721, [], $result['junctions']));
        self::assertNotNull($graph->routeFromSegments($result['road_segments'], 60.6, -135.7295, 60.6, -135.728, [], $result['junctions']));
    }

    public function testDeletedGeometryStaysRemovedWhenItsCrossingRoadsAreDeleted(): void
    {
        $graph = new SiteRoadGraphService(); $service = new RoadTrafficService($graph); $roads = $this->roads();
        $removed = $this->removal($this->middle($service, $roads));
        $result = $service->assess([$roads[0]], [], [$removed]);
        self::assertCount(3, $result['road_segments']);
        self::assertCount(1, array_filter($result['road_segments'], fn ($s) => $s['is_removed']));
        self::assertSame([], $result['junctions']);
        self::assertNull($graph->routeFromSegments($result['road_segments'], 60.6, -135.729, 60.6, -135.721));
        $removed['is_removed'] = false;
        $removed['removal_origin_key'] = $removed['target_key'];
        $restored = $service->assess([$roads[0]], [], [$removed]);
        self::assertCount(3, $restored['road_segments']);
        $middle = array_values(array_filter($restored['road_segments'], fn ($s) => $s['status'] === 'partial'));
        self::assertCount(1, $middle);
        self::assertSame('heavy', $middle[0]['congestion']);
        self::assertNotNull($graph->routeFromSegments($restored['road_segments'], 60.6, -135.729, 60.6, -135.721));
    }

    public function testShortGapCannotBeReconnectedByLegacyEndpointSnapping(): void
    {
        $graph = new SiteRoadGraphService(); $service = new RoadTrafficService($graph);
        $roads = [$this->road('main', [[0, 0], [.001, 0], [.00115, 0], [.002, 0]], ['a', 'b', 'c', 'd']),
            $this->road('side-b', [[.001, 0], [.001, .002]], ['b', 'e']),
            $this->road('side-c', [[.00115, 0], [.00115, .002]], ['c', 'f'])];
        $middle = $this->middle($service, $roads);
        self::assertLessThan(25, $middle['length_meters']);
        $result = $service->assess($roads, [], [$this->removal($middle)]);
        self::assertNull($graph->routeFromSegments($result['road_segments'], 0, .0003, 0, .0018));
        self::assertNull($graph->routeFromSegments($result['road_segments'], 0, .001075, 0, .0018));
    }

    public function testNewJunctionDoesNotReviveChildrenOfDeletedSection(): void
    {
        $service = new RoadTrafficService(new SiteRoadGraphService()); $roads = $this->roads();
        $removed = $this->removal($this->middle($service, $roads));
        $roads[] = $this->road('new-cross', [[-135.725, 60.599], [-135.725, 60.602]]);
        $result = $service->assess($roads, [], [$removed]);
        self::assertCount(2, array_filter($result['road_segments'], fn ($s) => $s['is_removed']));
        self::assertCount(2, array_filter($result['road_segments'], fn ($s) => $s['road_segment_uuid'] === 'main' && !$s['is_removed']));
    }

    public function testRemovingOnlyBranchRemovesEmptyJunctionAndKeepsMainRoadUsable(): void
    {
        $graph = new SiteRoadGraphService(); $service = new RoadTrafficService($graph);
        $roads = array_slice($this->roads(), 0, 2);
        $sections = $service->assess($roads, [])['road_segments'];
        $branch = array_values(array_filter($sections, fn ($s) => $s['road_segment_uuid'] === 'cross-a'))[0];
        $result = $service->assess($roads, [], [$this->removal($branch)]);
        self::assertSame([], $result['junctions']);
        self::assertNotNull($graph->routeFromSegments($result['road_segments'], 60.6, -135.729, 60.6, -135.721));
    }
}
