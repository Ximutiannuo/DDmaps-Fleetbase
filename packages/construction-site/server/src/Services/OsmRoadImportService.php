<?php

namespace Fleetbase\ConstructionSite\Services;

use InvalidArgumentException;

/** Converts OSM ways into bounded road candidates without inferring connections from pixels. */
class OsmRoadImportService
{
    public const HIGHWAYS = ['motorway', 'motorway_link', 'trunk', 'trunk_link', 'primary', 'primary_link',
        'secondary', 'secondary_link', 'tertiary', 'tertiary_link', 'unclassified', 'residential', 'living_street', 'service', 'road', 'track', 'construction'];

    public function __construct(private SiteRoadGraphService $graph)
    {
    }

    public function polygons(array $border): array
    {
        $polygons = match ($border['type'] ?? '') {
            'Polygon' => [$border['coordinates'] ?? []],
            'MultiPolygon' => $border['coordinates'] ?? [],
            default => [],
        };
        if (!$polygons) throw new InvalidArgumentException('当前 GCC 尚未设置有效的多边形边界，请先设置区域边界。');
        foreach ($polygons as $polygon) {
            if (!$polygon) throw new InvalidArgumentException('GCC 边界无效。');
            foreach ($polygon as $ring) {
                if (count($ring) < 4 || $ring[0] !== end($ring)) throw new InvalidArgumentException('GCC 边界必须是闭合多边形。');
                foreach ($ring as $p) {
                    if (count($p) < 2 || !is_numeric($p[0]) || !is_numeric($p[1]) || abs($p[0]) > 180 || abs($p[1]) > 85) {
                        throw new InvalidArgumentException('GCC 边界坐标无效。');
                    }
                }
            }
        }
        return $polygons;
    }

    public function bbox(array $border): array
    {
        $points = [];
        foreach ($this->polygons($border) as $polygon) foreach ($polygon as $ring) $points = array_merge($points, $ring);
        return [min(array_column($points, 1)), min(array_column($points, 0)), max(array_column($points, 1)), max(array_column($points, 0))];
    }

    public function query(array $bbox): string
    {
        // A small halo also retrieves roads whose next OSM vertex lies just outside the boundary.
        $halo = 0.002;
        $bounds = implode(',', [$bbox[0] - $halo, $bbox[1] - $halo, $bbox[2] + $halo, $bbox[3] + $halo]);
        $types = implode('|', self::HIGHWAYS);
        return '[out:json][timeout:35][maxsize:64000000];way["highway"~"^(' . $types . ')$"](' . $bounds . ');out body geom;';
    }

    public function candidates(array $osm, array $border, string $areaUuid): array
    {
        $polygons = $this->polygons($border);
        $result = [];
        foreach ($osm['elements'] ?? [] as $way) {
            $tags = $way['tags'] ?? [];
            if (($way['type'] ?? '') !== 'way' || !in_array($tags['highway'] ?? '', self::HIGHWAYS, true)) continue;
            $coords = array_map(fn ($p) => [(float) $p['lon'], (float) $p['lat']], $way['geometry'] ?? []);
            $nodeIds = $way['nodes'] ?? [];
            if (count($coords) < 2 || count($nodeIds) !== count($coords)) continue;
            $access = $tags['motor_vehicle'] ?? $tags['vehicle'] ?? $tags['access'] ?? 'yes';
            if (in_array($access, ['no', 'agricultural', 'forestry'], true) || ($tags['area'] ?? '') === 'yes') continue;
            $restricted = !in_array($access, ['yes', 'permissive', 'designated'], true) || ($tags['hgv'] ?? '') === 'no';
            foreach (['maxheight', 'maxwidth', 'maxweight', 'maxaxleload', 'hgv:conditional'] as $restriction) if (isset($tags[$restriction])) $restricted = true;
            if (isset($tags['hgv']) && !in_array($tags['hgv'], ['yes', 'permissive', 'designated'], true)) $restricted = true;
            $oneway = strtolower($tags['oneway:motor_vehicle'] ?? $tags['oneway:vehicle'] ?? $tags['oneway'] ?? ((($tags['junction'] ?? '') === 'roundabout' || $tags['highway'] === 'motorway') ? 'yes' : 'no'));
            if (!in_array($oneway, ['yes', '1', 'true', '-1', 'reverse', 'no', '0', 'false'], true)) $restricted = true;
            $direction = match ($oneway) {
                'yes', '1', 'true' => 1, '-1', 'reverse' => -1, default => 0,
            };
            // Conditional restrictions cannot safely be reduced to a permanent one-way value.
            $restricted = $restricted || isset($tags['oneway:conditional']) || isset($tags['access:conditional']) || isset($tags['motor_vehicle:conditional']);
            $layer = (string) ($tags['layer'] ?? ((($tags['bridge'] ?? 'no') !== 'no') ? '1' : ((($tags['tunnel'] ?? 'no') !== 'no') ? '-1' : '0')));
            foreach ($this->clip($coords, $nodeIds, (string) $way['id'], $polygons) as $fragment) {
                $length = 0;
                for ($i = 1; $i < count($fragment['coordinates']); $i++) {
                    [$a, $b] = [$fragment['coordinates'][$i - 1], $fragment['coordinates'][$i]];
                    $length += $this->graph->haversineMeters($a[1], $a[0], $b[1], $b[0]);
                }
                if ($length < 2) continue;
                $ends = [$fragment['node_keys'][0], end($fragment['node_keys'])];
                sort($ends);
                $key = 'osm:' . sha1($areaUuid . ':' . $way['id'] . ':' . implode(':', $ends));
                $result[$key] = [
                    'key' => $key, 'source' => 'osm', 'source_key' => $key,
                    'name' => mb_substr($tags['name:zh'] ?? $tags['name'] ?? $tags['ref'] ?? ('OSM 道路 ' . $way['id']), 0, 190),
                    'status' => ($tags['highway'] === 'construction') ? 'construction' : 'open',
                    'highway' => $tags['highway'], 'length_meters' => round($length, 1), 'restricted' => $restricted,
                    'geometry' => ['type' => 'LineString', 'coordinates' => $fragment['coordinates']],
                    'routing' => ['node_keys' => $fragment['node_keys'], 'direction' => $direction, 'layer' => $layer, 'access' => $access],
                    'source_meta' => ['way_id' => $way['id'], 'service_area_uuid' => $areaUuid, 'tags' => $tags],
                    'availability' => 'new', 'availability_label' => $restricted ? '需核实车辆通行权限，导入后默认封闭' : '可导入',
                ];
            }
        }
        return array_values($result);
    }

