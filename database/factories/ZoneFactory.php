<?php

namespace Database\Factories;

use App\Enums\ZoneType;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Zone>
 */
class ZoneFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Colonia '.fake()->unique()->lastName(),
            'type' => ZoneType::Colonia,
            'university_id' => null,
            'city' => 'Torreón',
            'center_lat' => fake()->latitude(25.50, 25.58),
            'center_lng' => fake()->longitude(-103.46, -103.36),
        ];
    }
}
