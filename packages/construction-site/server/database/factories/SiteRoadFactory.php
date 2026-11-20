<?php

namespace Fleetbase\ConstructionSite\Database\Factories;

use Fleetbase\ConstructionSite\Models\SiteRoad;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SiteRoadFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = SiteRoad::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'company_uuid' => Str::uuid()->toString(),
            'name'         => $this->faker->streetName(),
            'road_type'    => $this->faker->randomElement(['main', 'branch', 'haul_road', 'ring_road']),
            'status'       => $this->faker->randomElement(['open', 'closed', 'partial']),
            'geometry'     => [
                'type'        => 'LineString',
                'coordinates' => [
                    [$this->faker->randomFloat(6, -180, 180), $this->faker->randomFloat(6, -90, 90)],
                    [$this->faker->randomFloat(6, -180, 180), $this->faker->randomFloat(6, -90, 90)],
                ],
            ],
        ];
    }
}