    private function inside(array $p, array $polygons): bool
    {
        foreach ($polygons as $polygon) {
            if (!$this->graph->pointInPolygonRing($p[1], $p[0], $polygon[0])) continue;
            $inHole = false;
            foreach (array_slice($polygon, 1) as $hole) if ($this->graph->pointInPolygonRing($p[1], $p[0], $hole)) $inHole = true;
            if (!$inHole) return true;
        }
        return false;
    }

    private function clip(array $coords, array $ids, string $way, array $polygons): array
    {
        $fragments = [];
        $current = null;
        for ($i = 1; $i < count($coords); $i++) {
            $a = $coords[$i - 1]; $b = $coords[$i];
            $dx = $b[0] - $a[0]; $dy = $b[1] - $a[1];
            $cuts = [0.0, 1.0];
            foreach ($polygons as $polygon) foreach ($polygon as $ring) {
                for ($j = 1; $j < count($ring); $j++) {
                    $c = $ring[$j - 1]; $d = $ring[$j];
                    $ex = $d[0] - $c[0]; $ey = $d[1] - $c[1];
                    $den = $dx * $ey - $dy * $ex;
                    if (abs($den) < 1e-15) continue;
                    $t = (($c[0] - $a[0]) * $ey - ($c[1] - $a[1]) * $ex) / $den;
                    $u = (($c[0] - $a[0]) * $dy - ($c[1] - $a[1]) * $dx) / $den;
                    if ($t > 0 && $t < 1 && $u >= 0 && $u <= 1) $cuts[] = $t;
                }
            }
            sort($cuts);
            $cuts = array_values(array_unique($cuts, SORT_REGULAR));
            for ($j = 1; $j < count($cuts); $j++) {
                $t0 = $cuts[$j - 1]; $t1 = $cuts[$j];
                $mid = ($t0 + $t1) / 2;
                if (!$this->inside([$a[0] + $dx * $mid, $a[1] + $dy * $mid], $polygons)) {
                    if ($current) $fragments[] = $current;
                    $current = null;
                    continue;
                }
                $p = [$a[0] + $dx * $t0, $a[1] + $dy * $t0];
                $q = [$a[0] + $dx * $t1, $a[1] + $dy * $t1];
                $nodeKey = fn ($t, $p) => $t < 1e-9 ? 'osm-node:' . $ids[$i - 1] : ($t > 1 - 1e-9 ? 'osm-node:' . $ids[$i] : 'osm-boundary:' . $way . ':' . sprintf('%.8f,%.8f', $p[0], $p[1]));
                if ($current && abs(end($current['coordinates'])[0] - $p[0]) + abs(end($current['coordinates'])[1] - $p[1]) > 1e-8) {
                    $fragments[] = $current; $current = null;
                }
                if (!$current) $current = ['coordinates' => [$p], 'node_keys' => [$nodeKey($t0, $p)]];
                if (abs($p[0] - $q[0]) + abs($p[1] - $q[1]) > 1e-10) {
                    $current['coordinates'][] = $q;
                    $current['node_keys'][] = $nodeKey($t1, $q);
                }
            }
        }
        if ($current) $fragments[] = $current;
        return $fragments;
    }

