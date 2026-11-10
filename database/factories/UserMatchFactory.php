<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserMatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserMatch>
 */
class UserMatchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_one_id' => User::factory(),
            'user_two_id' => User::factory(),
        ];
    }

    /**
     * Match entre dos usuarios existentes, con el par ya ordenado como lo guarda la aplicación.
     */
    public function between(User $a, User $b): static
    {
        return $this->state(fn (array $attributes) => UserMatch::pairOf($a, $b));
    }
}
