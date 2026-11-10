<?php

namespace Tests\Feature\Messaging;

use App\Events\MessageSent;
use App\Models\Message;
use App\Models\University;
use App\Models\User;
use App\Models\UserMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MessageApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_match_member_sends_a_text_message_and_broadcasts_it_to_the_chat_channel(): void
    {
        [$match, $sender, $recipient] = $this->createConversation();
        $otherMatch = UserMatch::factory()->between($sender, User::factory()->create())->create();
        Event::fake([MessageSent::class]);
        Sanctum::actingAs($sender);

        $response = $this->postJson("/api/matches/{$match->id}/messages", [
            'body' => 'Hola, ¿cómo estás?',
            'sender_id' => $recipient->id,
            'match_id' => $otherMatch->id,
            'type' => 'image',
            'read_at' => now()->toIso8601String(),
        ])
            ->assertCreated()
            ->assertJsonPath('data.match_id', $match->id)
            ->assertJsonPath('data.sender_id', $sender->id)
            ->assertJsonPath('data.type', 'text')
            ->assertJsonPath('data.body', 'Hola, ¿cómo estás?')
            ->assertJsonPath('data.read_at', null)
            ->assertJsonMissingPath('data.sender.email');

        $message = Message::query()->sole();

        $this->assertSame($response->json('data.id'), $message->id);
        $this->assertSame($match->id, $message->match_id);
        $this->assertSame($sender->id, $message->sender_id);
        $this->assertSame('text', $message->type);
        $this->assertNull($message->read_at);

        Event::assertDispatched(MessageSent::class, function (MessageSent $event) use ($message, $match): bool {
            $channel = $event->broadcastOn()[0];
            $payload = $event->broadcastWith()['message'];

            return $event->message->is($message)
                && $channel->name === "private-chat.{$match->id}"
                && $event->broadcastAs() === 'message.sent'
                && $payload['id'] === $message->id
                && ! array_key_exists('email', $payload);
        });
    }

    public function test_sending_a_message_validates_a_nonempty_body_within_the_limit(): void
    {
        [$match, $sender] = $this->createConversation();
        Sanctum::actingAs($sender);

        $this->postJson("/api/matches/{$match->id}/messages", [])
            ->assertInvalid(['body']);
        $this->postJson("/api/matches/{$match->id}/messages", ['body' => '   '])
            ->assertInvalid(['body']);
        $this->postJson("/api/matches/{$match->id}/messages", ['body' => str_repeat('a', 5001)])
            ->assertInvalid(['body']);

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_a_member_reads_the_latest_messages_in_descending_id_order_with_pagination(): void
    {
        [$match, $sender, $recipient] = $this->createConversation();
        $messages = Message::factory()
            ->count(55)
            ->create(['match_id' => $match->id, 'sender_id' => $sender->id]);
        $otherMatch = UserMatch::factory()->between($sender, User::factory()->create())->create();
        $unrelatedMessage = Message::factory()->create([
            'match_id' => $otherMatch->id,
            'sender_id' => $recipient->id,
        ]);
        Sanctum::actingAs($recipient);

        $response = $this->getJson("/api/matches/{$match->id}/messages")
            ->assertOk()
            ->assertJsonCount(50, 'data')
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonPath('data.0.id', $messages->last()->id)
            ->assertJsonPath('data.49.id', $messages[5]->id)
            ->assertJsonMissing(['id' => $unrelatedMessage->id]);

        $this->assertNotNull($response->json('links.next'));

        $this->getJson("/api/matches/{$match->id}/messages?per_page=3")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.per_page', 3);

        $this->getJson("/api/matches/{$match->id}/messages?per_page=101")
            ->assertUnprocessable()
            ->assertInvalid(['per_page']);
    }

    public function test_messages_can_be_marked_read_without_changing_sent_or_other_match_messages(): void
    {
        [$match, $reader, $sender] = $this->createConversation();
        $unreadMessages = Message::factory()
            ->count(2)
            ->create(['match_id' => $match->id, 'sender_id' => $sender->id, 'read_at' => null]);
        $alreadyReadMessage = Message::factory()->create([
            'match_id' => $match->id,
            'sender_id' => $sender->id,
            'read_at' => now()->subMinute(),
        ]);
        $sentMessage = Message::factory()->create([
            'match_id' => $match->id,
            'sender_id' => $reader->id,
            'read_at' => null,
        ]);
        $otherMatch = UserMatch::factory()->between($reader, User::factory()->create())->create();
        $unrelatedMessage = Message::factory()->create([
            'match_id' => $otherMatch->id,
            'sender_id' => $sender->id,
            'read_at' => null,
        ]);
        Sanctum::actingAs($reader);

        $this->patchJson("/api/matches/{$match->id}/messages/read")
            ->assertOk()
            ->assertJsonPath('data.read_count', 2);

        foreach ($unreadMessages as $message) {
            $this->assertNotNull($message->fresh()->read_at);
        }

        $this->assertSame(
            $alreadyReadMessage->read_at->toIso8601String(),
            $alreadyReadMessage->fresh()->read_at->toIso8601String(),
        );
        $this->assertNull($sentMessage->fresh()->read_at);
        $this->assertNull($unrelatedMessage->fresh()->read_at);

        $this->patchJson("/api/matches/{$match->id}/messages/read")
            ->assertOk()
            ->assertJsonPath('data.read_count', 0);
    }

    public function test_only_members_of_visible_matches_can_read_send_or_mark_messages_read(): void
    {
        [$match, $member, $otherMember] = $this->createConversation();
        $outsider = User::factory()->create();
        Sanctum::actingAs($outsider);

        $this->getJson('/api/matches/999999/messages')->assertNotFound();
        $this->getJson("/api/matches/{$match->id}/messages")->assertNotFound();
        $this->postJson("/api/matches/{$match->id}/messages", ['body' => 'No autorizado'])->assertNotFound();
        $this->patchJson("/api/matches/{$match->id}/messages/read")->assertNotFound();

        DB::table('blocks')->insert([
            'blocker_id' => $member->id,
            'blocked_id' => $otherMember->id,
        ]);
        Sanctum::actingAs($member);

        $this->getJson("/api/matches/{$match->id}/messages")->assertNotFound();
        $this->postJson("/api/matches/{$match->id}/messages", ['body' => 'Bloqueado'])->assertNotFound();
        $this->patchJson("/api/matches/{$match->id}/messages/read")->assertNotFound();

        DB::table('blocks')->delete();
        DB::table('blocks')->insert([
            'blocker_id' => $otherMember->id,
            'blocked_id' => $member->id,
        ]);

        $this->getJson("/api/matches/{$match->id}/messages")->assertNotFound();
        $this->postJson("/api/matches/{$match->id}/messages", ['body' => 'Bloqueado por el otro lado'])->assertNotFound();
        $this->patchJson("/api/matches/{$match->id}/messages/read")->assertNotFound();

        DB::table('blocks')->delete();
        $otherMember->forceFill(['suspended_at' => now()])->save();

        $this->getJson("/api/matches/{$match->id}/messages")->assertNotFound();
        $this->postJson("/api/matches/{$match->id}/messages", ['body' => 'Cuenta suspendida'])->assertNotFound();
        $this->patchJson("/api/matches/{$match->id}/messages/read")->assertNotFound();

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_message_endpoints_require_a_verified_authenticated_student(): void
    {
        [$match] = $this->createConversation();
        $path = "/api/matches/{$match->id}/messages";

        $this->getJson($path)->assertUnauthorized();
        $this->postJson($path, ['body' => 'Hola'])->assertUnauthorized();
        $this->patchJson("{$path}/read")->assertUnauthorized();

        $unverified = User::factory()->unverified()->create();
        Sanctum::actingAs($unverified);
        $this->getJson($path)->assertForbidden();

        $university = University::factory()->create();
        Sanctum::actingAs(User::factory()->moderator($university)->create());
        $this->getJson($path)->assertForbidden();
    }

    public function test_history_and_message_updates_stay_under_two_seconds_with_two_thousand_messages(): void
    {
        [$match, $sender, $recipient] = $this->createConversation();
        $timestamp = now();
        $rows = [];

        for ($index = 0; $index < 2000; $index++) {
            $rows[] = [
                'match_id' => $match->id,
                'sender_id' => $sender->id,
                'type' => 'text',
                'body' => "Mensaje {$index}",
                'attachment_path' => null,
                'latitude' => null,
                'longitude' => null,
                'read_at' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('messages')->insert($chunk);
        }

        Sanctum::actingAs($recipient);
        $startedAt = hrtime(true);
        $this->getJson("/api/matches/{$match->id}/messages")->assertOk();
        $historyMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;

        $this->assertLessThan(2000, $historyMilliseconds, 'El historial debe responder en menos de 2 segundos.');

        $startedAt = hrtime(true);
        $this->patchJson("/api/matches/{$match->id}/messages/read")->assertOk();
        $markReadMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;

        $this->assertLessThan(2000, $markReadMilliseconds, 'Marcar mensajes leídos debe responder en menos de 2 segundos.');

        $startedAt = hrtime(true);
        $this->postJson("/api/matches/{$match->id}/messages", ['body' => 'Mensaje de rendimiento'])
            ->assertCreated();
        $sendMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;

        $this->assertLessThan(2000, $sendMilliseconds, 'Enviar un mensaje debe responder en menos de 2 segundos.');
    }

    /**
     * @return array{UserMatch, User, User}
     */
    private function createConversation(): array
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $match = UserMatch::factory()->between($firstUser, $secondUser)->create();

        return [$match, $firstUser, $secondUser];
    }
}
