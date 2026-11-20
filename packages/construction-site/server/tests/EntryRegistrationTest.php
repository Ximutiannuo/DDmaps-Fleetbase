<?php

use Fleetbase\ConstructionSite\Models\EntryRegistration;
use Fleetbase\ConstructionSite\Models\Gate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can create an entry registration', function () {
    $registration = EntryRegistration::factory()->create(['driver_name' => 'John Doe']);

    expect($registration->driver_name)->toBe('John Doe')
        ->and($registration->uuid)->toBeString()
        ->and($registration->public_id)->toBeString();
});

it('auto-generates a 64 character session token on creation', function () {
    $registration = EntryRegistration::factory()->create();

    expect($registration->session_token)->toBeString()
        ->and(strlen($registration->session_token))->toBe(64);
});

it('can be marked as exited and reports active state', function () {
    $registration = EntryRegistration::factory()->create(['status' => 'active', 'exited_at' => null]);

    expect($registration->isActive())->toBeTrue();

    $registration->exit();

    expect($registration->fresh()->status)->toBe('exited')
        ->and($registration->fresh()->exited_at)->not->toBeNull()
        ->and($registration->fresh()->isActive())->toBeFalse()
        ->and($registration->fresh()->gate)->toBeInstanceOf(Gate::class);
});
