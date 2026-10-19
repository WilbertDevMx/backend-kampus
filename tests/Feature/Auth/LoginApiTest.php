<?php

namespace Tests\Feature\Auth;

use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class LoginApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_user_receives_an_expiring_token(): void
    {
        $user = User::factory()->create(['email' => 'alumno@utt.edu.mx']);

        $response = $this->postJson('/api/login', [
            'email' => 'alumno@utt.edu.mx',
            'password' => 'password',
            'device_name' => 'iphone',
        ]);

        $response->assertCreated()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.email', 'alumno@utt.edu.mx')
            ->assertJsonStructure(['token', 'token_type', 'expires_at', 'user' => ['id', 'name', 'email', 'email_verified_at']])
            ->assertJsonMissingPath('user.password');

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'iphone',
        ]);
        $this->assertNotNull($user->tokens()->first()->expires_at);
    }

    public function test_issued_token_authenticates_api_requests(): void
    {
        User::factory()->create(['email' => 'alumno@utt.edu.mx']);

        $token = $this->login('alumno@utt.edu.mx')->json('token');

        $this->withToken($token)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('email', 'alumno@utt.edu.mx');
    }

    public function test_email_is_matched_case_insensitively(): void
    {
        User::factory()->create(['email' => 'alumno@utt.edu.mx']);

        $this->login('  Alumno@UTT.edu.mx ')->assertCreated();
    }

    public function test_wrong_password_is_rejected_without_issuing_a_token(): void
    {
        User::factory()->create(['email' => 'alumno@utt.edu.mx']);

        $this->login('alumno@utt.edu.mx', 'incorrecta')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'Las credenciales no son correctas.']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_unknown_email_gets_the_same_error_as_a_wrong_password(): void
    {
        $this->login('nadie@utt.edu.mx')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email' => 'Las credenciales no son correctas.']);
    }

    public function test_login_requires_email_and_password(): void
    {
        $this->postJson('/api/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_unverified_user_cannot_log_in(): void
    {
        User::factory()->unverified()->create(['email' => 'alumno@utt.edu.mx']);

        $this->login('alumno@utt.edu.mx')
            ->assertForbidden()
            ->assertJsonPath('message', 'Verifica tu correo institucional antes de iniciar sesión.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_account_is_locked_after_five_failed_attempts(): void
    {
        User::factory()->create(['email' => 'alumno@utt.edu.mx']);

        for ($i = 0; $i < LoginRequest::MAX_ATTEMPTS; $i++) {
            $this->login('alumno@utt.edu.mx', 'incorrecta')->assertUnprocessable();
        }

        // Ni siquiera la contraseña correcta entra mientras dura el bloqueo.
        $this->login('alumno@utt.edu.mx')
            ->assertTooManyRequests()
            ->assertJsonValidationErrors(['email' => 'Demasiados intentos fallidos. Intenta de nuevo en 15 minutos.']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_lockout_expires_after_the_lockout_period(): void
    {
        User::factory()->create(['email' => 'alumno@utt.edu.mx']);

        for ($i = 0; $i < LoginRequest::MAX_ATTEMPTS; $i++) {
            $this->login('alumno@utt.edu.mx', 'incorrecta');
        }

        $this->travel(LoginRequest::LOCKOUT_SECONDS + 1)->seconds();

        $this->login('alumno@utt.edu.mx')->assertCreated();
    }

    public function test_successful_login_resets_the_failed_attempt_counter(): void
    {
        User::factory()->create(['email' => 'alumno@utt.edu.mx']);

        for ($i = 0; $i < LoginRequest::MAX_ATTEMPTS - 1; $i++) {
            $this->login('alumno@utt.edu.mx', 'incorrecta');
        }

        $this->login('alumno@utt.edu.mx')->assertCreated();

        for ($i = 0; $i < LoginRequest::MAX_ATTEMPTS - 1; $i++) {
            $this->login('alumno@utt.edu.mx', 'incorrecta')->assertUnprocessable();
        }
    }

    public function test_expired_token_is_rejected(): void
    {
        User::factory()->create(['email' => 'alumno@utt.edu.mx']);

        $token = $this->login('alumno@utt.edu.mx')->json('token');

        $this->travel(config('sanctum.expiration') + 1)->minutes();

        $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        User::factory()->create(['email' => 'alumno@utt.edu.mx']);

        $phoneToken = $this->login('alumno@utt.edu.mx')->json('token');
        $laptopToken = $this->login('alumno@utt.edu.mx')->json('token');

        $this->withToken($phoneToken)->postJson('/api/logout')->assertNoContent();
        $this->forgetResolvedUser();

        $this->withToken($phoneToken)->getJson('/api/user')->assertUnauthorized();
        $this->forgetResolvedUser();

        $this->withToken($laptopToken)->getJson('/api/user')->assertOk();
    }

    public function test_logout_requires_authentication(): void
    {
        $this->postJson('/api/logout')->assertUnauthorized();
    }

    private function login(string $email, string $password = 'password'): TestResponse
    {
        return $this->postJson('/api/login', ['email' => $email, 'password' => $password]);
    }

    /**
     * Las pruebas reutilizan la misma aplicación entre peticiones; sin esto,
     * el guard recordaría al usuario de la petición anterior.
     */
    private function forgetResolvedUser(): void
    {
        $this->app['auth']->forgetGuards();
    }
}
