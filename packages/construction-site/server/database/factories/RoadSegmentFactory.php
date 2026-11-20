<?php

namespace Fleetbase\ConstructionSite\Database\Factories;

use Fleetbase\ConstructionSite\Models\RoadSegment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class RoadSegmentFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = RoadSegment::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'company_uuid'         => Str::uuid()->toString(),
            'site_road_uuid'       => SiteRoad::factory(),
            'name'                 => $this->faker->streetName(),
            'sequence'             => $this->faker->numberBetween(0, 100),
            'status'               => $this->faker->randomElement(['open', 'closed', 'partial', 'construction']),
            'geometry'             => [
                'type'        => 'LineString',
                'coordinates' => [
                    [$this->faker->randomFloat(6, -180, 180), $this->faker->randomFloat(6, -90, 90)],
                    [$this->faker->randomFloat(6, -180, 180), $this->faker->randomFloat(6, -90, 90)],
                ],
            ],
            'current_condition_uuid' => null,
        ];
    }
}
