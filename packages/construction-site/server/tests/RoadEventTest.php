<?php

use Fleetbase\ConstructionSite\Models\RoadEvent;
use Fleetbase\ConstructionSite\Models\RoadSegment;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can create a road event', function () {
    $event = RoadEvent::factory()->create(['title' => 'Excavator Breakdown']);

    expect($event->title)->toBe('Excavator Breakdown')
        ->and($event->uuid)->toBeString()
        ->and($event->public_id)->toBeString();
});

it('casts starts_at to a datetime and location to an array', function () {
    $event = RoadEvent::factory()->create([
        'starts_at' => '2026-01-01 08:00:00',
        'location'  => ['type' => 'Point', 'coordinates' => [120.1, 14.3]],
    ]);

    expect($event->starts_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class)
        ->and($event->starts_at->year)->toBe(2026)
        ->and($event->location)->toBeArray()
        ->and($event->location['type'])->toBe('Point');
});

it('belongs to a road segment', function () {
    $segment = RoadSegment::factory()->create();
    $event = RoadEvent::factory()->create(['road_segment_uuid' => $segment->uuid]);

    expect($event->roadSegment)->toBeInstanceOf(RoadSegment::class)
        ->and($event->roadSegment->uuid)->toBe($segment->uuid);
});
