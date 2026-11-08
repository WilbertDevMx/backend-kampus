<?php

namespace Database\Factories;

use App\Enums\Gender;
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
            'gender' => fake()->randomElement(Gender::cases()),
            'bio' => fake()->text(200),
            'nearby_enabled' => false,
            'discovery_filters' => null,
            // Casi todas las pruebas necesitan a alguien que ya puede aparecer en el descubrimiento.
            'completed_at' => now(),
        ];
    }

    /**
     * Perfil recién creado que todavía no termina el registro por pasos (T-49).
     */
    public function incomplete(): static
    {
        return $this->state(fn (): array => ['completed_at' => null]);
    }
}
