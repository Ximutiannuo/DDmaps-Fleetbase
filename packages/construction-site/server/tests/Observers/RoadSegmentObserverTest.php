<?php

use Fleetbase\ConstructionSite\Models\RoadSegment;
use Fleetbase\ConstructionSite\Models\SiteNotification;
use Fleetbase\ConstructionSite\Models\SiteRoad;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates critical notification when segment status changes to closed', function () {
    $road = SiteRoad::factory()->create();
    $segment = RoadSegment::factory()->create([
        'site_road_uuid' => $road->uuid,
        'status'         => 'open',
    ]);

    $segment->update(['status' => 'closed']);

    expect(SiteNotification::where('type', 'road_status')
        ->where('severity', 'critical')
        ->where('source_uuid', $segment->uuid)
        ->exists()
    )->toBeTrue();
});

it('creates warning notification when segment status changes to partial', function () {
    $road = SiteRoad::factory()->create();
    $segment = RoadSegment::factory()->create([
        'site_road_uuid' => $road->uuid,
        'status'         => 'open',
    ]);

    $segment->update(['status' => 'partial']);

    expect(SiteNotification::where('type', 'road_status')
        ->where('severity', 'warning')
        ->where('source_uuid', $segment->uuid)
        ->exists()
    )->toBeTrue();
});

it('does not create notification when non-status field changes', function () {
    $road = SiteRoad::factory()->create();
    $segment = RoadSegment::factory()->create([
        'site_road_uuid' => $road->uuid,
        'status'         => 'open',
    ]);

    $segment->update(['name' => 'K0+100~K0+300']);

    expect(SiteNotification::where('source_uuid', $segment->uuid)->exists())->toBeFalse();
});
