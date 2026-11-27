<?php

namespace Tests\Feature\Security;

use App\Models\Message;
use App\Models\University;
use App\Models\User;
use App\Models\UserMatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * La API guarda y devuelve el texto del usuario tal cual, siempre dentro de JSON y con cabeceras que
 * impiden que el navegador lo interprete como HTML o ejecute algo. Escaparlo al mostrarlo es tarea de
 * la app (React Native lo muestra como texto).
 */
class XssTest extends TestCase
{
    use RefreshDatabase;

    private University $utt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->utt = University::factory()->create(['short_name' => 'UTT']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function payloads(): array
    {
        return [
            'script' => ['<script>alert(document.cookie)</script>'],
            'imagen con onerror' => ['<img src=x onerror=alert(1)>'],
            'cierra un atributo' => ['"><svg onload=alert(1)>'],
            'enlace javascript' => ['<a href="javascript:alert(1)">ver</a>'],
            'iframe' => ['<iframe src="https://evil.example"></iframe>'],
        ];
    }

    #[DataProvider('payloads')]
    public function test_a_chat_message_is_stored_and_returned_as_plain_text(string $payload): void
    {
        $sender = User::factory()->student($this->utt)->create();
        $match = UserMatch::factory()->between($sender, User::factory()->student($this->utt)->create())->create();
        Sanctum::actingAs($sender);

        $sent = $this->postJson("/api/matches/{$match->id}/messages", ['body' => $payload])
            ->assertCreated()
            ->assertJsonPath('data.body', $payload);
        $this->assertServedAsInertJson($sent);

        $this->assertSame($payload, Message::query()->sole()->body);

        $history = $this->getJson("/api/matches/{$match->id}/messages")
            ->assertOk()
            ->assertJsonPath('data.0.body', $payload);
        $this->assertServedAsInertJson($history);
    }

    #[DataProvider('payloads')]
    public function test_the_profile_bio_and_name_are_returned_as_plain_text(string $payload): void
    {
        $student = User::factory()->student($this->utt)->create();
        Sanctum::actingAs($student);

        $response = $this->patchJson('/api/profile', ['name' => $payload, 'bio' => $payload])
            ->assertOk()
            ->assertJsonPath('data.user.name', $payload)
            ->assertJsonPath('data.profile.bio', $payload);
        $this->assertServedAsInertJson($response);

        $this->assertDatabaseHas('profiles', ['user_id' => $student->id, 'bio' => $payload]);
    }

    #[DataProvider('payloads')]
    public function test_a_group_name_and_description_are_returned_as_plain_text(string $payload): void
    {
        Sanctum::actingAs(User::factory()->student($this->utt)->create());

        $response = $this->postJson('/api/groups', [
            'name' => $payload,
            'description' => $payload,
            'category' => 'cultural',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', $payload)
            ->assertJsonPath('data.description', $payload);
        $this->assertServedAsInertJson($response);
    }

    public function test_input_reflected_in_an_error_stays_inside_json(): void
    {
        Sanctum::actingAs(User::factory()->student($this->utt)->create());

        // El mensaje de "ruta no encontrada" repite la ruta pedida: debe seguir siendo JSON, no HTML.
        $response = $this->getJson('/api/'.rawurlencode('<script>alert(1)</script>'))->assertNotFound();

        $this->assertServedAsInertJson($response);
        $this->assertStringNotContainsString('<html', strtolower((string) $response->getContent()));
    }

    /**
     * El navegador no puede tratar la respuesta como HTML ni ejecutar nada que venga en ella.
     */
    private function assertServedAsInertJson(TestResponse $response): void
    {
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
    }
}
