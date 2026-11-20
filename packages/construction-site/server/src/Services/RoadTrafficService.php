<?php

namespace Fleetbase\ConstructionSite\Services;

use Fleetbase\ConstructionSite\Models\EntryRegistration;
use Fleetbase\ConstructionSite\Models\RoadSegment;
use Fleetbase\ConstructionSite\Models\RoadTrafficControl;

class RoadTrafficService
{
    public const FRESH_SECONDS = 90;
    public const MATCH_METERS = 25;
    public const JUNCTION_METERS = 30;
    public const MULTIPLIERS = ['unknown' => 1.0, 'none' => 1.0, 'light' => 1.3, 'moderate' => 2.0, 'heavy' => 4.0, 'standstill' => 5.0];

    public function __construct(private SiteRoadGraphService $graph, private ?\Fleetbase\ConstructionSite\Support\TopologyCache $topologyCache = null)
    {
    }

    /** GPS speed is metres/second. Counts alone can classify traffic; speed only supplements >=3 samples. */
    public function classify(array $drivers, array $control = []): array
    {
        $observations = $drivers;
        $drivers = array_values(array_filter($drivers, fn ($driver) => ($driver['is_fresh'] ?? true) === true));
        $count = count($drivers);
        $light = (int) ($control['light_threshold'] ?? 3);
        $moderate = (int) ($control['moderate_threshold'] ?? 6);
        $heavy = (int) ($control['heavy_threshold'] ?? 10);
        $level = $count >= $heavy ? 'heavy' : ($count >= $moderate ? 'moderate' : ($count >= $light ? 'light' : 'none'));
        if ($count === 0) $level = 'unknown';
        $speeds = array_values(array_filter(array_column($drivers, 'speed'), fn ($speed) => $speed !== null && is_numeric($speed) && $speed >= 0));
        $average = count($speeds) ? array_sum($speeds) / count($speeds) : null;
        if ($count >= $light && count($speeds) >= 3) {
            if ($average <= 0.5) {
                $level = 'standstill';
            } elseif ($average <= 2 && self::MULTIPLIERS[$level] < self::MULTIPLIERS['heavy']) {
                $level = 'heavy';
            }
        }
        $automatic = $level;
        $mode = $control['congestion_mode'] ?? 'auto';
        if ($mode === 'manual') {
            $level = $control['manual_congestion'] ?? 'none';
        }
        $dataStatus = $count > 0 ? 'observed' : (count($observations) > 0 ? 'stale' : 'no_data');
        $labels = ['unknown' => '数据不足', 'none' => '通畅', 'light' => '轻微拥堵', 'moderate' => '中度拥堵', 'heavy' => '严重拥堵', 'standstill' => '停滞'];
        $times = array_filter(array_column($observations, 'observed_at'));
        return [
            'data_status' => $dataStatus,
            'data_status_label' => ['observed' => '有效定位', 'stale' => '定位已过期', 'no_data' => '暂无有效定位'][$dataStatus],
            'last_observed_at' => $times ? max($times) : null,
            'driver_count' => $count, 'average_speed_kmh' => $average === null ? null : round($average * 3.6, 1),
            'congestion' => $level, 'automatic_congestion' => $automatic,
            'congestion_label' => $labels[$level], 'congestion_mode' => $mode,
            'traffic_multiplier' => self::MULTIPLIERS[$level],
            'light_threshold' => $light, 'moderate_threshold' => $moderate, 'heavy_threshold' => $heavy,
            'manual_congestion' => $control['manual_congestion'] ?? 'none', 'note' => $control['note'] ?? '',
        ];
    }

