<?php

namespace Fleetbase\ConstructionSite\Database\Seeders;

use Fleetbase\ConstructionSite\Models\Gate;
use Fleetbase\ConstructionSite\Models\RoadCondition;
use Fleetbase\ConstructionSite\Models\RoadSegment;
use Fleetbase\ConstructionSite\Models\SiteRoad;
use Illuminate\Database\Seeder;

class ConstructionSiteSeeder extends Seeder
{
    public function run(): void
    {
        $mainRoad = SiteRoad::create([
            'name'      => '主干道',
            'road_type' => 'main',
            'status'    => 'open',
            'geometry'  => [
                'type'        => 'LineString',
                'coordinates' => [
                    [116.404, 39.915],
                    [116.408, 39.917],
                    [116.412, 39.915],
                ],
            ],
        ]);

        $branchRoad = SiteRoad::create([
            'name'      => '卸料通道A',
            'road_type' => 'haul_road',
            'status'    => 'open',
            'geometry'  => [
                'type'        => 'LineString',
                'coordinates' => [
                    [116.408, 39.917],
                    [116.409, 39.919],
                ],
            ],
        ]);

        $seg1 = RoadSegment::create([
            'site_road_uuid' => $mainRoad->uuid,
            'name'           => 'K0+000~K0+100',
            'sequence'       => 1,
            'status'         => 'open',
            'geometry'       => [
                'type'        => 'LineString',
                'coordinates' => [[116.404, 39.915], [116.408, 39.917]],
            ],
        ]);

        $seg2 = RoadSegment::create([
            'site_road_uuid' => $mainRoad->uuid,
            'name'           => 'K0+100~K0+200',
            'sequence'       => 2,
            'status'         => 'open',
            'geometry'       => [
                'type'        => 'LineString',
                'coordinates' => [[116.408, 39.917], [116.412, 39.915]],
            ],
        ]);

        $seg3 = RoadSegment::create([
            'site_road_uuid' => $mainRoad->uuid,
            'name'           => 'K0+200~K0+300',
            'sequence'       => 3,
            'status'         => 'construction',
            'geometry'       => [
                'type'        => 'LineString',
                'coordinates' => [[116.412, 39.915], [116.415, 39.916]],
            ],
        ]);

        Gate::create([
            'name'      => '东门',
            'gate_type' => 'entrance',
            'status'    => 'open',
            'location'  => ['type' => 'Point', 'coordinates' => [116.412, 39.915]],
        ]);

        Gate::create([
            'name'      => '西门',
            'gate_type' => 'exit',
            'status'    => 'open',
            'location'  => ['type' => 'Point', 'coordinates' => [116.404, 39.915]],
        ]);

        RoadCondition::create([
            'road_segment_uuid' => $seg3->uuid,
            'status'            => 'construction',
            'surface_condition' => 'dry',
            'congestion'        => 'light',
            'note'              => '路面施工中，请减速慢行',
            'reported_at'       => now(),
        ]);

        RoadCondition::create([
            'road_segment_uuid' => $seg1->uuid,
            'status'            => 'open',
            'surface_condition' => 'dry',
            'congestion'        => 'none',
            'note'              => '路况良好',
            'reported_at'       => now(),
        ]);
    }
}
