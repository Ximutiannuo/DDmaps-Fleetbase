<?php

namespace Fleetbase\ConstructionSite\Database\Factories;

use Fleetbase\ConstructionSite\Models\EntryRegistration;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class EntryRegistrationFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = EntryRegistration::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'company_uuid'      => Str::uuid()->toString(),
            'vehicle_uuid'      => Str::uuid()->toString(),
            'driver_uuid'       => Str::uuid()->toString(),
            'gate_uuid'         => Gate::factory(),
            'driver_name'       => $this->faker->name(),
            'driver_phone'      => $this->faker->phoneNumber(),
            'purpose'           => $this->faker->randomElement(['delivery', 'pickup', 'maintenance', 'other']),
            'status'            => $this->faker->randomElement(['active', 'exited']),
            'entered_at'        => $this->faker->dateTime(),
            'exited_at'         => null,
            'tracking_enabled'  => true,
        ];
    }
}
