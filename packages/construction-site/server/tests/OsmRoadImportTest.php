<?php

use Fleetbase\ConstructionSite\Services\OsmRoadImportService;
use Fleetbase\ConstructionSite\Services\SiteRoadGraphService;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/Services/SiteRoadGraphService.php';
require_once __DIR__ . '/../src/Services/OsmRoadImportService.php';

final class OsmRoadImportTest extends TestCase
{
    private function border(bool $hole = false): array
    {
        $rings = [[[-135.73, 60.60], [-135.72, 60.60], [-135.72, 60.61], [-135.73, 60.61], [-135.73, 60.60]]];
        if ($hole) $rings[] = [[-135.726, 60.602], [-135.724, 60.602], [-135.724, 60.608], [-135.726, 60.608], [-135.726, 60.602]];
        return ['type' => 'Polygon', 'coordinates' => $rings];
    }

    private function way(int $id, array $coords, array $nodes, array $tags = []): array
    {
        return ['type' => 'way', 'id' => $id, 'nodes' => $nodes, 'tags' => array_merge(['highway' => 'service'], $tags),
            'geometry' => array_map(fn ($p) => ['lon' => $p[0], 'lat' => $p[1]], $coords)];
    }

    private function candidates(array $ways, ?array $border = null): array
    {
        return (new OsmRoadImportService(new SiteRoadGraphService()))->candidates(['elements' => $ways], $border ?? $this->border(), 'area');
    }

    private function road(string $id, array $coords, array $nodes, int $direction = 0, string $layer = '0'): array
    {
        return ['uuid' => $id, 'name' => $id, 'status' => 'open', 'geometry' => ['type' => 'LineString', 'coordinates' => $coords],
            'routing' => ['node_keys' => array_map(fn ($n) => 'osm-node:' . $n, $nodes), 'direction' => $direction, 'layer' => $layer]];
    }

    public function testClipsCrossBoundaryRoadAndKeepsOriginalInteriorNodes(): void
    {
        $roads = $this->candidates([$this->way(10, [[-135.731, 60.605], [-135.725, 60.605], [-135.719, 60.605]], [1, 2, 3])]);
        self::assertCount(1, $roads);
        self::assertEqualsWithDelta(-135.73, $roads[0]['geometry']['coordinates'][0][0], 1e-9);
        self::assertEqualsWithDelta(-135.72, $roads[0]['geometry']['coordinates'][2][0], 1e-9);
        self::assertSame('osm-node:2', $roads[0]['routing']['node_keys'][1]);
        self::assertStringStartsWith('osm-boundary:10:', $roads[0]['routing']['node_keys'][0]);
    }

    public function testHoleSplitsRoadRatherThanBridgingExcludedArea(): void
    {
        $roads = $this->candidates([$this->way(10, [[-135.729, 60.605], [-135.721, 60.605]], [1, 2])], $this->border(true));
        self::assertCount(2, $roads);
        self::assertEqualsWithDelta(-135.726, $roads[0]['geometry']['coordinates'][1][0], 1e-9);
        self::assertEqualsWithDelta(-135.724, $roads[1]['geometry']['coordinates'][0][0], 1e-9);
        self::assertNotSame($roads[0]['key'], $roads[1]['key']);
    }

    public function testMultiPolygonAndEmptyCoverage(): void
    {
        $border = ['type' => 'MultiPolygon', 'coordinates' => [$this->border()['coordinates'], [[[-135.71, 60.60], [-135.70, 60.60], [-135.70, 60.61], [-135.71, 60.61], [-135.71, 60.60]]]]];
        $roads = $this->candidates([$this->way(10, [[-135.731, 60.605], [-135.699, 60.605]], [1, 2])], $border);
        self::assertCount(2, $roads);
        self::assertCount(0, $this->candidates([$this->way(11, [[-135.69, 60.605], [-135.68, 60.605]], [3, 4])]));
    }

