<?php

namespace Database\Factories;

use App\Models\Message;
use App\Models\User;
use App\Models\UserMatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'match_id' => UserMatch::factory(),
            'sender_id' => User::factory(),
            'type' => 'text',
            'body' => fake()->sentence(),
            'attachment_path' => null,
            'latitude' => null,
            'longitude' => null,
            'read_at' => null,
        ];
    }
}
