<?php

namespace Tests\Feature\Video;

use App\Events\VideoCallEnded;
use App\Events\VideoCallStarted;
use App\Models\Block;
use App\Models\User;
use App\Models\UserMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Videollamada 1 a 1 entre matches (T-19, RF-10). LiveKit se simula con Http::fake().
 */
class VideoCallApiTest extends TestCase
{
    use RefreshDatabase;

    private UserMatch $match;

    private User $ana;

    private User $beto;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.livekit.url' => 'wss://kampus-prueba.livekit.cloud',
            'services.livekit.api_key' => 'APIprueba',
            'services.livekit.api_secret' => 'secreto-de-prueba-de-al-menos-32-caracteres',
        ]);
        Http::fake(['kampus-prueba.livekit.cloud/*' => Http::response(['name' => 'sala'])]);

        $this->ana = User::factory()->create(['name' => 'Ana López']);
        $this->beto = User::factory()->create(['name' => 'Beto Ruiz']);
        $this->match = UserMatch::factory()->between($this->ana, $this->beto)->create();
    }

    public function test_starting_a_call_returns_a_token_for_a_two_person_room_and_rings_the_other_person(): void
    {
        Event::fake([VideoCallStarted::class]);
        Sanctum::actingAs($this->ana);

        $response = $this->postJson("/api/matches/{$this->match->id}/call")
            ->assertCreated()
            ->assertJsonPath('data.url', 'wss://kampus-prueba.livekit.cloud')
            ->assertJsonPath('data.room', "match-{$this->match->id}")
            ->assertJsonStructure(['data' => ['token', 'expires_at']]);

        $claims = $this->claimsOf($response->json('data.token'));
        $this->assertSame("user-{$this->ana->id}", $claims['sub']);
        $this->assertSame("match-{$this->match->id}", $claims['video']['room']);
        $this->assertSame(2, $claims['roomConfig']['maxParticipants']);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/CreateRoom')
            && $request['name'] === "match-{$this->match->id}"
            && $request['max_participants'] === 2);

        Event::assertDispatched(VideoCallStarted::class, function (VideoCallStarted $event): bool {
            $payload = $event->broadcastWith()['call'];

            return $event->broadcastOn()[0]->name === "private-user.{$this->beto->id}"
                && $event->broadcastAs() === 'call.started'
                && $payload['match_id'] === $this->match->id
                && $payload['caller']['id'] === $this->ana->id
                && ! array_key_exists('token', $payload);
        });
    }

    public function test_answering_returns_a_token_without_ringing_again(): void
    {
        Event::fake([VideoCallStarted::class]);
        Sanctum::actingAs($this->beto);

        $response = $this->postJson("/api/matches/{$this->match->id}/call/token")->assertOk();

        $this->assertSame("user-{$this->beto->id}", $this->claimsOf($response->json('data.token'))['sub']);
        Event::assertNotDispatched(VideoCallStarted::class);
    }

    public function test_hanging_up_closes_the_room_and_tells_the_other_person(): void
    {
        Event::fake([VideoCallEnded::class]);
        Sanctum::actingAs($this->beto);

        $this->deleteJson("/api/matches/{$this->match->id}/call")->assertNoContent();

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/DeleteRoom')
            && $request['room'] === "match-{$this->match->id}");
        Event::assertDispatched(VideoCallEnded::class, fn (VideoCallEnded $event): bool => $event->broadcastOn()[0]->name === "private-user.{$this->ana->id}"
            && $event->broadcastWith()['call'] === ['match_id' => $this->match->id, 'ended_by' => $this->beto->id]);
    }

    public function test_only_the_two_members_of_a_visible_match_get_a_token(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/matches/{$this->match->id}/call")->assertNotFound();
        $this->postJson("/api/matches/{$this->match->id}/call/token")->assertNotFound();

        // Con un bloqueo en cualquier sentido, el match deja de existir para los dos.
        Block::query()->create(['blocker_id' => $this->beto->id, 'blocked_id' => $this->ana->id]);
        Sanctum::actingAs($this->ana);
        $this->postJson("/api/matches/{$this->match->id}/call")->assertNotFound();

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson("/api/matches/{$this->match->id}/call")->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_without_livekit_it_answers_503_and_the_chat_keeps_working(): void
    {
        config(['services.livekit.api_secret' => null]);
        Event::fake([VideoCallStarted::class]);
        Sanctum::actingAs($this->ana);

        $this->postJson("/api/matches/{$this->match->id}/call")
            ->assertStatus(503)
            ->assertJsonPath('code', 'video_unavailable');

        Event::assertNotDispatched(VideoCallStarted::class);
        $this->postJson("/api/matches/{$this->match->id}/messages", ['body' => 'Mejor por aquí'])->assertCreated();
    }

    public function test_starting_calls_has_its_own_limit(): void
    {
        Event::fake([VideoCallStarted::class]);
        Sanctum::actingAs($this->ana);

        foreach (range(1, 10) as $attempt) {
            $this->postJson("/api/matches/{$this->match->id}/call")->assertCreated();
        }

        $this->postJson("/api/matches/{$this->match->id}/call")->assertTooManyRequests();
    }

    /**
     * @return array<string, mixed>
     */
    private function claimsOf(string $token): array
    {
        return json_decode((string) base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);
    }
}
