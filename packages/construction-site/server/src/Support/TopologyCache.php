<?php
namespace Fleetbase\ConstructionSite\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class TopologyCache
{
    public function remember(array $segments, array $history, callable $build): array
    {
        // A transaction may see geometry that is never committed. Never publish that into Redis.
        if (DB::transactionLevel() > 0) return $build();
        $key = 'construction-site:topology:v1:' . hash('sha256', json_encode([$segments, $history], JSON_THROW_ON_ERROR));
        return Cache::remember($key, 300, $build);
    }
}
