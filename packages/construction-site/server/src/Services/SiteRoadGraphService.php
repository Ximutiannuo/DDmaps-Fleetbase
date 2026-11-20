<?php

namespace Fleetbase\ConstructionSite\Services;

class SiteRoadGraphService
{
    public const SNAP_METERS = 25.0;
    public const MAX_ENDPOINT_SNAP_METERS = 100.0;
    public const PARTIAL_PENALTY = 1.5;
    public const SITE_SPEED_MPS = 20.0 / 3.6;
    public const AVOID_LINE_BUFFER_METERS = 12.0;
    public const CONNECTOR_START_UUID = '__connector_start__';
    public const CONNECTOR_END_UUID = '__connector_end__';

    public function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $R * atan2(sqrt($a), sqrt(1 - $a));
    }

    public function isImpassable(?string $status): bool
    {
        return in_array($status, ['closed', 'construction'], true);
    }

    public function edgeWeight(float $lengthMeters, ?string $status): float
    {
        if ($status === 'partial') {
            return $lengthMeters * self::PARTIAL_PENALTY;
        }

        return $lengthMeters;
    }

    /**
     * @param  array{0:float,1:float}  $a  [lat, lng]
     * @param  array{0:float,1:float}  $b  [lat, lng]
     * @param  array<int, array{uuid?:string,geometry:array}>  $avoidAreas
     */
    public function edgeBlockedByAvoids(array $a, array $b, array $avoidAreas): bool
    {
        foreach ($avoidAreas as $avoid) {
            $geometry = $avoid['geometry'] ?? null;
            if (!is_array($geometry) || empty($geometry['type']) || empty($geometry['coordinates'])) {
                continue;
            }

            $type = $geometry['type'];
            if ($type === 'Polygon') {
                $ring = $geometry['coordinates'][0] ?? [];
                if ($this->edgeIntersectsPolygonRing($a, $b, $ring)) {
                    return true;
                }
            } elseif ($type === 'Point') {
                [$lng, $lat] = $geometry['coordinates'];
                if ($this->distancePointToSegmentMeters($lat, $lng, $a[0], $a[1], $b[0], $b[1]) <= ($avoid['radius_meters'] ?? 15)) {
                    return true;
                }
            } elseif ($type === 'LineString') {
                if ($this->edgeIntersectsLineStringBuffer($a, $b, $geometry['coordinates'], self::AVOID_LINE_BUFFER_METERS)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<int, array{uuid:string,name?:string,status:?string,geometry:array}>  $segments
     * @param  array<int, array{uuid?:string,geometry:array}>  $avoidAreas
     * @return array{coordinates: array<int, array{0:float,1:float}>, total_distance: float, total_duration: float, segment_uuids: array<int,string>, steps: array<int, array{type:string,name:string}>}|null
     */
    public function routeFromSegments(array $segments, float $startLat, float $startLng, float $endLat, float $endLng, array $avoidAreas = [], array $junctions = []): ?array
    {
        if ($this->hasExplicitNodes($segments)) {
            return $this->routeImported($segments, $startLat, $startLng, $endLat, $endLng, $avoidAreas, $junctions);
        }
        // Endpoint snapping must not move a position inside a closed section
        // onto an adjacent open section. Shared junction endpoints remain usable.
        foreach ([[$startLat, $startLng], [$endLat, $endLng]] as [$lat, $lng]) {
            $nearestOpen = INF;
            $nearestClosed = INF;
            foreach ($segments as $segment) {
                $coords = $segment['geometry']['coordinates'] ?? [];
                for ($i = 1; $i < count($coords); $i++) {
                    $distance = $this->distancePointToSegmentMeters($lat, $lng, $coords[$i - 1][1], $coords[$i - 1][0], $coords[$i][1], $coords[$i][0]);
                    if ($this->isImpassable($segment['status'] ?? null)) $nearestClosed = min($nearestClosed, $distance);
                    else $nearestOpen = min($nearestOpen, $distance);
                }
            }
            if ($nearestClosed <= self::SNAP_METERS && $nearestClosed + 0.01 < $nearestOpen) return null;
        }
        foreach ($junctions as $junction) {
            if ($this->isImpassable($junction['status'] ?? null)) {
                if ($this->haversineMeters($startLat, $startLng, $junction['latitude'], $junction['longitude']) <= 15
                    || $this->haversineMeters($endLat, $endLng, $junction['latitude'], $junction['longitude']) <= 15) {
                    return null;
                }
                $avoidAreas[] = ['geometry' => $junction['geometry'], 'radius_meters' => 15];
            }
        }
        $graph = $this->buildGraph($segments, $avoidAreas, $junctions);
        if (empty($graph['nodes']) || empty($graph['edges'])) {
            return null;
        }

        // Snapshot the raw segments so connector legs are never re-added
        // when this method is called repeatedly (e.g. recalculations).
        $baseSegments = array_filter($segments, fn ($s) => ($s['uuid'] ?? '') !== self::CONNECTOR_START_UUID && ($s['uuid'] ?? '') !== self::CONNECTOR_END_UUID);
        $baseSegments = array_values($baseSegments);

        $startNode = $this->snapToNearestPassableNode($graph, $startLat, $startLng, self::MAX_ENDPOINT_SNAP_METERS);
        $endNode = $this->snapToNearestPassableNode($graph, $endLat, $endLng, self::MAX_ENDPOINT_SNAP_METERS);

        // When the start/end lies beyond the snap radius, project it onto the
        // nearest passable edge (perpendicular foot) and model the connector
        // leg as a real graph segment. This makes the route follow roads:
        // the straight leg only runs from the off-road position to the
        // nearest point ON the road, then the route continues along the
        // network from the projection point (edge is split there).
        $extendedSegments = $baseSegments;

        if ($startNode === null) {
            $foot = $this->nearestFootOnEdges($graph, $startLat, $startLng);
            if ($foot !== null) {
                $extendedSegments[] = [
                    'uuid' => self::CONNECTOR_START_UUID,
                    'name' => '前往道路入口',
                    'status' => 'open',
                    'geometry' => [
                        'type' => 'LineString',
                        'coordinates' => [[$startLng, $startLat], [$foot['lng'], $foot['lat']]],
                    ],
                ];
            }
        }

        if ($endNode === null) {
            $foot = $this->nearestFootOnEdges($graph, $endLat, $endLng);
            if ($foot !== null) {
                $extendedSegments[] = [
                    'uuid' => self::CONNECTOR_END_UUID,
                    'name' => '驶离道路',
                    'status' => 'open',
                    'geometry' => [
                        'type' => 'LineString',
                        'coordinates' => [[$foot['lng'], $foot['lat']], [$endLng, $endLat]],
                    ],
                ];
            }
        }

        if (count($extendedSegments) !== count($baseSegments)) {
            $graph = $this->buildGraph($extendedSegments, $avoidAreas, $junctions);
        }

        $startNode = $this->snapToNearestPassableNode($graph, $startLat, $startLng, self::MAX_ENDPOINT_SNAP_METERS);
        $endNode = $this->snapToNearestPassableNode($graph, $endLat, $endLng, self::MAX_ENDPOINT_SNAP_METERS);

        if ($startNode === null || $endNode === null) {
            return null;
        }

        $path = $this->dijkstra($graph, $startNode, $endNode);
        if ($path === null) {
            return null;
        }

        return $this->pathToRoute($graph, $path, $extendedSegments);
    }

    /**
     * Project a point onto the nearest passable edge (perpendicular foot).
     * Returns the foot coordinate and the distance to it, or null when the
     * graph has no usable edges.
     *
     * @param  array{edges: array<int, array{coordinates: array<int, array{0:float,1:float}>}>}  $graph
     * @return array{lat:float,lng:float,distance:float}|null
     */
    private function nearestFootOnEdges(array $graph, float $lat, float $lng): ?array
    {
        $best = null;
        $bestDistance = INF;

        foreach ($graph['edges'] as $edge) {
            $a = $edge['coordinates'][0];
            $b = $edge['coordinates'][1];

            $distance = $this->distancePointToSegmentMeters($lat, $lng, $a[0], $a[1], $b[0], $b[1]);
            if ($distance >= $bestDistance) {
                continue;
            }

            $t = $this->projectionParameter([$lat, $lng], $a, $b);
            $foot = [
                'lat' => $a[0] + $t * ($b[0] - $a[0]),
                'lng' => $a[1] + $t * ($b[1] - $a[1]),
            ];

            $best = ['lat' => $foot['lat'], 'lng' => $foot['lng'], 'distance' => $distance];
            $bestDistance = $distance;
        }

        return $best;
    }

    /**
     * Find the graph node closest to a point, ignoring the snap radius.
     *
     * @param  array{nodes: array<string, array{lat:float,lng:float}>}  $graph
     */
    private function buildGraph(array $segments, array $avoidAreas = [], array $junctions = []): array
    {
        return $this->hasExplicitNodes($segments)
            ? $this->buildImportedGraph($segments, $avoidAreas, $junctions)
            : $this->buildLegacyGraph($segments, $avoidAreas, $junctions);
    }

    private function buildLegacyGraph(array $segments, array $avoidAreas = [], array $junctions = []): array
    {
        $nodes = [];
        $edges = [];

        // Pass 1: collect passable raw edges and every endpoint coordinate.
        $rawEdges = [];
        $snapPoints = [];

        foreach ($segments as $segment) {
            if ($this->isImpassable($segment['status'] ?? null)) {
                continue;
            }

            $coords = $segment['geometry']['coordinates'] ?? [];
            if (count($coords) < 2) {
                continue;
            }

            for ($i = 0; $i < count($coords) - 1; $i++) {
                [$lng1, $lat1] = $coords[$i];
                [$lng2, $lat2] = $coords[$i + 1];

                $a = [(float) $lat1, (float) $lng1];
                $b = [(float) $lat2, (float) $lng2];

                $rawEdges[] = [
                    'a' => $a,
                    'b' => $b,
                    'segment_uuid' => $segment['uuid'],
                    'status' => $segment['status'] ?? null,
                    'traffic_multiplier' => max(1, (float) ($segment['traffic_multiplier'] ?? 1)),
                ];

                $snapPoints[] = $a;
                $snapPoints[] = $b;
            }
        }

        // Pass 1.5: add every edge-edge intersection as a snap point so
        // X/cross junctions split both roads into shared nodes.
        foreach ($rawEdges as $i => $e1) {
            foreach ($rawEdges as $j => $e2) {
                if ($j <= $i) {
                    continue;
                }

                $intersection = $this->lineIntersection($e1['a'], $e1['b'], $e2['a'], $e2['b']);
                if ($intersection !== null) {
                    $snapPoints[] = $intersection;
                }
            }
        }

        // Pass 2: split every raw edge at any snap point that projects onto
        // it (endpoints or intersections), then emit sub-edges.
        foreach ($rawEdges as $raw) {
            $a = $raw['a'];
            $b = $raw['b'];

            $cuts = [0.0, 1.0];
            // Keep the usable approaches to a closed junction. Cut the edge at
            // the boundary instead of discarding its entire length.
            foreach ($avoidAreas as $avoid) {
                if (($avoid['geometry']['type'] ?? '') !== 'Point') continue;
                [$lng, $lat] = $avoid['geometry']['coordinates'];
                $xScale = 111320 * cos(deg2rad($lat));
                $yScale = 111320;
                $x = ($a[1] - $lng) * $xScale;
                $y = ($a[0] - $lat) * $yScale;
                $dx = ($b[1] - $a[1]) * $xScale;
                $dy = ($b[0] - $a[0]) * $yScale;
                $aa = $dx * $dx + $dy * $dy;
                $bb = 2 * ($x * $dx + $y * $dy);
                $radius = ($avoid['radius_meters'] ?? 15) + 0.1;
                $cc = $x * $x + $y * $y - $radius * $radius;
                $discriminant = $bb * $bb - 4 * $aa * $cc;
                if ($aa > 0 && $discriminant >= 0) {
                    foreach ([(-$bb - sqrt($discriminant)) / (2 * $aa), (-$bb + sqrt($discriminant)) / (2 * $aa)] as $t) {
                        if ($t > 0 && $t < 1) $cuts[] = $t;
                    }
                }
            }
            foreach ($snapPoints as $p) {
                if ($this->pointsEqual($p, $a) || $this->pointsEqual($p, $b)) {
                    continue;
                }

                $distance = $this->distancePointToSegmentMeters($p[0], $p[1], $a[0], $a[1], $b[0], $b[1]);
                if ($distance <= self::SNAP_METERS) {
                    $t = $this->projectionParameter($p, $a, $b);
                    if ($t > 1e-6 && $t < 1 - 1e-6) {
                        $cuts[] = $t;
                    }
                }
            }

            sort($cuts);

            $prev = $a;
            foreach ($cuts as $t) {
                if ($t <= 0.0 || $t >= 1.0) {
                    continue;
                }

                $point = [
                    $a[0] + $t * ($b[0] - $a[0]),
                    $a[1] + $t * ($b[1] - $a[1]),
                ];

                if ($this->pointsEqual($prev, $point)) {
                    continue;
                }

                $edgeCoords = [$prev, $point];
                if ($this->edgeBlockedByAvoids($prev, $point, $avoidAreas)) {
                    $prev = $point;
                    continue;
                }

                $node1 = $this->findOrCreateNode($nodes, $prev[0], $prev[1], $avoidAreas);
                $node2 = $this->findOrCreateNode($nodes, $point[0], $point[1], $avoidAreas);

                if ($node1 !== $node2) {
                    $length = $this->haversineMeters($prev[0], $prev[1], $point[0], $point[1]);
                    $edges[] = [
                        'from' => $node1,
                        'to' => $node2,
                        'length' => $length,
                        'weight' => $this->edgeWeight($length, $raw['status'] ?? null) * $raw['traffic_multiplier'],
                        'segment_uuid' => $raw['segment_uuid'],
                        'coordinates' => $edgeCoords,
                    ];
                }

                $prev = $point;
            }

            if (!$this->pointsEqual($prev, $b)) {
                $edgeCoords = [$prev, $b];
                if (!$this->edgeBlockedByAvoids($prev, $b, $avoidAreas)) {
                    $node1 = $this->findOrCreateNode($nodes, $prev[0], $prev[1], $avoidAreas);
                    $node2 = $this->findOrCreateNode($nodes, $b[0], $b[1], $avoidAreas);

                    if ($node1 !== $node2) {
                        $length = $this->haversineMeters($prev[0], $prev[1], $b[0], $b[1]);
                        $edges[] = [
                            'from' => $node1,
                            'to' => $node2,
                            'length' => $length,
                            'weight' => $this->edgeWeight($length, $raw['status'] ?? null) * $raw['traffic_multiplier'],
                            'segment_uuid' => $raw['segment_uuid'],
                            'coordinates' => $edgeCoords,
                        ];
                    }
                }
            }
        }

        foreach ($junctions as $junction) {
            if ($this->isImpassable($junction['status'] ?? null)) continue;
            $node = $this->snapToNearestPassableNode(['nodes' => $nodes, 'adjacency' => $this->buildAdjacency($edges)], $junction['latitude'], $junction['longitude'], self::SNAP_METERS);
            if ($node !== null) {
                $nodes[$node]['delay_weight'] = ($junction['delay_seconds'] ?? 0) * self::SITE_SPEED_MPS;
            }
        }

        return [
            'nodes' => $nodes,
            'edges' => $edges,
            'adjacency' => $this->buildAdjacency($edges),
        ];
    }

    /**
     * Compute the intersection point of two segments, or null when they do
     * not properly cross (parallel, collinear, or only touching at ends).
     *
     * @param  array{0:float,1:float}  $a  [lat, lng]
     * @param  array{0:float,1:float}  $b  [lat, lng]
     * @param  array{0:float,1:float}  $c  [lat, lng]
     * @param  array{0:float,1:float}  $d  [lat, lng]
     * @return array{0:float,1:float}|null
     */
    private function lineIntersection(array $a, array $b, array $c, array $d): ?array
    {
        // Work in planar lng/lat space (sufficient for short site edges).
        $x1 = $a[1];
        $y1 = $a[0];
        $x2 = $b[1];
        $y2 = $b[0];
        $x3 = $c[1];
        $y3 = $c[0];
        $x4 = $d[1];
        $y4 = $d[0];

        $denominator = ($x1 - $x2) * ($y3 - $y4) - ($y1 - $y2) * ($x3 - $x4);
        if (abs($denominator) < 1e-15) {
            return null;
        }

        $t = (($x1 - $x3) * ($y3 - $y4) - ($y1 - $y3) * ($x3 - $x4)) / $denominator;
        $u = -((($x1 - $x2) * ($y1 - $y3) - ($y1 - $y2) * ($x1 - $x3)) / $denominator);

        if ($t > 1e-6 && $t < 1 - 1e-6 && $u > 1e-6 && $u < 1 - 1e-6) {
            return [
                $y1 + $t * ($y2 - $y1),
                $x1 + $t * ($x2 - $x1),
            ];
        }

        return null;
    }

    /**
     * Projection parameter t of point p onto segment a->b, clamped to [0, 1].
     *
     * @param  array{0:float,1:float}  $p  [lat, lng]
     * @param  array{0:float,1:float}  $a  [lat, lng]
     * @param  array{0:float,1:float}  $b  [lat, lng]
     */
    private function projectionParameter(array $p, array $a, array $b): float
    {
        $dx = $b[1] - $a[1];
        $dy = $b[0] - $a[0];
        $denominator = $dx * $dx + $dy * $dy;

        if (abs($denominator) < 1e-15) {
            return 0.0;
        }

        $t = (($p[1] - $a[1]) * $dx + ($p[0] - $a[0]) * $dy) / $denominator;

        return max(0.0, min(1.0, $t));
    }

    /**
     * @param  array{0:float,1:float}  $a  [lat, lng]
     * @param  array{0:float,1:float}  $b  [lat, lng]
     * @param  array<int, array{0:float,1:float}>  $ring  GeoJSON ring [lng, lat]
     */
    public function edgeIntersectsPolygonRing(array $a, array $b, array $ring): bool
    {
        if (count($ring) < 3) {
            return false;
        }

        if ($this->pointInPolygonRing($a[0], $a[1], $ring) || $this->pointInPolygonRing($b[0], $b[1], $ring)) {
            return true;
        }

        for ($i = 0; $i < count($ring) - 1; $i++) {
            $lng1 = (float) $ring[$i][0];
            $lat1 = (float) $ring[$i][1];
            $lng2 = (float) $ring[$i + 1][0];
            $lat2 = (float) $ring[$i + 1][1];

            if ($this->segmentsIntersect($a[1], $a[0], $b[1], $b[0], $lng1, $lat1, $lng2, $lat2)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{0:float,1:float}  $a  [lat, lng]
     * @param  array{0:float,1:float}  $b  [lat, lng]
     * @param  array<int, array{0:float,1:float}>  $lineCoords  GeoJSON LineString [lng, lat]
     */
    public function edgeIntersectsLineStringBuffer(array $a, array $b, array $lineCoords, float $bufferMeters): bool
    {
        if (count($lineCoords) < 2) {
            return false;
        }

        for ($i = 0; $i < count($lineCoords) - 1; $i++) {
            $lng1 = (float) $lineCoords[$i][0];
            $lat1 = (float) $lineCoords[$i][1];
            $lng2 = (float) $lineCoords[$i + 1][0];
            $lat2 = (float) $lineCoords[$i + 1][1];

            if ($this->segmentsIntersect($a[1], $a[0], $b[1], $b[0], $lng1, $lat1, $lng2, $lat2)) {
                return true;
            }

            if ($this->distancePointToSegmentMeters($a[0], $a[1], $lat1, $lng1, $lat2, $lng2) <= $bufferMeters) {
                return true;
            }
            if ($this->distancePointToSegmentMeters($b[0], $b[1], $lat1, $lng1, $lat2, $lng2) <= $bufferMeters) {
                return true;
            }
            if ($this->distancePointToSegmentMeters($lat1, $lng1, $a[0], $a[1], $b[0], $b[1]) <= $bufferMeters) {
                return true;
            }
            if ($this->distancePointToSegmentMeters($lat2, $lng2, $a[0], $a[1], $b[0], $b[1]) <= $bufferMeters) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ray-cast point-in-polygon. Point is [lat,lng]; ring vertices are [lng,lat].
     *
     * @param  array<int, array{0:float,1:float}>  $ring
     */
    public function pointInPolygonRing(float $lat, float $lng, array $ring): bool
    {
        $inside = false;
        $n = count($ring);
        if ($n < 3) {
            return false;
        }

        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi = (float) $ring[$i][0];
            $yi = (float) $ring[$i][1];
            $xj = (float) $ring[$j][0];
            $yj = (float) $ring[$j][1];

            $intersect = (($yi > $lat) !== ($yj > $lat))
                && ($lng < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-15) + $xi);

            if ($intersect) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    public function distancePointToSegmentMeters(
        float $lat,
        float $lng,
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2
    ): float {
        $longitudeScale = cos(deg2rad($lat));
        $dx = ($lng2 - $lng1) * $longitudeScale;
        $dy = $lat2 - $lat1;
        if (abs($dx) < 1e-15 && abs($dy) < 1e-15) {
            return $this->haversineMeters($lat, $lng, $lat1, $lng1);
        }

        $t = (($lng - $lng1) * $longitudeScale * $dx + ($lat - $lat1) * $dy) / ($dx * $dx + $dy * $dy);
        $t = max(0.0, min(1.0, $t));

        return $this->haversineMeters($lat, $lng, $lat1 + $t * $dy, $lng1 + $t * ($lng2 - $lng1));
    }

    /**
     * Segment intersection in planar lng/lat space (sufficient for short site edges).
     */
    public function segmentsIntersect(
        float $ax,
        float $ay,
        float $bx,
        float $by,
        float $cx,
        float $cy,
        float $dx,
        float $dy
    ): bool {
        $orient = static function (float $px, float $py, float $qx, float $qy, float $rx, float $ry): float {
            return ($qy - $py) * ($rx - $qx) - ($qx - $px) * ($ry - $qy);
        };

        $o1 = $orient($ax, $ay, $bx, $by, $cx, $cy);
        $o2 = $orient($ax, $ay, $bx, $by, $dx, $dy);
        $o3 = $orient($cx, $cy, $dx, $dy, $ax, $ay);
        $o4 = $orient($cx, $cy, $dx, $dy, $bx, $by);

        if ($o1 * $o2 < 0 && $o3 * $o4 < 0) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, array{lat:float,lng:float}>  $nodes
     */
    private function findOrCreateNode(array &$nodes, float $lat, float $lng, array $avoidAreas = []): string
    {
        foreach ($nodes as $key => $node) {
            if ($this->haversineMeters($lat, $lng, $node['lat'], $node['lng']) <= self::SNAP_METERS
                && !$this->edgeBlockedByAvoids([$lat, $lng], [$node['lat'], $node['lng']], $avoidAreas)) {
                return $key;
            }
        }

        $key = sprintf('%.6f:%.6f', round($lat, 6), round($lng, 6));
        $nodes[$key] = ['lat' => $lat, 'lng' => $lng];

        return $key;
    }

    /**
     * @param  array<int, array{from:string,to:string,length:float,weight:float,segment_uuid:string,coordinates:array<int, array{0:float,1:float}>}>  $edges
     * @return array<string, array<int, array{neighbor:string,weight:float,edge_idx:int}>>
     */
    private function buildAdjacency(array $edges, bool $respectDirection = true): array
    {
        $adjacency = [];

        foreach ($edges as $idx => $edge) {
            $direction = $respectDirection ? ($edge['direction'] ?? 0) : 0;
            if ($direction >= 0) {
            $adjacency[$edge['from']][] = [
                'neighbor' => $edge['to'],
                'weight' => $edge['weight'],
                'edge_idx' => $idx,
            ];
            }
            if ($direction <= 0) {
            $adjacency[$edge['to']][] = [
                'neighbor' => $edge['from'],
                'weight' => $edge['weight'],
                'edge_idx' => $idx,
            ];
            }
        }

        return $adjacency;
    }

    /**
     * @param  array{nodes: array<string, array{lat:float,lng:float}>, edges: array<int, array{from:string,to:string,length:float,weight:float,segment_uuid:string,coordinates:array<int, array{0:float,1:float}>}>, adjacency: array<string, array<int, array{neighbor:string,weight:float,edge_idx:int}>>}  $graph
     */
    private function snapToNearestPassableNode(array $graph, float $lat, float $lng, float $maxMeters): ?string
    {
        $nearest = null;
        $nearestDistance = INF;

        foreach ($graph['nodes'] as $key => $node) {
            $distance = $this->haversineMeters($lat, $lng, $node['lat'], $node['lng']);
            if ($distance <= $maxMeters && $distance < $nearestDistance) {
                $nearest = $key;
                $nearestDistance = $distance;
            }
        }

        return $nearest;
    }

    /**
     * @param  array{nodes: array<string, array{lat:float,lng:float}>, edges: array<int, array{from:string,to:string,length:float,weight:float,segment_uuid:string,coordinates:array<int, array{0:float,1:float}>}>, adjacency: array<string, array<int, array{neighbor:string,weight:float,edge_idx:int}>>}  $graph
     * @return array{nodes: array<int, string>, edge_indices: array<int, int>}|null
     */
    private function dijkstra(array $graph, string $startNode, string $endNode): ?array
    {
        if ($startNode === $endNode) {
            return ['nodes' => [$startNode], 'edge_indices' => []];
        }

        $dist = array_fill_keys(array_keys($graph['nodes']), INF);
        $prev = [];
        $prevEdgeIdx = [];
        $visited = [];
        $dist[$startNode] = 0.0;
        $queue = [$startNode];

        while ($queue !== []) {
            $minIdx = 0;
            $minDist = $dist[$queue[0]];
            foreach ($queue as $idx => $node) {
                if ($dist[$node] < $minDist) {
                    $minDist = $dist[$node];
                    $minIdx = $idx;
                }
            }

            $current = $queue[$minIdx];
            array_splice($queue, $minIdx, 1);

            if (isset($visited[$current])) {
                continue;
            }
            $visited[$current] = true;

            if ($current === $endNode) {
                break;
            }

            foreach ($graph['adjacency'][$current] ?? [] as $adj) {
                $neighbor = $adj['neighbor'];
                $alt = $dist[$current] + $adj['weight'] + ($graph['nodes'][$neighbor]['delay_weight'] ?? 0);

                if ($alt < $dist[$neighbor]) {
                    $dist[$neighbor] = $alt;
                    $prev[$neighbor] = $current;
                    $prevEdgeIdx[$neighbor] = $adj['edge_idx'];

                    if (! isset($visited[$neighbor]) && ! in_array($neighbor, $queue, true)) {
                        $queue[] = $neighbor;
                    }
                }
            }
        }

        if (! isset($prev[$endNode])) {
            return null;
        }

        $nodes = [$endNode];
        $edgeIndices = [];
        $current = $endNode;

        while ($current !== $startNode) {
            $edgeIndices[] = $prevEdgeIdx[$current];
            $current = $prev[$current];
            $nodes[] = $current;
        }

        return [
            'nodes' => array_reverse($nodes),
            'edge_indices' => array_reverse($edgeIndices),
        ];
    }

    /**
     * @param  array{nodes: array<string, array{lat:float,lng:float}>, edges: array<int, array{from:string,to:string,length:float,weight:float,segment_uuid:string,coordinates:array<int, array{0:float,1:float}>}>, adjacency: array<string, array<int, array{neighbor:string,weight:float,edge_idx:int}>>}  $graph
     * @param  array{nodes: array<int, string>, edge_indices: array<int, int>}  $path
     * @param  array<int, array{uuid:string,name?:string,status:?string,geometry:array}>  $segments
     * @return array{coordinates: array<int, array{0:float,1:float}>, total_distance: float, total_duration: float, segment_uuids: array<int,string>, steps: array<int, array{type:string,name:string}>}
     */
    private function pathToRoute(array $graph, array $path, array $segments): array
    {
        $segmentNames = [];
        foreach ($segments as $segment) {
            $segmentNames[$segment['uuid']] = $segment['name'] ?? $segment['uuid'];
        }

        $coordinates = [];
        $segmentUuids = [];
        $offRoadSegments = [];
        $totalDistance = 0.0;
        $totalDuration = 0.0;
        $legs = [];
        $steps = [];
        $previousBearing = null;
        $previousUuid = null;

        if ($path['edge_indices'] === []) {
            $node = $graph['nodes'][$path['nodes'][0]];
            $coordinates[] = [$node['lat'], $node['lng']];
        }

        foreach ($path['edge_indices'] as $idx => $edgeIdx) {
            $edge = $graph['edges'][$edgeIdx];
            $fromNode = $path['nodes'][$idx];
            $from = $graph['nodes'][$fromNode];
            $to = $graph['nodes'][$path['nodes'][$idx + 1]];
            $a = [$from['lat'], $from['lng']];
            $b = [$to['lat'], $to['lng']];
            // Use the same snapped geometry that the client projects onto.
            $length = $this->haversineMeters(...array_merge($a, $b));
            $travelDuration = $edge['length'] > 0
                ? $edge['weight'] / $edge['length'] * $length / self::SITE_SPEED_MPS : 0.0;
            $delay = ($to['delay_weight'] ?? 0) / self::SITE_SPEED_MPS;
            if ($coordinates === []) $coordinates[] = $a;
            $startIndex = count($coordinates) - 1;
            $coordinates[] = $b;
            $isConnector = in_array($edge['segment_uuid'], [self::CONNECTOR_START_UUID, self::CONNECTOR_END_UUID], true);
            if ($isConnector) $offRoadSegments[] = [$a, $b];
            $legs[] = [
                'start_index' => $startIndex, 'end_index' => $startIndex + 1,
                'segment_uuid' => $edge['segment_uuid'], 'distance' => $length,
                'travel_duration' => $travelDuration, 'delay_seconds' => $delay,
                'duration' => $travelDuration + $delay,
            ];
            $bearing = $this->bearing($a, $b);
            $isStepBoundary = $idx === 0 || $previousUuid !== $edge['segment_uuid']
                || count($graph['adjacency'][$fromNode] ?? []) > 2;
            if ($isStepBoundary) {
                $angle = $previousBearing === null ? 0 : fmod($bearing - $previousBearing + 540, 360) - 180;
                $modifier = abs($angle) < 20 ? null
                    : (abs($angle) >= 150 ? 'uturn'
                    : ((abs($angle) < 45 ? 'slight ' : '') . ($angle > 0 ? 'right' : 'left')));
                $steps[] = [
                    'maneuverType' => $idx === 0 ? 'depart' : ($modifier ? 'turn' : 'continue'),
                    'modifier' => $modifier,
                    'name' => $segmentNames[$edge['segment_uuid']] ?? $edge['segment_uuid'],
                    'distance' => 0.0, 'duration' => 0.0,
                    'distance_start' => $totalDistance, 'distance_end' => $totalDistance,
                ];
            }
            $stepIndex = count($steps) - 1;
            $steps[$stepIndex]['distance'] += $length;
            $steps[$stepIndex]['duration'] += $travelDuration + $delay;
            $steps[$stepIndex]['distance_end'] = $totalDistance + $length;
            $totalDistance += $length;
            $totalDuration += $travelDuration + $delay;
            $previousBearing = $bearing;
            $previousUuid = $edge['segment_uuid'];

            if (! in_array($edge['segment_uuid'], $segmentUuids, true)) {
                $segmentUuids[] = $edge['segment_uuid'];
            }
        }

        $steps[] = [
            'maneuverType' => 'arrive', 'modifier' => null,
            'name' => $steps === [] ? '' : $steps[count($steps) - 1]['name'],
            'distance' => 0.0, 'duration' => 0.0,
            'distance_start' => $totalDistance, 'distance_end' => $totalDistance,
        ];

        return [
            'coordinates'        => $coordinates,
            'total_distance'     => $totalDistance,
            'total_duration'     => $totalDuration,
            'legs'               => $legs,
            'optimization'       => 'travel_time',
            'segment_uuids'      => $segmentUuids,
            'off_road_segments'  => $offRoadSegments,
            'steps'              => $steps,
        ];
    }

    private function bearing(array $a, array $b): float
    {
        $lat1 = deg2rad($a[0]);
        $lat2 = deg2rad($b[0]);
        $lng = deg2rad($b[1] - $a[1]);
        return rad2deg(atan2(sin($lng) * cos($lat2),
            cos($lat1) * sin($lat2) - sin($lat1) * cos($lat2) * cos($lng)));
    }

    /**
     * @param  array{0:float,1:float}  $a
     * @param  array{0:float,1:float}  $b
     */
    private function pointsEqual(array $a, array $b): bool
    {
        return abs($a[0] - $b[0]) < 1e-9 && abs($a[1] - $b[1]) < 1e-9;
    }

    /** Split drawn roads at junctions, retaining degree-two shape vertices inside each section. */
    public function topology(array $segments, array $removedSections = []): array
    {
        // Sorting makes snap coordinates and target keys independent of database/input ordering.
        usort($segments, fn ($a, $b) => strcmp($a['uuid'], $b['uuid']));
        $allOpen = array_map(fn ($segment) => array_merge($segment, ['status' => 'open']), $segments);
        $graph = $this->buildGraph($allOpen);
        // Keep a deleted section's boundaries even if its former crossing road disappears.
        // Otherwise adjacent open pieces could merge across the deleted geometry.
        $removalBoundaries = $this->retainSectionBoundaries($graph, $removedSections);
        $graph['adjacency'] = $this->buildAdjacency($graph['edges'], false);
        $junctions = [];
        $boundaries = [];
        $names = array_column($segments, 'name', 'uuid');
        $parents = array_column($segments, null, 'uuid');
        foreach ($graph['nodes'] as $id => $node) {
            $roads = [];
            foreach ($graph['adjacency'][$id] ?? [] as $edge) {
                $roads[] = $graph['edges'][$edge['edge_idx']]['segment_uuid'];
            }
            $roads = array_values(array_unique($roads));
            sort($roads);
            $degree = count($graph['adjacency'][$id] ?? []);
            $isJunction = count($roads) >= 2 || $degree > 2;
            $boundaries[$id] = $isJunction || $degree !== 2 || isset($removalBoundaries[$id]);
            if (!$isJunction) continue;
            $key = 'junction:' . sha1(($node['explicit'] ?? false) ? $id : sprintf('%.7f,%.7f', $node['lat'], $node['lng']));
            $junctions[$id] = [
                'target_key' => $key, 'name' => implode(' / ', array_map(fn ($uuid) => $names[$uuid] ?? $uuid, $roads)),
                'latitude' => $node['lat'], 'longitude' => $node['lng'], 'segment_uuids' => $roads,
                'node_key' => $id, 'layer' => $node['layer'] ?? '0',
                'geometry' => ['type' => 'Point', 'coordinates' => [$node['lng'], $node['lat']]],
            ];
        }
        $visited = [];
        $sections = [];
        $numbers = [];
        // Walk from real boundaries first; a closed loop without junctions is one section.
        $starts = array_keys(array_filter($boundaries));
        $starts = array_merge($starts, array_keys($graph['nodes']));
        foreach ($starts as $start) {
            foreach ($graph['adjacency'][$start] ?? [] as $first) {
                if (isset($visited[$first['edge_idx']])) continue;
                $parentUuid = $graph['edges'][$first['edge_idx']]['segment_uuid'];
                $nodeId = $start;
                $edgeIndex = $first['edge_idx'];
                $coordinates = [];
                $nodeKeys = [];
                $direction = ($graph['edges'][$edgeIndex]['direction'] ?? 0) * ($graph['edges'][$edgeIndex]['from'] === $start ? 1 : -1);
                $length = 0;
                while (true) {
                    $node = $graph['nodes'][$nodeId];
                    $coordinates[] = [$node['lng'], $node['lat']];
                    $nodeKeys[] = $nodeId;
                    $edge = $graph['edges'][$edgeIndex];
                    $visited[$edgeIndex] = true;
                    $length += $edge['length'];
                    $nodeId = $edge['from'] === $nodeId ? $edge['to'] : $edge['from'];
                    $next = array_values(array_filter($graph['adjacency'][$nodeId] ?? [], fn ($item) =>
                        !isset($visited[$item['edge_idx']]) && $graph['edges'][$item['edge_idx']]['segment_uuid'] === $parentUuid));
                    if ($boundaries[$nodeId] || count($next) !== 1) break;
                    $edgeIndex = $next[0]['edge_idx'];
                }
                $end = $graph['nodes'][$nodeId];
                $coordinates[] = [$end['lng'], $end['lat']];
                $nodeKeys[] = $nodeId;
                $normalized = array_map(fn ($p) => [round($p[0], 7), round($p[1], 7)], $coordinates);
                $forward = json_encode($normalized);
                $reverse = json_encode(array_reverse($normalized));
                $hash = sha1(min($forward, $reverse));
                if (($parents[$parentUuid]['source'] ?? '') === 'osm') {
                    $ends = [$nodeKeys[0], end($nodeKeys)];
                    sort($ends);
                    $hash = sha1(implode('|', $ends));
                }
                $key = 'section:' . $parentUuid . ':' . $hash;
                $number = $numbers[$parentUuid] = ($numbers[$parentUuid] ?? 0) + 1;
                $parent = $parents[$parentUuid];
                $routing = $parent['routing'] ?? null;
                if ($this->hasExplicitNodes($segments)) $routing = array_merge($routing ?? [], ['node_keys' => $nodeKeys, 'direction' => $direction]);
                $sections[] = array_merge($parent, [
                    'uuid' => $key, 'target_key' => $key, 'road_segment_uuid' => $parentUuid,
                    'road_name' => $parent['name'], 'name' => $parent['name'] . ' · 第' . $number . '段',
                    'section_number' => $number, 'length_meters' => round($length, 1),
                    'start_label' => isset($junctions[$start]) ? $junctions[$start]['name'] . '交汇点' : '道路端点',
                    'end_label' => isset($junctions[$nodeId]) ? $junctions[$nodeId]['name'] . '交汇点' : '道路端点',
                    'start_junction_key' => $junctions[$start]['target_key'] ?? null,
                    'end_junction_key' => $junctions[$nodeId]['target_key'] ?? null,
                    'geometry' => ['type' => 'LineString', 'coordinates' => $coordinates],
                    'routing' => $routing, 'topology_node_keys' => $nodeKeys,
                ]);
            }
        }
        return ['road_segments' => $sections, 'junctions' => array_values($junctions)];
    }

    private function retainSectionBoundaries(array &$graph, array $removedSections): array
    {
        $anchors = [];
        foreach ($removedSections as $section) {
            $coords = $section['geometry']['coordinates'] ?? [];
            if (count($coords) < 2) continue;
            foreach ([$coords[0], end($coords)] as $p) $anchors[$section['road_segment_uuid']][] = [$p[1], $p[0]];
        }
        if (!$anchors) return [];
        $boundaries = []; $edges = [];
        foreach ($graph['edges'] as $edge) {
            [$a, $b] = $edge['coordinates'];
            $cuts = [['t' => 0, 'key' => $edge['from']], ['t' => 1, 'key' => $edge['to']]];
            foreach ($anchors[$edge['segment_uuid']] ?? [] as $p) {
                if ($this->distancePointToSegmentMeters($p[0], $p[1], ...array_merge($a, $b)) > 0.1) continue;
                $t = $this->projectionParameter($p, $a, $b);
                $key = $t < 1e-8 ? $edge['from'] : ($t > 1 - 1e-8 ? $edge['to'] : 'cut:' . $edge['segment_uuid'] . ':' . sprintf('%.8f,%.8f', $p[0], $p[1]));
                $boundaries[$key] = true;
                $graph['nodes'][$key] ??= ['lat' => $p[0], 'lng' => $p[1], 'explicit' => $graph['nodes'][$edge['from']]['explicit'] ?? false, 'layer' => $graph['nodes'][$edge['from']]['layer'] ?? '0'];
                $cuts[] = ['t' => $t, 'key' => $key];
            }
            usort($cuts, fn ($a, $b) => $a['t'] <=> $b['t']);
            for ($i = 1; $i < count($cuts); $i++) {
                $from = $cuts[$i - 1]; $to = $cuts[$i];
                $fraction = $to['t'] - $from['t'];
                if ($fraction < 1e-9) continue;
                $start = $graph['nodes'][$from['key']]; $end = $graph['nodes'][$to['key']];
                $edges[] = array_merge($edge, ['from' => $from['key'], 'to' => $to['key'], 'length' => $edge['length'] * $fraction,
                    'weight' => $edge['weight'] * $fraction, 'coordinates' => [[$start['lat'], $start['lng']], [$end['lat'], $end['lng']]]]);
            }
        }
        $graph['edges'] = $edges;
        return $boundaries;
    }

    private function hasExplicitNodes(array $segments): bool
    {
        foreach ($segments as $segment) if (!empty($segment['routing']['node_keys'])) return true;
        return false;
    }

    /** OSM roads join only at shared node IDs. Manual roads retain their existing topology. */
    private function buildImportedGraph(array $segments, array $avoidAreas, array $junctions): array
    {
        $manual = array_values(array_filter($segments, fn ($s) => empty($s['routing']['node_keys'])));
        $legacy = $this->buildLegacyGraph($manual, array_values(array_filter($avoidAreas, fn ($avoid) => empty($avoid['segment_uuids']))));
        $raw = [];
        foreach ($legacy['edges'] as $edge) {
            $raw[] = array_merge($edge, ['a' => $edge['coordinates'][0], 'b' => $edge['coordinates'][1],
                'from' => 'manual:' . $edge['from'], 'to' => 'manual:' . $edge['to'], 'explicit' => false, 'layer' => '0']);
        }
        foreach ($segments as $segment) {
            $routing = $segment['routing'] ?? [];
            $keys = $routing['node_keys'] ?? [];
            $coords = $segment['geometry']['coordinates'] ?? [];
            if (!$keys || count($keys) !== count($coords) || $this->isImpassable($segment['status'] ?? null)) continue;
            for ($i = 1; $i < count($coords); $i++) {
                $a = [$coords[$i - 1][1], $coords[$i - 1][0]]; $b = [$coords[$i][1], $coords[$i][0]];
                $length = $this->haversineMeters(...array_merge($a, $b));
                $raw[] = ['a' => $a, 'b' => $b, 'from' => $keys[$i - 1], 'to' => $keys[$i], 'explicit' => true,
                    'layer' => (string) ($routing['layer'] ?? '0'), 'segment_uuid' => $segment['uuid'],
                    'parent_uuid' => $segment['road_segment_uuid'] ?? $segment['uuid'], 'direction' => (int) ($routing['direction'] ?? 0),
                    'length' => $length, 'weight' => $this->edgeWeight($length, $segment['status'] ?? null) * ($segment['traffic_multiplier'] ?? 1)];
            }
        }
        $cuts = [];
        foreach ($raw as $i => $edge) $cuts[$i] = [['t' => 0, 'key' => $edge['from']], ['t' => 1, 'key' => $edge['to']]];
        // Only ground-level manual/imported intersections need geometric stitching.
        $manualEdges = array_filter($raw, fn ($edge) => !$edge['explicit']);
        $importedEdges = array_filter($raw, fn ($edge) => $edge['explicit']);
        foreach ($manualEdges as $i => $a) foreach ($importedEdges as $j => $b) {
            if ($a['layer'] !== $b['layer']
                || max($a['a'][0], $a['b'][0]) + 1e-6 < min($b['a'][0], $b['b'][0])
                || max($b['a'][0], $b['b'][0]) + 1e-6 < min($a['a'][0], $a['b'][0])
                || max($a['a'][1], $a['b'][1]) + 1e-6 < min($b['a'][1], $b['b'][1])
                || max($b['a'][1], $b['b'][1]) + 1e-6 < min($a['a'][1], $a['b'][1])) continue;
            $point = $this->lineIntersection($a['a'], $a['b'], $b['a'], $b['b']);
            $pairs = $point ? [$point] : [];
            foreach ([$a['a'], $a['b'], $b['a'], $b['b']] as $p) {
                if ($this->distancePointToSegmentMeters($p[0], $p[1], ...array_merge($a['a'], $a['b'])) < 0.1
                    && $this->distancePointToSegmentMeters($p[0], $p[1], ...array_merge($b['a'], $b['b'])) < 0.1) $pairs[] = $p;
            }
            foreach ($pairs as $p) {
                $ta = $this->projectionParameter($p, $a['a'], $a['b']);
                $tb = $this->projectionParameter($p, $b['a'], $b['b']);
                $osm = $a['explicit'] ? $a : $b; $t = $a['explicit'] ? $ta : $tb;
                $key = $t < 1e-8 ? $osm['from'] : ($t > 1 - 1e-8 ? $osm['to'] : 'join:' . $a['layer'] . ':' . sprintf('%.8f,%.8f', $p[0], $p[1]));
                $cuts[$i][] = ['t' => $ta, 'key' => $key]; $cuts[$j][] = ['t' => $tb, 'key' => $key];
            }
        }
        $nodes = []; $edges = [];
        foreach ($raw as $i => $edge) {
            // A closed junction affects its own connected roads, including approach pieces.
            $avoids = array_values(array_filter($avoidAreas, fn ($avoid) => empty($avoid['segment_uuids'])
                || in_array($edge['parent_uuid'] ?? $edge['segment_uuid'], $avoid['segment_uuids'], true)));
            foreach ($avoids as $avoid) {
                if (($avoid['geometry']['type'] ?? '') !== 'Point') continue;
                [$lng, $lat] = $avoid['geometry']['coordinates'];
                $xScale = 111320 * cos(deg2rad($lat));
                $x = ($edge['a'][1] - $lng) * $xScale; $y = ($edge['a'][0] - $lat) * 111320;
                $dx = ($edge['b'][1] - $edge['a'][1]) * $xScale; $dy = ($edge['b'][0] - $edge['a'][0]) * 111320;
                $aa = $dx * $dx + $dy * $dy; $bb = 2 * ($x * $dx + $y * $dy);
                $cc = $x * $x + $y * $y - (($avoid['radius_meters'] ?? 15) + 0.1) ** 2;
                $disc = $bb * $bb - 4 * $aa * $cc;
                if ($aa <= 0 || $disc < 0) continue;
                foreach ([(-$bb - sqrt($disc)) / (2 * $aa), (-$bb + sqrt($disc)) / (2 * $aa)] as $t) {
                    if ($t > 0 && $t < 1) $cuts[$i][] = ['t' => $t, 'key' => 'avoid:' . $i . ':' . $t];
                }
            }
            usort($cuts[$i], fn ($a, $b) => $a['t'] <=> $b['t']);
            $unique = [];
            foreach ($cuts[$i] as $cut) $unique[sprintf('%.9f', $cut['t'])] = $cut;
            $parts = array_values($unique);
            for ($j = 1; $j < count($parts); $j++) {
                $before = $parts[$j - 1]; $after = $parts[$j];
                $at = fn ($t) => [$edge['a'][0] + ($edge['b'][0] - $edge['a'][0]) * $t, $edge['a'][1] + ($edge['b'][1] - $edge['a'][1]) * $t];
                $a = $at($before['t']); $b = $at($after['t']);
                if ($this->edgeBlockedByAvoids($a, $b, $avoids) || $before['key'] === $after['key']) continue;
                foreach ([[$before['key'], $a], [$after['key'], $b]] as [$key, $p]) {
                    $nodes[$key] = ['lat' => $p[0], 'lng' => $p[1], 'explicit' => !str_starts_with($key, 'manual:'), 'layer' => $edge['layer']];
                }
                $fraction = $after['t'] - $before['t'];
                $edges[] = array_merge($edge, ['from' => $before['key'], 'to' => $after['key'],
                    'length' => $edge['length'] * $fraction, 'weight' => $edge['weight'] * $fraction, 'coordinates' => [$a, $b]]);
            }
        }
        foreach ($junctions as $junction) {
            $key = $junction['node_key'] ?? null;
            if ($key && isset($nodes[$key]) && !$this->isImpassable($junction['status'] ?? null)) $nodes[$key]['delay_weight'] = ($junction['delay_seconds'] ?? 0) * self::SITE_SPEED_MPS;
        }
        return ['nodes' => $nodes, 'edges' => $edges, 'adjacency' => $this->buildAdjacency($edges)];
    }

    /** Project endpoints onto specific edges so a nearby node cannot bypass one-way rules. */
    private function routeImported(array $segments, float $startLat, float $startLng, float $endLat, float $endLng, array $avoids, array $junctions): ?array
    {
        foreach ([[$startLat, $startLng], [$endLat, $endLng]] as [$lat, $lng]) {
            $open = INF; $closed = INF;
            foreach ($segments as $s) {
                $coords = $s['geometry']['coordinates'] ?? [];
                for ($i = 1; $i < count($coords); $i++) {
                    $d = $this->distancePointToSegmentMeters($lat, $lng, $coords[$i - 1][1], $coords[$i - 1][0], $coords[$i][1], $coords[$i][0]);
                    if ($this->isImpassable($s['status'] ?? null)) $closed = min($closed, $d); else $open = min($open, $d);
                }
            }
            if ($closed <= self::SNAP_METERS && $closed + 0.01 < $open) return null;
        }
        foreach ($junctions as $junction) if ($this->isImpassable($junction['status'] ?? null)) {
            $avoids[] = ['geometry' => $junction['geometry'], 'radius_meters' => 15, 'segment_uuids' => $junction['segment_uuids'] ?? []];
        }
        $graph = $this->buildGraph($segments, $avoids, $junctions);
        $attachments = []; $endNodes = []; $connectorEdges = [];
        foreach ([[$startLat, $startLng], [$endLat, $endLng]] as $idx => [$lat, $lng]) {
            $best = null; $distance = INF;
            foreach ($graph['edges'] as $edgeIdx => $edge) {
                [$a, $b] = $edge['coordinates'];
                $d = $this->distancePointToSegmentMeters($lat, $lng, ...array_merge($a, $b));
                if ($d >= $distance) continue;
                $t = $this->projectionParameter([$lat, $lng], $a, $b);
                $p = [$a[0] + ($b[0] - $a[0]) * $t, $a[1] + ($b[1] - $a[1]) * $t];
                // Never snap across a user-drawn forbidden area or through a closed junction.
                $edgeAvoids = array_values(array_filter($avoids, fn ($avoid) => empty($avoid['segment_uuids']) || in_array($edge['parent_uuid'] ?? $edge['segment_uuid'], $avoid['segment_uuids'], true)));
                if ($this->edgeBlockedByAvoids([$lat, $lng], $p, $edgeAvoids)) continue;
                $best = compact('edgeIdx', 'edge', 't', 'p'); $distance = $d;
            }
            if (!$best || $distance > self::MAX_ENDPOINT_SNAP_METERS) return null;
            extract($best);
            $key = $t < 1e-8 ? $edge['from'] : ($t > 1 - 1e-8 ? $edge['to'] : 'projection:' . $edgeIdx . ':' . sprintf('%.9f', $t));
            $attachments[$edgeIdx][] = ['t' => $t, 'key' => $key];
            $graph['nodes'][$key] ??= ['lat' => $p[0], 'lng' => $p[1]];
            $endNodes[$idx] = $key;
            if ($distance > 0.1) {
                $endpointKey = 'endpoint:' . $idx;
                $uuid = $idx === 0 ? self::CONNECTOR_START_UUID : self::CONNECTOR_END_UUID;
                $coords = $idx === 0 ? [[$lat, $lng], $p] : [$p, [$lat, $lng]];
                $graph['nodes'][$endpointKey] = ['lat' => $lat, 'lng' => $lng];
                $connectorEdges[] = ['from' => $idx === 0 ? $endpointKey : $key, 'to' => $idx === 0 ? $key : $endpointKey,
                    'length' => $distance, 'weight' => $distance, 'coordinates' => $coords, 'segment_uuid' => $uuid];
                $segments[] = ['uuid' => $uuid, 'name' => $idx === 0 ? '前往道路入口' : '驶离道路', 'status' => 'open'];
                $endNodes[$idx] = $endpointKey;
            }
        }
        $newEdges = [];
        foreach ($graph['edges'] as $idx => $edge) {
            $cuts = array_merge([['t' => 0, 'key' => $edge['from']], ['t' => 1, 'key' => $edge['to']]], $attachments[$idx] ?? []);
            usort($cuts, fn ($a, $b) => $a['t'] <=> $b['t']);
            for ($i = 1; $i < count($cuts); $i++) {
                $a = $cuts[$i - 1]; $b = $cuts[$i];
                if ($b['t'] - $a['t'] < 1e-9) continue;
                $from = $graph['nodes'][$a['key']]; $to = $graph['nodes'][$b['key']];
                $newEdges[] = array_merge($edge, ['from' => $a['key'], 'to' => $b['key'],
                    'length' => $edge['length'] * ($b['t'] - $a['t']), 'weight' => $edge['weight'] * ($b['t'] - $a['t']),
                    'coordinates' => [[$from['lat'], $from['lng']], [$to['lat'], $to['lng']]]]);
            }
        }
        $graph['edges'] = array_merge($newEdges, $connectorEdges);
        $graph['adjacency'] = $this->buildAdjacency($graph['edges']);
        $path = $this->dijkstra($graph, $endNodes[0], $endNodes[1]);
        return $path ? $this->pathToRoute($graph, $path, $segments) : null;
    }

    /** Discover T/X/shared endpoint junctions even when a connected road is closed. */
    public function junctions(array $segments): array
    {
        return $this->topology($segments)['junctions'];
    }
}
