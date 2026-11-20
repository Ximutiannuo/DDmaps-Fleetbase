<?php

use Fleetbase\ConstructionSite\Models\SiteNotification;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can create a site notification', function () {
    $notification = SiteNotification::factory()->create(['title' => 'Road Closure Alert']);

    expect($notification->title)->toBe('Road Closure Alert')
        ->and($notification->uuid)->toBeString()
        ->and($notification->public_id)->toBeString();
});

it('reports read state and can be marked as read', function () {
    $notification = SiteNotification::factory()->create(['read_at' => null]);

    expect($notification->isRead())->toBeFalse();

    $notification->markAsRead();

    expect($notification->fresh()->read_at)->not->toBeNull()
        ->and($notification->fresh()->isRead())->toBeTrue();
});

it('defines a polymorphic source relationship', function () {
    $notification = SiteNotification::factory()->create();

    expect($notification->source())->toBeInstanceOf(MorphTo::class);
});
