<?php

namespace Fleetbase\ConstructionSite\Database\Factories;

use Fleetbase\ConstructionSite\Models\RoadCondition;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class RoadConditionFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = RoadCondition::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'company_uuid'        => Str::uuid()->toString(),
            'road_segment_uuid'   => RoadSegment::factory(),
            'status'              => $this->faker->randomElement(['open', 'closed', 'partial', 'construction']),
            'surface_condition'   => $this->faker->randomElement(['dry', 'wet', 'muddy', 'icy', 'pothole']),
            'congestion'          => $this->faker->randomElement(['none', 'light', 'heavy', 'standstill']),
            'note'                => $this->faker->optional()->sentence(),
            'reported_by_uuid'    => Str::uuid()->toString(),
            'reported_at'         => $this->faker->dateTime(),
        ];
    }
}