    /** Assign each driver to one nearest road; never count one vehicle on two parallel roads. */
    public function assess(array $segments, array $drivers, array $controls = []): array
    {
        $removed = array_values(array_filter($controls, fn ($c) => !empty($c['is_removed'])));
        // Keep edited section boundaries when a crossing road disappears or is restored.
        // Merging across an old closure would otherwise make the closure disappear.
        $history = array_values(array_filter($controls, fn ($c) => !empty($c['removal_origin_key']) || !empty($c['is_removed'])
            || (str_starts_with($c['target_key'] ?? '', 'section:') && ($c['geometry']['type'] ?? '') === 'LineString')));
        $build = fn () => $this->graph->topology($segments, $history);
        $topology = $this->topologyCache ? $this->topologyCache->remember($segments, $history, $build) : $build();
        $segments = $topology['road_segments'];
        foreach ($segments as &$segment) {
            $segment['is_removed'] = false;
            foreach ($removed as $control) {
                if ($control['road_segment_uuid'] === $segment['road_segment_uuid']
                    && ($control['target_key'] === $segment['target_key'] || $this->geometryCoveredBy($segment['geometry'], $control['geometry']))) {
                    $segment['is_removed'] = true;
                    break;
                }
            }
            // Preserve exact shared nodes when removing pieces; a 25m snap must not bridge a gap.
            if ($history) $segment['routing'] = array_merge($segment['routing'] ?? [], ['node_keys' => $segment['topology_node_keys']]);
            unset($segment['topology_node_keys']);
        }
        unset($segment);
        $assigned = [];
        $seen = [];
        foreach ($drivers as $driver) {
            $identity = $driver['vehicle_uuid'] ?? $driver['driver_uuid'] ?? $driver['uuid'];
            if (isset($seen[$identity]) || !isset($driver['accuracy']) || $driver['accuracy'] < 0 || $driver['accuracy'] > 50) {
                continue;
            }
            $seen[$identity] = true;
            $nearest = null;
            $distance = self::MATCH_METERS;
            foreach ($segments as $segment) {
                if ($segment['is_removed']) continue;
                $coords = $segment['geometry']['coordinates'] ?? [];
                for ($i = 1; $i < count($coords); $i++) {
                    $d = $this->graph->distancePointToSegmentMeters($driver['lat'], $driver['lng'], $coords[$i - 1][1], $coords[$i - 1][0], $coords[$i][1], $coords[$i][0]);
                    if ($d < $distance) {
                        $distance = $d;
                        $nearest = $segment['uuid'];
                    }
                }
            }
            if ($nearest !== null) {
                $assigned[$nearest][] = $driver;
            }
        }
        $byKey = array_column($controls, null, 'target_key');
        foreach ($segments as &$segment) {
            $segment['status'] = $segment['status'] ?? 'open';
            $key = $segment['target_key'];
            // Existing whole-road configuration remains the default until a section is edited.
            $control = $byKey[$key] ?? null;
            if (!$control) foreach ($history as $previous) {
                if ($previous['road_segment_uuid'] === $segment['road_segment_uuid'] && $this->geometryCoveredBy($segment['geometry'], $previous['geometry'])) {
                    $control = $previous;
                    break;
                }
            }
            $control ??= $byKey['road:' . $segment['road_segment_uuid']] ?? [];
            if (str_starts_with($control['target_key'] ?? '', 'section:') && isset($control['status'])) $segment['status'] = $control['status'];
            if ($segment['is_removed']) $segment['status'] = 'closed';
            $segment = array_merge($segment, $this->classify($assigned[$segment['uuid']] ?? [], $control));
            $segment['target_key'] = $key;
            $segment['border'] = $segment['geometry'];
            $segment['color'] = $this->color($segment['status'], $segment['congestion']);
            $segment['status_label'] = $this->statusLabel($segment['status']);
        }
        unset($segment);
        $junctions = $topology['junctions'];
        if ($removed) {
            foreach ($junctions as &$junction) {
                $roads = []; $degree = 0;
                foreach ($segments as $segment) {
                    if ($segment['is_removed']) continue;
                    foreach (['start_junction_key', 'end_junction_key'] as $end) {
                        if ($segment[$end] === $junction['target_key']) { $degree++; $roads[] = $segment['road_segment_uuid']; }
                    }
                }
                $junction['segment_uuids'] = array_values(array_unique($roads));
                $names = array_column($segments, 'road_name', 'road_segment_uuid');
                $junction['name'] = implode(' / ', array_map(fn ($uuid) => $names[$uuid], $junction['segment_uuids']));
                $junction['active_degree'] = $degree;
            }
            unset($junction);
            $junctions = array_values(array_filter($junctions, fn ($j) => count($j['segment_uuids']) >= 2 || $j['active_degree'] > 2));
            $junctionKeys = array_column($junctions, null, 'target_key');
            foreach ($segments as &$segment) foreach (['start', 'end'] as $end) {
                $key = $segment[$end . '_junction_key'];
                if ($key && !isset($junctionKeys[$key])) { $segment[$end . '_junction_key'] = null; $segment[$end . '_label'] = '道路端点'; }
                elseif ($key) $segment[$end . '_label'] = $junctionKeys[$key]['name'] . '交汇点';
            }
            unset($segment);
        }
        foreach ($junctions as &$junction) {
            $control = $byKey[$junction['target_key']] ?? [];
            $nearby = [];
            $dedup = [];
            foreach ($drivers as $driver) {
                $identity = $driver['vehicle_uuid'] ?? $driver['driver_uuid'] ?? $driver['uuid'];
                if (isset($dedup[$identity]) || !isset($driver['accuracy']) || $driver['accuracy'] < 0 || $driver['accuracy'] > 50) continue;
                if ($this->graph->haversineMeters($driver['lat'], $driver['lng'], $junction['latitude'], $junction['longitude']) <= self::JUNCTION_METERS) {
                    $nearby[] = $driver;
                    $dedup[$identity] = true;
                }
            }
            $junction = array_merge($junction, $this->classify($nearby, $control));
            $junction['status'] = $control['status'] ?? 'open';
            $junction['status_label'] = $this->statusLabel($junction['status']);
            $junction['service_area_uuid'] = $control['service_area_uuid'] ?? null;
            $junction['color'] = $this->color($junction['status'], $junction['congestion']);
            $junction['delay_seconds'] = match ($junction['congestion']) {
                'light' => 10, 'moderate' => 30, 'heavy' => 60, 'standstill' => 120, default => 0,
            };
            if ($junction['status'] === 'partial') $junction['delay_seconds'] += 30;
        }
        return ['road_segments' => $segments, 'junctions' => $junctions];
    }

