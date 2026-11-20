<?php

namespace Fleetbase\ConstructionSite\Database\Factories;

use Fleetbase\ConstructionSite\Models\VehicleConstructionMeta;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class VehicleConstructionMetaFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = VehicleConstructionMeta::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'company_uuid'     => Str::uuid()->toString(),
            'vehicle_uuid'     => Str::uuid()->toString(),
            'vehicle_category' => $this->faker->randomElement(['earthwork', 'concrete', 'material_transport', 'machinery', 'management']),
            'specs'            => ['max_load' => $this->faker->numberBetween(1000, 50000)],
            'capacity'         => $this->faker->randomFloat(2, 100, 5000),
            'belonging_unit'   => $this->faker->company(),
        ];
    }
}