    public function testFiltersMotorVehicleAccessAndMarksRestrictions(): void
    {
        $coords = [[-135.729, 60.605], [-135.721, 60.605]];
        $roads = $this->candidates([
            $this->way(1, $coords, [1, 2], ['motor_vehicle' => 'no']),
            $this->way(2, $coords, [3, 4], ['highway' => 'footway']),
            $this->way(3, $coords, [5, 6], ['access' => 'private']),
            $this->way(4, $coords, [7, 8], ['highway' => 'construction']),
            $this->way(5, $coords, [9, 10], ['access' => 'no', 'motor_vehicle' => 'yes']),
        ]);
        self::assertCount(3, $roads);
        self::assertTrue($roads[0]['restricted']);
        self::assertSame('construction', $roads[1]['status']);
        self::assertFalse($roads[2]['restricted']);
    }

    public function testSourceIdentitySurvivesOrdinaryBendChangeAndAreaIsIsolated(): void
    {
        $a = $this->candidates([$this->way(10, [[-135.729, 60.605], [-135.725, 60.605], [-135.721, 60.605]], [1, 2, 3])]);
        $b = $this->candidates([$this->way(10, [[-135.729, 60.605], [-135.725, 60.606], [-135.721, 60.605]], [1, 2, 3])]);
        self::assertSame($a[0]['key'], $b[0]['key']);
        $importer = new OsmRoadImportService(new SiteRoadGraphService());
        $other = $importer->candidates(['elements' => [$this->way(10, [[-135.729, 60.605], [-135.721, 60.605]], [1, 3])]], $this->border(), 'other-area');
        self::assertNotSame($a[0]['key'], $other[0]['key']);
    }

    public function testOverlapReviewPreservesManualClosuresButAllowsCrossRoad(): void
    {
        $importer = new OsmRoadImportService(new SiteRoadGraphService());
        $candidates = $this->candidates([
            $this->way(10, [[-135.729, 60.605], [-135.721, 60.605]], [1, 2]),
            $this->way(11, [[-135.725, 60.601], [-135.725, 60.609]], [3, 4]),
            $this->way(12, [[-135.721, 60.605], [-135.7202, 60.605]], [2, 5]),
        ]);
        $existing = [['name' => '封闭手绘道路', 'status' => 'closed', 'geometry' => ['coordinates' => [[-135.729, 60.605], [-135.721, 60.605]]]]];
        $marked = $importer->markExisting($candidates, $existing);
        self::assertSame('overlap', $marked[0]['availability']);
        self::assertSame('new', $marked[1]['availability']);
        self::assertSame('new', $marked[2]['availability']);
        self::assertSame('deleted', $importer->markExisting($candidates, [['source_key' => $candidates[0]['key'], 'deleted_at' => '2026-01-01']])[0]['availability']);
    }

    public function testDeletedSourcePrecedesOverlapAndCachedAvailabilityIsRechecked(): void
    {
        $importer = new OsmRoadImportService(new SiteRoadGraphService());
        $candidate = $this->candidates([$this->way(10, [[-135.729, 60.605], [-135.721, 60.605]], [1, 2])])[0];
        $roads = [['source_key' => 'different', 'name' => 'Overlapping', 'geometry' => $candidate['geometry'], 'routing' => $candidate['routing']],
            ['source_key' => $candidate['key'], 'uuid' => 'original', 'name' => 'Custom name', 'deleted_at' => '2026-01-01', 'geometry' => $candidate['geometry']]];
        $deleted = $importer->markExisting([$candidate], $roads)[0];
        self::assertSame('deleted', $deleted['availability']);
        self::assertSame('original', $deleted['existing_road_uuid']);
        self::assertSame('Custom name', $deleted['existing_road_name']);
        self::assertSame($candidate['geometry'], $deleted['existing_road_geometry']);
        $roads[1]['deleted_at'] = null;
        $active = $importer->markExisting([$deleted], $roads)[0];
        self::assertSame('existing', $active['availability']);
        self::assertArrayNotHasKey('existing_road_geometry', $active);
        self::assertSame('new', $importer->markExisting([$active], [])[0]['availability']);
    }

