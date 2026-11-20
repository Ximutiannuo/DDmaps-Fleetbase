<?php

namespace Fleetbase\ConstructionSite\Database\Factories;

use Fleetbase\ConstructionSite\Models\SiteNotification;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SiteNotificationFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = SiteNotification::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'company_uuid'  => Str::uuid()->toString(),
            'title'         => $this->faker->sentence(3),
            'message'       => $this->faker->paragraph(),
            'type'          => $this->faker->randomElement(['road_status', 'event', 'construction', 'entry', 'system']),
            'severity'      => $this->faker->randomElement(['info', 'warning', 'critical']),
            'source_type'   => null,
            'source_uuid'   => null,
            'target_scope'  => $this->faker->randomElement(['all', 'managers', 'drivers']),
            'read_at'       => null,
        ];
    }
}