    /** A future subdivision stays removed when all its vertices and midpoints lie in the old section. */
    private function geometryCoveredBy(array $geometry, array $removedGeometry): bool
    {
        $old = $removedGeometry['coordinates'] ?? [];
        $points = $geometry['coordinates'] ?? [];
        if (count($old) < 2 || count($points) < 2) return false;
        foreach ($geometry['coordinates'] as $i => $p) if ($i > 0) {
            $q = $geometry['coordinates'][$i - 1]; $points[] = [($p[0] + $q[0]) / 2, ($p[1] + $q[1]) / 2];
        }
        foreach ($points as $p) {
            $distance = INF;
            for ($i = 1; $i < count($old); $i++) $distance = min($distance,
                $this->graph->distancePointToSegmentMeters($p[1], $p[0], $old[$i - 1][1], $old[$i - 1][0], $old[$i][1], $old[$i][0]));
            if ($distance > 1) return false;
        }
        return true;
    }

    public function snapshot(string $company): array
    {
        $segments = RoadSegment::where('company_uuid', $company)->orderBy('uuid')->get(['uuid', 'name', 'status', 'geometry', 'source', 'source_key', 'source_meta', 'routing'])
            ->filter(function ($segment) {
                $geometry = $segment->geometry;
                if (!is_array($geometry) || ($geometry['type'] ?? '') !== 'LineString' || count($geometry['coordinates'] ?? []) < 2) return false;
                foreach ($geometry['coordinates'] as $point) {
                    if (!is_array($point) || count($point) < 2 || !is_numeric($point[0]) || !is_numeric($point[1])
                        || abs((float) $point[0]) > 180 || abs((float) $point[1]) > 90) return false;
                }
                return true;
            })->values()->toArray();
        $drivers = EntryRegistration::where('company_uuid', $company)->where('status', 'active')
            ->whereNull('exited_at')->where('tracking_enabled', true)
            ->whereNotNull('gps_reported_at')
            ->whereNotNull('gps_latitude')->orderByRaw('COALESCE(gps_observed_at, gps_reported_at) DESC')->orderByDesc('gps_reported_at')
            ->get(['uuid', 'vehicle_uuid', 'driver_uuid', 'gps_latitude', 'gps_longitude', 'gps_speed', 'gps_accuracy', 'gps_observed_at', 'gps_reported_at'])
            ->map(fn ($entry) => [
                'uuid' => $entry->uuid, 'vehicle_uuid' => $entry->vehicle_uuid, 'driver_uuid' => $entry->driver_uuid,
                'lat' => (float) $entry->gps_latitude, 'lng' => (float) $entry->gps_longitude,
                'speed' => $entry->gps_speed, 'accuracy' => $entry->gps_accuracy,
                'is_fresh' => ($entry->gps_observed_at ?? $entry->gps_reported_at)->gte(now()->subSeconds(self::FRESH_SECONDS)),
                'observed_at' => ($entry->gps_observed_at ?? $entry->gps_reported_at)->toIso8601String(),
            ])->all();
        $result = $this->assess($segments, $drivers, RoadTrafficControl::where('company_uuid', $company)->get()->toArray());
        $result['generated_at'] = now()->toIso8601String();
        $result['fresh_seconds'] = self::FRESH_SECONDS;
        $result['revision'] = sha1(json_encode([
            array_map(fn ($s) => [$s['uuid'], $s['name'], $s['status'], $s['congestion'], $s['is_removed'], $s['geometry'], $s['routing'] ?? null], $result['road_segments']),
            array_map(fn ($j) => [$j['target_key'], $j['name'], $j['status'], $j['congestion']], $result['junctions']),
        ]));
        return $result;
    }

    private function color(string $status, string $congestion): string
    {
        return match ($status) {
            'closed' => '#ef4444', 'construction' => '#f97316', 'partial' => '#eab308',
            default => match ($congestion) {
                'unknown' => '#94a3b8', 'light' => '#eab308', 'moderate' => '#f97316', 'heavy', 'standstill' => '#dc2626', default => '#22c55e',
            },
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'closed' => '封闭', 'construction' => '施工禁行', 'partial' => '部分封闭', default => '可通行',
        };
    }
}
