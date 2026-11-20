<?php

namespace Fleetbase\ConstructionSite\Database\Factories;

use Fleetbase\ConstructionSite\Models\Gate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class GateFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Gate::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'company_uuid' => Str::uuid()->toString(),
            'name'         => $this->faker->streetName() . ' Gate',
            'gate_type'    => $this->faker->randomElement(['entrance', 'exit', 'both']),
            'status'       => $this->faker->randomElement(['open', 'closed']),
            'location'     => [
                'type'        => 'Point',
                'coordinates' => [$this->faker->randomFloat(6, -180, 180), $this->faker->randomFloat(6, -90, 90)],
            ],
        ];
    }
}
