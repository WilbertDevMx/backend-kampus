<?php

namespace Tests\Feature;

use App\Broadcasting\ChatChannel;
use App\Broadcasting\UserChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BroadcastChannelAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app-id',
        ]);

        Broadcast::channel('chat.{matchId}', ChatChannel::class);
        Broadcast::channel('user.{userId}', UserChannel::class);
    }

    public function test_a_match_participant_can_authorize_the_chat_channel(): void
    {
        $participant = User::factory()->create();
        $otherParticipant = User::factory()->create();
        $matchId = $this->createMatch($participant, $otherParticipant);

        $this->authorizeChannel($participant, "private-chat.{$matchId}")
            ->assertOk();
    }

    public function test_a_non_participant_cannot_authorize_the_chat_channel(): void
    {
        $participant = User::factory()->create();
        $otherParticipant = User::factory()->create();
        $outsider = User::factory()->create();
        $matchId = $this->createMatch($participant, $otherParticipant);

        $this->authorizeChannel($outsider, "private-chat.{$matchId}")
            ->assertForbidden();
    }

    public function test_a_nonexistent_match_cannot_authorize_the_chat_channel(): void
    {
        $user = User::factory()->create();

        $this->authorizeChannel($user, 'private-chat.999999')
            ->assertForbidden();

        $this->authorizeChannel($user, 'private-chat.invalid')
            ->assertForbidden();
    }

    public function test_a_blocked_pair_cannot_authorize_the_chat_channel(): void
    {
        $participant = User::factory()->create();
        $otherParticipant = User::factory()->create();
        $matchId = $this->createMatch($participant, $otherParticipant);

        DB::table('blocks')->insert([
            'blocker_id' => $participant->getKey(),
            'blocked_id' => $otherParticipant->getKey(),
        ]);

        $this->authorizeChannel($participant, "private-chat.{$matchId}")
            ->assertForbidden();

        DB::table('blocks')
            ->where('blocker_id', $participant->getKey())
            ->where('blocked_id', $otherParticipant->getKey())
            ->delete();
        DB::table('blocks')->insert([
            'blocker_id' => $otherParticipant->getKey(),
            'blocked_id' => $participant->getKey(),
        ]);

        $this->authorizeChannel($participant, "private-chat.{$matchId}")
            ->assertForbidden();
    }

    public function test_a_suspended_user_cannot_authorize_the_chat_channel(): void
    {
        $participant = User::factory()->create(['suspended_at' => now()]);
        $otherParticipant = User::factory()->create();
        $matchId = $this->createMatch($participant, $otherParticipant);

        $this->authorizeChannel($participant, "private-chat.{$matchId}")
            ->assertForbidden();
    }

    public function test_a_user_can_only_authorize_their_own_private_user_channel(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $this->authorizeChannel($user, "private-user.{$user->getKey()}")
            ->assertOk();

        $this->authorizeChannel($user, "private-user.{$otherUser->getKey()}")
            ->assertForbidden();

        $suspendedUser = User::factory()->create(['suspended_at' => now()]);

        $this->authorizeChannel($suspendedUser, "private-user.{$suspendedUser->getKey()}")
            ->assertForbidden();
    }

    public function test_broadcast_authorization_requires_a_verified_sanctum_user(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-user.{$user->getKey()}",
        ])->assertUnauthorized();

        $unverifiedUser = User::factory()->unverified()->create();

        $this->authorizeChannel($unverifiedUser, "private-user.{$unverifiedUser->getKey()}")
            ->assertForbidden();
    }

    private function authorizeChannel(User $user, string $channelName): TestResponse
    {
        return $this->actingAs($user, 'sanctum')->postJson('/api/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => $channelName,
        ]);
    }

    private function createMatch(User $firstParticipant, User $secondParticipant): int
    {
        return (int) DB::table('matches')->insertGetId([
            'user_one_id' => $firstParticipant->getKey(),
            'user_two_id' => $secondParticipant->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
