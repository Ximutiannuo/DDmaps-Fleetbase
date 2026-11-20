<?php

use Fleetbase\ConstructionSite\Models\ConstructionActivity;
use Fleetbase\ConstructionSite\Models\RoadSegment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can create a construction activity', function () {
    $activity = ConstructionActivity::factory()->create(['title' => 'Foundation Pour']);

    expect($activity->title)->toBe('Foundation Pour')
        ->and($activity->uuid)->toBeString()
        ->and($activity->public_id)->toBeString();
});

it('casts starts_at to a datetime', function () {
    $activity = ConstructionActivity::factory()->create([
        'starts_at' => '2026-02-01 07:00:00',
    ]);

    expect($activity->starts_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class)
        ->and($activity->starts_at->month)->toBe(2);
});

it('belongs to many road segments', function () {
    $activity = ConstructionActivity::factory()->create();
    $segments = RoadSegment::factory()->count(2)->create();

    $activity->roadSegments()->attach($segments->pluck('uuid')->toArray());

    expect($activity->roadSegments)->toHaveCount(2)
        ->and($activity->roadSegments->first())->toBeInstanceOf(RoadSegment::class);
});
