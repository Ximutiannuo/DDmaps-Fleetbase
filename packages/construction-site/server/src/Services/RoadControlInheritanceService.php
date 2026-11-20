<?php

namespace Fleetbase\ConstructionSite\Services;

use Fleetbase\ConstructionSite\Models\RoadTrafficControl;

class RoadControlInheritanceService
{
    /** Adding a junction must not silently release a manual closure on the original section. */
    public function inheritControls(string $company, array $before, array $after, SiteRoadGraphService $graph): void
    {
        $controls = RoadTrafficControl::where('company_uuid', $company)->lockForUpdate()->get()->keyBy('target_key');
        $oldSections = array_column($before['road_segments'], null, 'target_key');
        $oldJunctions = array_column($before['junctions'], null, 'target_key');
        foreach (array_merge($after['road_segments'], $after['junctions']) as $target) {
            if (isset($controls[$target['target_key']])) continue;
            foreach ($controls as $oldKey => $control) {
                if (isset($target['road_segment_uuid'])) {
                    // A restored parent may have been absent from the previous topology.
                    $old = $oldSections[$oldKey] ?? ($control->road_segment_uuid && ($control->geometry['type'] ?? '') === 'LineString'
                        ? ['road_segment_uuid' => $control->road_segment_uuid, 'geometry' => $control->geometry] : null);
                    if (!$old || $old['road_segment_uuid'] !== $target['road_segment_uuid']) continue;
                    $oldCoords = $old['geometry']['coordinates'];
                    $points = $target['geometry']['coordinates'];
                    // Endpoints plus edge midpoints exclude sections spanning an unrelated branch.
                    foreach ($target['geometry']['coordinates'] as $i => $p) if ($i > 0) {
                        $q = $target['geometry']['coordinates'][$i - 1];
                        $points[] = [($p[0] + $q[0]) / 2, ($p[1] + $q[1]) / 2];
                    }
                    $matches = true;
                    foreach ($points as $p) {
                        $distance = INF;
                        for ($i = 1; $i < count($oldCoords); $i++) {
                            $a = $oldCoords[$i - 1]; $b = $oldCoords[$i];
                            $distance = min($distance, $graph->distancePointToSegmentMeters($p[1], $p[0], $a[1], $a[0], $b[1], $b[0]));
                        }
                        if ($distance > 1) { $matches = false; break; }
                    }
                    if (!$matches) continue;
                } else {
                    $old = $oldJunctions[$oldKey] ?? null;
                    if (!$old || (string) ($old['layer'] ?? '0') !== (string) ($target['layer'] ?? '0')
                        || !array_intersect($old['segment_uuids'], $target['segment_uuids'])
                        || $graph->haversineMeters($old['latitude'], $old['longitude'], $target['latitude'], $target['longitude']) > 0.5) continue;
                }
                $attributes = array_intersect_key($control->toArray(), array_flip($control->getFillable()));
                $attributes['target_key'] = $target['target_key'];
                $attributes['geometry'] = $target['geometry'];
                RoadTrafficControl::create($attributes);
                break;
            }
        }
    }
}
