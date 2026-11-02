<?php

namespace Database\Factories;

use App\Enums\GroupCategory;
use App\Enums\GroupMemberRole;
use App\Enums\GroupStatus;
use App\Models\Group;
use App\Models\University;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Group>
 */
class GroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'university_id' => University::factory(),
            'created_by' => null,
            'name' => 'Club de '.fake()->unique()->word(),
            'description' => fake()->sentence(),
            'category' => fake()->randomElement(GroupCategory::cases()),
        ];
    }

    /**
     * Grupo publicado. `status` no es asignable en masa, por eso se fija después de crearlo.
     */
    public function approved(): static
    {
        return $this->afterMaking(fn (Group $group) => $group->forceFill(['status' => GroupStatus::Approved]));
    }

    public function rejected(string $reason = 'No cumple las reglas de la comunidad.'): static
    {
        return $this->afterMaking(fn (Group $group) => $group->forceFill([
            'status' => GroupStatus::Rejected,
            'rejection_reason' => $reason,
        ]));
    }

    public function official(): static
    {
        return $this->afterMaking(fn (Group $group) => $group->forceFill([
            'status' => GroupStatus::Approved,
            'is_official' => true,
        ]));
    }

    /**
     * Grupo creado por `$admin`, que queda como su administrador.
     */
    public function createdBy(User $admin): static
    {
        return $this->state(fn (array $attributes) => [
            'created_by' => $admin->getKey(),
            'university_id' => $admin->university_id,
        ])->afterCreating(fn (Group $group) => $group->members()->attach(
            $admin->getKey(),
            ['role' => GroupMemberRole::Admin->value],
        ));
    }
}
