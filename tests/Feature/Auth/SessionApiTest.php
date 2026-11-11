<?php

namespace Tests\Feature\Auth;

use App\Models\University;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Gestión de sesiones (T-46): cada sesión es un token de Sanctum.
 */
class SessionApiTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36';

    private const SAFARI_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = User::factory()->student(University::factory()->create())->create(['email' => 'ana@utt.edu.mx']);
    }

    public function test_login_saves_the_device_and_the_list_shows_the_current_session_first(): void
    {
        $phone = $this->login(self::SAFARI_IPHONE, 'iPhone de Ana');
        $this->travel(5)->minutes();
        $laptop = $this->login(self::CHROME_WINDOWS);

        $response = $this->as($phone)->getJson('/api/sessions')->assertOk();

        $response->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.current', true)
            ->assertJsonPath('data.0.name', 'iPhone de Ana')
            ->assertJsonPath('data.0.device', 'Safari en iOS')
            ->assertJsonPath('data.1.current', false)
            ->assertJsonPath('data.1.name', 'api')
            ->assertJsonPath('data.1.device', 'Chrome en Windows');
        $this->assertSame(['id', 'name', 'device', 'current', 'created_at', 'last_used_at', 'expires_at'], array_keys($response->json('data.0')));
        // Sanctum anota el último uso de la sesión con la que se hizo la petición.
        $this->assertNotNull($response->json('data.0.last_used_at'));
        // Nunca se devuelve el token ni su hash.
        $this->assertStringNotContainsString(explode('|', $laptop)[1], $response->getContent());
        $this->assertStringNotContainsString('token', $response->getContent());
    }

    public function test_the_list_skips_expired_sessions_and_other_peoples_sessions(): void
    {
        $current = $this->login(self::CHROME_WINDOWS);
        $this->student->createToken('vieja', ['*'], now()->subMinute());
        $other = User::factory()->student(University::factory()->create())->create();
        $other->createToken('ajena');

        $this->as($current)->getJson('/api/sessions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.current', true);
    }

    public function test_a_session_from_another_device_can_be_closed(): void
    {
        $phone = $this->login(self::SAFARI_IPHONE);
        $laptop = $this->login(self::CHROME_WINDOWS);

        $this->as($phone)->deleteJson('/api/sessions/'.$this->tokenId($laptop))->assertNoContent();

        $this->assertNull(PersonalAccessToken::findToken($laptop));
        $this->as($laptop)->getJson('/api/user')->assertUnauthorized();
        $this->as($phone)->getJson('/api/user')->assertOk();
    }

    public function test_nobody_can_see_or_close_someone_elses_session(): void
    {
        $mine = $this->login(self::CHROME_WINDOWS);
        $other = User::factory()->student(University::factory()->create())->create();
        $theirs = $other->createToken('ajena');

        $this->as($mine)->deleteJson("/api/sessions/{$theirs->accessToken->id}")->assertNotFound();
        $this->as($mine)->deleteJson('/api/sessions/999999')->assertNotFound();

        $this->assertModelExists($theirs->accessToken);
    }

    public function test_all_other_sessions_can_be_closed_at_once(): void
    {
        $current = $this->login(self::CHROME_WINDOWS);
        $this->login(self::SAFARI_IPHONE);
        $this->login(self::SAFARI_IPHONE);
        $other = User::factory()->student(University::factory()->create())->create();
        $theirs = $other->createToken('ajena');

        $this->as($current)->deleteJson('/api/sessions')->assertOk()->assertExactJson(['closed' => 2]);

        $this->assertSame(1, $this->student->tokens()->count());
        $this->as($current)->getJson('/api/sessions')->assertJsonCount(1, 'data')->assertJsonPath('data.0.current', true);
        $this->assertModelExists($theirs->accessToken);
    }

    public function test_closing_the_current_session_logs_out(): void
    {
        $current = $this->login(self::CHROME_WINDOWS);

        $this->as($current)->deleteJson('/api/sessions/'.$this->tokenId($current))->assertNoContent();

        $this->as($current)->getJson('/api/sessions')->assertUnauthorized();
    }

    public function test_staff_can_manage_their_sessions_too(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'admin@utt.edu.mx']);
        $token = $admin->createToken('panel')->plainTextToken;

        $this->as($token)->getJson('/api/sessions')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_guests_cannot_list_sessions(): void
    {
        $this->getJson('/api/sessions')->assertUnauthorized();
        $this->deleteJson('/api/sessions')->assertUnauthorized();
    }

    private function login(string $userAgent, ?string $deviceName = null): string
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('User-Agent', $userAgent)
            ->postJson('/api/login', array_filter([
                'email' => 'ana@utt.edu.mx',
                'password' => 'password',
                'device_name' => $deviceName,
            ]))
            ->assertCreated()
            ->json('token');
    }

    /**
     * Petición con un token nuevo, sin lo que guardó el guard en la petición anterior.
     */
    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function tokenId(string $plainTextToken): int
    {
        return (int) explode('|', $plainTextToken)[0];
    }
}
