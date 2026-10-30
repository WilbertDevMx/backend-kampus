<?php

namespace Tests\Feature\Security;

use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_responses_include_security_headers(): void
    {
        $response = $this->getJson('/api/user');

        $response->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_hsts_is_only_sent_over_https(): void
    {
        $this->getJson('/api/user')->assertHeaderMissing('Strict-Transport-Security');

        $this->getJson('https://localhost/api/user')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_api_is_rate_limited_per_user(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < AppServiceProvider::API_REQUESTS_PER_MINUTE; $i++) {
            $this->actingAs($user, 'sanctum')->getJson('/api/user')->assertOk();
        }

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/user')
            ->assertTooManyRequests()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_cors_only_allows_the_configured_frontend(): void
    {
        config(['cors.allowed_origins' => ['https://app.kampus.mx']]);

        $this->withHeaders(['Origin' => 'https://app.kampus.mx'])
            ->getJson('/api/user')
            ->assertHeader('Access-Control-Allow-Origin', 'https://app.kampus.mx');

        // Con un solo origen permitido, la cabecera siempre trae ese origen; el navegador
        // bloquea la respuesta porque no coincide con el sitio que hizo la petición.
        $allowed = $this->withHeaders(['Origin' => 'https://sitio-malicioso.com'])
            ->getJson('/api/user')
            ->headers->get('Access-Control-Allow-Origin');

        $this->assertNotSame('https://sitio-malicioso.com', $allowed);
        $this->assertNotSame('*', $allowed);
    }

    public function test_user_endpoint_only_exposes_public_fields(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/user')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'email_verified_at' => $user->email_verified_at->toIso8601String(),
                    'role' => 'student',
                    'university_id' => null,
                ],
            ]);
    }

    public function test_password_policy_requires_letters_mixed_case_and_numbers(): void
    {
        $rules = ['password' => ['required', Password::defaults()]];

        foreach (['corto1A', 'solominusculas1', 'SOLOMAYUSCULAS1', 'SinNumerosAqui'] as $weak) {
            $this->assertTrue(Validator::make(['password' => $weak], $rules)->fails(), "Debería rechazar: {$weak}");
        }

        $this->assertTrue(Validator::make(['password' => 'Kampus2026'], $rules)->passes());
    }

    public function test_failed_login_and_lockout_are_logged_with_a_masked_email(): void
    {
        $logger = Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->with('security')->andReturn($logger);

        User::factory()->create(['email' => 'alumno@utt.edu.mx']);

        for ($i = 0; $i <= LoginRequest::MAX_ATTEMPTS; $i++) {
            $this->postJson('/api/login', ['email' => 'alumno@utt.edu.mx', 'password' => 'incorrecta']);
        }

        $logger->shouldHaveReceived('notice')
            ->with('Intento fallido de inicio de sesión.', Mockery::on(
                fn (array $context) => $context['email'] === 'al****@utt.edu.mx' && $context['ip'] === '127.0.0.1'
            ))
            ->times(LoginRequest::MAX_ATTEMPTS);

        $logger->shouldHaveReceived('warning')
            ->with('Bloqueo por intentos fallidos de inicio de sesión.', Mockery::on(
                fn (array $context) => $context['email'] === 'al****@utt.edu.mx'
            ))
            ->once();
    }

    public function test_registration_and_verification_events_are_logged(): void
    {
        $logger = Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->with('security')->andReturn($logger);

        $user = User::factory()->create();

        event(new Registered($user));
        event(new Verified($user));

        $logger->shouldHaveReceived('info')->with('Cuenta registrada.', ['user_id' => $user->id])->once();
        $logger->shouldHaveReceived('info')->with('Correo verificado.', ['user_id' => $user->id])->once();
    }
}
