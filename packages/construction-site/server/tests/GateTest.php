<?php

use Fleetbase\ConstructionSite\Models\EntryRegistration;
use Fleetbase\ConstructionSite\Models\Gate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can create a gate', function () {
    $gate = Gate::factory()->create(['name' => 'North Gate']);

    expect($gate->name)->toBe('North Gate')
        ->and($gate->uuid)->toBeString()
        ->and($gate->public_id)->toBeString();
});

it('casts location to an array', function () {
    $gate = Gate::factory()->create([
        'location' => ['type' => 'Point', 'coordinates' => [120.5, 14.2]],
    ]);

    expect($gate->location)->toBeArray()
        ->and($gate->location['type'])->toBe('Point');
});

it('has many entry registrations', function () {
    $gate = Gate::factory()->create();
    EntryRegistration::factory()->count(2)->create(['gate_uuid' => $gate->uuid]);

    expect($gate->entryRegistrations)->toHaveCount(2)
        ->and($gate->entryRegistrations->first())->toBeInstanceOf(EntryRegistration::class);
});
