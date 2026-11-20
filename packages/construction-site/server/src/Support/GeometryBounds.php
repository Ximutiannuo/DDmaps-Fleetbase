<?php
namespace Fleetbase\ConstructionSite\Support;

final class GeometryBounds
{
    public static function line(array $points): ?array
    {
        if (!$points) return null;
        return [min(array_column($points, 0)), min(array_column($points, 1)), max(array_column($points, 0)), max(array_column($points, 1))];
    }

    public static function near(?array $a, ?array $b, float $meters): bool
    {
        if (!$a || !$b) return false;
        $lat = $meters / 110000;
        $extreme = max(abs($a[1]), abs($a[3]), abs($b[1]), abs($b[3]));
        $lng = $extreme > 89.9 ? 360 : $lat / cos(deg2rad($extreme));
        return $a[0] <= $b[2] + $lng && $a[2] >= $b[0] - $lng && $a[1] <= $b[3] + $lat && $a[3] >= $b[1] - $lat;
    }
}
