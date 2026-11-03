<?php

namespace Database\Factories;

use App\Models\Group;
use App\Models\GroupActivity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupActivity>
 */
class GroupActivityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'group_id' => Group::factory(),
            'created_by' => null,
            'title' => fake()->sentence(3),
            'description' => fake()->sentence(),
            'location' => 'Cancha 2, UTT',
            'starts_at' => now()->addDays(3),
        ];
    }
}
