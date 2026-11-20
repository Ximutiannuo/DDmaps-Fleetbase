<?php

use Fleetbase\ConstructionSite\Models\RoadSegment;
use Fleetbase\ConstructionSite\Models\SiteRoad;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can create a site road', function () {
    $road = SiteRoad::factory()->create(['name' => 'Main Haul Road']);

    expect($road->name)->toBe('Main Haul Road')
        ->and($road->uuid)->toBeString()
        ->and($road->public_id)->toBeString();
});

it('casts geometry to an array', function () {
    $road = SiteRoad::factory()->create([
        'geometry' => ['type' => 'LineString', 'coordinates' => [[0, 0], [1, 1]]],
    ]);

    expect($road->geometry)->toBeArray()
        ->and($road->geometry['type'])->toBe('LineString');
});

it('has many road segments', function () {
    $road = SiteRoad::factory()->create();
    RoadSegment::factory()->count(2)->create(['site_road_uuid' => $road->uuid]);

    expect($road->roadSegments)->toHaveCount(2)
        ->and($road->roadSegments->first())->toBeInstanceOf(RoadSegment::class);
});