    public function testOneWayIncludesMidEdgeEndpointsAndDoesNotTeleportToNode(): void
    {
        $g = new SiteRoadGraphService();
        foreach ([1, -1] as $direction) {
            $roads = [$this->road('oneway', [[-135.73, 60.6], [-135.72, 60.6]], [1, 2], $direction)];
            $forward = $g->routeFromSegments($roads, 60.6, -135.728, 60.6, -135.722);
            $reverse = $g->routeFromSegments($roads, 60.6, -135.722, 60.6, -135.728);
            self::assertNotNull($direction === 1 ? $forward : $reverse);
            self::assertNull($direction === 1 ? $reverse : $forward);
        }
    }

    public function testNearbyParallelRoadsAndGeometricCrossingsStayDisconnected(): void
    {
        $g = new SiteRoadGraphService();
        $roads = [$this->road('lower', [[-135.73, 60.6], [-135.72, 60.6]], [1, 2]),
            $this->road('upper', [[-135.725, 60.599], [-135.725, 60.601]], [3, 4], 0, '1'),
            $this->road('parallel', [[-135.73, 60.6001], [-135.72, 60.6001]], [5, 6])];
        self::assertCount(0, $g->junctions($roads));
        self::assertNull($g->routeFromSegments($roads, 60.6, -135.73, 60.601, -135.725));
        self::assertNull($g->routeFromSegments($roads, 60.6, -135.73, 60.6001, -135.72));
    }

    public function testSharedOsmNodesMakeJunctionAndOrdinaryBendsStayInSection(): void
    {
        $g = new SiteRoadGraphService();
        $roads = [$this->road('main', [[-135.73, 60.6], [-135.728, 60.6001], [-135.725, 60.6], [-135.72, 60.6]], [1, 2, 3, 4], 1),
            $this->road('branch', [[-135.725, 60.6], [-135.725, 60.602]], [3, 5])];
        $topology = $g->topology($roads);
        self::assertCount(1, $topology['junctions']);
        self::assertCount(3, $topology['road_segments']);
        foreach ($topology['road_segments'] as $s) self::assertCount(count($s['geometry']['coordinates']), $s['routing']['node_keys']);
        self::assertNotNull($g->routeFromSegments($topology['road_segments'], 60.6, -135.73, 60.602, -135.725));
        self::assertNull($g->routeFromSegments($topology['road_segments'], 60.602, -135.725, 60.6, -135.73));
    }

    public function testClosedGroundJunctionDoesNotCloseUnconnectedBridge(): void
    {
        $g = new SiteRoadGraphService();
        $roads = [$this->road('ground', [[-135.73, 60.6], [-135.725, 60.6], [-135.72, 60.6]], [1, 2, 3]),
            $this->road('branch', [[-135.725, 60.6], [-135.725, 60.602]], [2, 4]),
            $this->road('bridge', [[-135.726, 60.599], [-135.724, 60.601]], [5, 6], 0, '1')];
        $topology = $g->topology($roads);
        $junction = array_merge($topology['junctions'][0], ['status' => 'closed']);
        self::assertNotNull($g->routeFromSegments($topology['road_segments'], 60.599, -135.726, 60.601, -135.724, [], [$junction]));
        self::assertNull($g->routeFromSegments($topology['road_segments'], 60.6, -135.73, 60.6, -135.72, [], [$junction]));
    }

    public function testManualRoadCanJoinImportedRoadAtActualGroundCrossing(): void
    {
        $g = new SiteRoadGraphService();
        $osm = $this->road('osm', [[-135.73, 60.6], [-135.72, 60.6]], [1, 2]);
        $manual = ['uuid' => 'manual', 'name' => 'manual', 'status' => 'open', 'geometry' => ['type' => 'LineString', 'coordinates' => [[-135.725, 60.599], [-135.725, 60.601]]]];
        self::assertCount(1, $g->junctions([$osm, $manual]));
        self::assertNotNull($g->routeFromSegments([$osm, $manual], 60.6, -135.73, 60.601, -135.725));
        $osm['routing']['layer'] = '1';
        self::assertCount(0, $g->junctions([$osm, $manual]));
    }
}
