<?php

namespace Fleetbase\ConstructionSite\Database\Factories;

use Fleetbase\ConstructionSite\Models\RoadEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class RoadEventFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = RoadEvent::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        $startsAt = $this->faker->dateTime();

        return [
            'company_uuid'      => Str::uuid()->toString(),
            'road_segment_uuid' => RoadSegment::factory(),
            'event_type'        => $this->faker->randomElement(['occupation', 'material_stack', 'equipment', 'breakdown', 'accident']),
            'title'             => $this->faker->sentence(3),
            'description'       => $this->faker->optional()->paragraph(),
            'starts_at'         => $startsAt,
            'ends_at'           => $this->faker->optional()->dateTimeInInterval($startsAt, '+2 days'),
            'status'            => $this->faker->randomElement(['active', 'resolved']),
            'location'          => [
                'type'        => 'Point',
                'coordinates' => [$this->faker->randomFloat(6, -180, 180), $this->faker->randomFloat(6, -90, 90)],
            ],
        ];
    }
}
