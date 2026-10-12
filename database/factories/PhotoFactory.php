<?php

namespace Database\Factories;

use App\Models\Photo;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Photo>
 */
class PhotoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'profile_id' => Profile::factory(),
            'path' => fake()->unique()->uuid().'.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1024,
        ];
    }
}
