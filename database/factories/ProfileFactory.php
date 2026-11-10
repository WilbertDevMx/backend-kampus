<?php

namespace Database\Factories;

use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Profile>
 */
class ProfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'career' => fake()->words(3, true),
            'semester' => fake()->numberBetween(1, 12),
            'birthdate' => fake()->dateTimeBetween('-30 years', '-18 years')->format('Y-m-d'),
            'bio' => fake()->text(200),
            'nearby_enabled' => false,
            'discovery_filters' => null,
        ];
    }
}
