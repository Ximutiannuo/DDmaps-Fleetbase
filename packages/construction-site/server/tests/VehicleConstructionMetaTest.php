<?php

use Fleetbase\ConstructionSite\Models\VehicleConstructionMeta;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can create a vehicle construction meta record', function () {
    $meta = VehicleConstructionMeta::factory()->create(['belonging_unit' => 'Earthwork Team A']);

    expect($meta->belonging_unit)->toBe('Earthwork Team A')
        ->and($meta->uuid)->toBeString()
        ->and($meta->public_id)->toBeString();
});

it('casts specs to an array and capacity to a decimal string', function () {
    $meta = VehicleConstructionMeta::factory()->create([
        'specs'    => ['max_load' => 20000, 'bed_capacity' => 12],
        'capacity' => 1500.5,
    ]);

    expect($meta->specs)->toBeArray()
        ->and($meta->specs['max_load'])->toBe(20000)
        ->and($meta->capacity)->toBeString()
        ->and((float) $meta->capacity)->toBe(1500.50);
});

it('defines a vehicle belongs-to relationship', function () {
    $meta = VehicleConstructionMeta::factory()->create();

    expect($meta->vehicle())->toBeInstanceOf(BelongsTo::class);
});
