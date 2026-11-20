<?php
namespace Fleetbase\ConstructionSite\Support;
use Fleetbase\FleetOps\Models\ServiceArea;
trait ServiceAreaGeometry
{
    protected function geometryCentroidInsideServiceArea(array $geometry, ServiceArea $area): bool
    {
        $centroid = $this->geometryCentroid($geometry);
        if ($centroid === null) {
            return true;
        }

        $border = $area->border ?? null;
        if (!$border) {
            return true;
        }

        $geoJson = is_array($border) ? $border : null;
        if (!$geoJson && method_exists($border, 'toGeoJSON')) {
            $geoJson = json_decode($border->toGeoJSON(), true);
        }
        if (!$geoJson && method_exists($border, 'toJson')) {
            $geoJson = json_decode($border->toJson(), true);
        }
        if (!$geoJson || empty($geoJson['coordinates'])) {
            return true;
        }

        $ring = null;
        $coords = $geoJson['coordinates'];
        if (($geoJson['type'] ?? null) === 'MultiPolygon' && isset($coords[0][0])) {
            $ring = $coords[0][0];
        } elseif (isset($coords[0][0]) && is_array($coords[0][0])) {
            $ring = $coords[0];
        }

        if (!$ring) {
            return true;
        }

        return $this->pointInRing($centroid['lat'], $centroid['lng'], $ring);
    }

    /**
     * @return array{lat:float,lng:float}|null
     */
    protected function geometryCentroid(array $geometry): ?array
    {
        $type = $geometry['type'] ?? null;
        $coords = $geometry['coordinates'] ?? [];
        $points = [];

        if ($type === 'LineString') {
            $points = $coords;
        } elseif ($type === 'Polygon') {
            $points = $coords[0] ?? [];
        }

        if ($points === []) {
            return null;
        }

        $sumLat = 0.0;
        $sumLng = 0.0;
        $count = 0;
        foreach ($points as $point) {
            if (!is_array($point) || count($point) < 2) {
                continue;
            }
            $sumLng += (float) $point[0];
            $sumLat += (float) $point[1];
            $count++;
        }

        if ($count === 0) {
            return null;
        }

        return ['lat' => $sumLat / $count, 'lng' => $sumLng / $count];
    }

    /**
     * @param  array<int, array{0:float,1:float}>  $ring
     */
    protected function pointInRing(float $lat, float $lng, array $ring): bool
    {
        $inside = false;
        $n = count($ring);
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
    protected function roadInsideArea(array $geometry, ServiceArea $area): bool
    {
        $point = $this->geometryCentroid($geometry);
        return $point !== null && $this->pointInsideArea(['type' => 'Point', 'coordinates' => [$point['lng'], $point['lat']]], $area);
    }

    protected function pointInsideArea(array $geometry, ServiceArea $area): bool
    {
        $border = $area->border;
        if (!$border) return true;
        if (!is_array($border)) {
            $border = json_decode(method_exists($border, 'toGeoJSON') ? $border->toGeoJSON() : $border->toJson(), true);
        }
        if (!$border) return false;
        $polygons = ($border['type'] ?? '') === 'MultiPolygon' ? $border['coordinates'] : [$border['coordinates']];
        [$lng, $lat] = $geometry['coordinates'];
        foreach ($polygons as $rings) {
            if (!$this->pointInRing($lat, $lng, $rings[0])) continue;
            $hole = false;
            foreach (array_slice($rings, 1) as $ring) {
                if ($this->pointInRing($lat, $lng, $ring)) $hole = true;
            }
            if (!$hole) return true;
        }
        return false;
    }
}
