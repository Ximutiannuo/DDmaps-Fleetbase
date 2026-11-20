<?php

namespace Fleetbase\ConstructionSite\Database\Factories;

use Fleetbase\ConstructionSite\Models\ConstructionActivity;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ConstructionActivityFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = ConstructionActivity::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        $startsAt = $this->faker->dateTime();

        return [
            'company_uuid' => Str::uuid()->toString(),
            'title'        => $this->faker->sentence(3),
            'type'         => $this->faker->randomElement(['road_work', 'pipeline', 'foundation', 'earthwork']),
            'status'       => $this->faker->randomElement(['planned', 'active', 'paused', 'completed']),
            'starts_at'    => $startsAt,
            'ends_at'      => $this->faker->optional()->dateTimeInInterval($startsAt, '+30 days'),
            'description'  => $this->faker->optional()->paragraph(),
        ];
    }
}
