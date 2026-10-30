<?php

namespace Database\Factories;

use App\Models\University;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<University>
 */
class UniversityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $shortName = Str::upper(fake()->unique()->lexify('U???'));

        return [
            'name' => 'Universidad '.fake()->unique()->city(),
            'short_name' => $shortName,
            'email_domains' => [Str::lower($shortName).'.edu.mx'],
            'is_active' => true,
        ];
    }
}
