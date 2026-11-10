<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\University;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Estudiante de una universidad.
     */
    public function student(University $university): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Student,
            'university_id' => $university->id,
        ]);
    }

    /**
     * Moderador de una sola universidad (RF-22).
     */
    public function moderator(University $university): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Moderator,
            'university_id' => $university->id,
        ]);
    }

    /**
     * Administrador: sin universidad, porque las atiende todas.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => UserRole::Admin,
            'university_id' => null,
        ]);
    }
}
