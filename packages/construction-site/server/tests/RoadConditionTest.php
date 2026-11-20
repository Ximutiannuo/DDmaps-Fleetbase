<?php

use Fleetbase\ConstructionSite\Models\RoadCondition;
use Fleetbase\ConstructionSite\Models\RoadSegment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can create a road condition', function () {
    $condition = RoadCondition::factory()->create(['surface_condition' => 'muddy']);

    expect($condition->surface_condition)->toBe('muddy')
        ->and($condition->uuid)->toBeString()
        ->and($condition->public_id)->toBeString();
});

it('casts reported_at to a datetime', function () {
    $condition = RoadCondition::factory()->create([
        'reported_at' => '2026-01-01 10:00:00',
    ]);

    expect($condition->reported_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class)
        ->and($condition->reported_at->year)->toBe(2026);
});

it('belongs to a road segment', function () {
    $segment = RoadSegment::factory()->create();
    $condition = RoadCondition::factory()->create(['road_segment_uuid' => $segment->uuid]);

    expect($condition->roadSegment)->toBeInstanceOf(RoadSegment::class)
        ->and($condition->roadSegment->uuid)->toBe($segment->uuid);
});
