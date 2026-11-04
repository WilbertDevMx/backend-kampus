<?php

namespace Database\Factories;

use App\Models\Swipe;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Swipe>
 */
class SwipeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'swiper_id' => User::factory(),
            'swiped_id' => User::factory(),
            'liked' => true,
        ];
    }

    public function passed(): static
    {
        return $this->state(fn (array $attributes) => [
            'liked' => false,
        ]);
    }
}
