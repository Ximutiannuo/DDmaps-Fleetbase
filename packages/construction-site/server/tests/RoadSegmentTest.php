<?php

use Fleetbase\ConstructionSite\Models\RoadCondition;
use Fleetbase\ConstructionSite\Models\RoadSegment;
use Fleetbase\ConstructionSite\Models\SiteRoad;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can create a road segment', function () {
    $segment = RoadSegment::factory()->create(['name' => 'Segment A']);

    expect($segment->name)->toBe('Segment A')
        ->and($segment->uuid)->toBeString()
        ->and($segment->public_id)->toBeString();
});

it('casts sequence to an integer and geometry to an array', function () {
    $segment = RoadSegment::factory()->create([
        'sequence' => '7',
        'geometry' => ['type' => 'LineString', 'coordinates' => [[2, 2], [3, 3]]],
    ]);

    expect($segment->sequence)->toBeInt()
        ->and($segment->sequence)->toBe(7)
        ->and($segment->geometry)->toBeArray();
});

it('belongs to a site road and has road conditions', function () {
    $road = SiteRoad::factory()->create();
    $segment = RoadSegment::factory()->create(['site_road_uuid' => $road->uuid]);
    RoadCondition::factory()->count(2)->create(['road_segment_uuid' => $segment->uuid]);

    expect($segment->siteRoad)->toBeInstanceOf(SiteRoad::class)
        ->and($segment->siteRoad->uuid)->toBe($road->uuid)
        ->and($segment->roadConditions)->toHaveCount(2);
});