    /** Conservative overlap review preserves all existing manual traffic controls. */
    public function markExisting(array $candidates, array $existing): array
    {
        $bounds = []; $sources = [];
        foreach ($existing as $index => $road) {
            $bounds[$index] = \Fleetbase\ConstructionSite\Support\GeometryBounds::line($road['geometry']['coordinates'] ?? []);
            if (!empty($road['source_key'])) $sources[$road['source_key']] ??= $road;
        }
        foreach ($candidates as &$candidate) {
            // Source identity takes precedence over any earlier overlapping road.
            // Re-evaluate cached previews against current database state.
            unset($candidate['existing_road_uuid'], $candidate['existing_road_name'], $candidate['existing_road_geometry']);
            $candidate['availability'] = 'new';
            $candidate['availability_label'] = $candidate['restricted'] ? '需核实车辆通行权限，导入后默认封闭' : '可导入';
            $sameSource = $sources[$candidate['key']] ?? null;
            if ($sameSource) {
                $deleted = !empty($sameSource['deleted_at']);
                $candidate['availability'] = $deleted ? 'deleted' : 'existing';
                $candidate['availability_label'] = $deleted ? '已删除，可恢复原道路' : '已导入，点击编辑路况／名称';
                $candidate['existing_road_uuid'] = $sameSource['uuid'] ?? null;
                $candidate['existing_road_name'] = $sameSource['name'] ?? $candidate['name'];
                if ($deleted) $candidate['existing_road_geometry'] = $sameSource['geometry'] ?? null;
                continue;
            }
            $candidateBounds = \Fleetbase\ConstructionSite\Support\GeometryBounds::line($candidate['geometry']['coordinates']);
            foreach ($existing as $index => $road) {
                if (!\Fleetbase\ConstructionSite\Support\GeometryBounds::near($candidateBounds, $bounds[$index], 20)) continue;
                if (!empty($road['deleted_at']) || (string) ($road['routing']['layer'] ?? '0') !== $candidate['routing']['layer']) continue;
                $line = $road['geometry']['coordinates'] ?? [];
                $candidateLine = $candidate['geometry']['coordinates'];
                $overlap = 0;
                for ($i = 1; $i < count($candidateLine); $i++) {
                    $a = $candidateLine[$i - 1]; $b = $candidateLine[$i];
                    $length = $this->graph->haversineMeters($a[1], $a[0], $b[1], $b[0]);
                    $samples = max(1, (int) ceil($length / 10));
                    for ($s = 0; $s < $samples; $s++) {
                        $t = ($s + 0.5) / $samples;
                        $p = [$a[0] + ($b[0] - $a[0]) * $t, $a[1] + ($b[1] - $a[1]) * $t];
                        for ($j = 1; $j < count($line); $j++) {
                            $c = $line[$j - 1]; $d = $line[$j];
                            $scale = cos(deg2rad($p[1]));
                            $v = [($b[0] - $a[0]) * $scale, $b[1] - $a[1]];
                            $w = [($d[0] - $c[0]) * $scale, $d[1] - $c[1]];
                            $den = hypot(...$v) * hypot(...$w);
                            $parallel = $den > 0 && abs(($v[0] * $w[0] + $v[1] * $w[1]) / $den) > 0.85;
                            $norm = $w[0] ** 2 + $w[1] ** 2;
                            $projection = $norm > 0 ? ((($p[0] - $c[0]) * $scale) * $w[0] + ($p[1] - $c[1]) * $w[1]) / $norm : -1;
                            if ($parallel && $projection >= 0 && $projection <= 1 && $this->graph->distancePointToSegmentMeters($p[1], $p[0], $c[1], $c[0], $d[1], $d[0]) <= 20) {
                                $overlap += $length / $samples; break;
                            }
                        }
                    }
                }
                if ($overlap >= min(15, $candidate['length_meters'] * 0.5)) {
                    $candidate['availability'] = 'overlap';
                    $candidate['availability_label'] = '与「' . ($road['name'] ?? '现有道路') . '」重叠，请先人工核对';
                    break;
                }
            }
        }
        return $candidates;
    }
}
