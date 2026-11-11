<?php

namespace Tests\Feature\Auth;

use App\Models\University;
use App\Models\User;
use App\Support\Security\TwoFactorChallenge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Verificación en dos pasos con app de autenticación (T-47).
 */
class TwoFactorApiTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->student = User::factory()->student(University::factory()->create())->create(['email' => 'ana@utt.edu.mx']);
    }

    public function test_enabling_asks_for_the_password_and_is_not_active_until_confirmed(): void
    {
        $token = $this->tokenFor($this->student);

        $this->as($token)->postJson('/api/two-factor', ['password' => 'incorrecta'])->assertInvalid(['password']);

        $response = $this->as($token)->postJson('/api/two-factor', ['password' => 'password'])->assertCreated();

        $secret = $response->json('data.secret');
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertStringStartsWith('otpauth://totp/', $response->json('data.otpauth_url'));
        $this->assertStringContainsString("secret={$secret}", $response->json('data.otpauth_url'));

        $this->as($token)->getJson('/api/two-factor')
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.pending_confirmation', true);

        // Sin confirmar, el login sigue entregando el token directamente.
        $this->login()->assertCreated()->assertJsonStructure(['token']);
    }

    public function test_confirming_with_a_valid_code_turns_it_on_and_returns_recovery_codes_once(): void
    {
        $token = $this->tokenFor($this->student);
        $secret = $this->as($token)->postJson('/api/two-factor', ['password' => 'password'])->json('data.secret');

        $this->as($token)->postJson('/api/two-factor/confirm', ['code' => '000000'])->assertInvalid(['code']);

        $codes = $this->as($token)->postJson('/api/two-factor/confirm', ['code' => $this->otp($secret)])
            ->assertOk()
            ->json('data.recovery_codes');

        $this->assertCount(8, $codes);
        $this->assertCount(8, array_unique($codes));
        $this->as($token)->getJson('/api/two-factor')
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.recovery_codes_left', 8);

        // El secreto y los códigos se guardan cifrados; los códigos, además, solo como hash.
        $row = DB::table('users')->where('id', $this->student->id)->first();
        $this->assertNotSame($secret, $row->two_factor_secret);
        $this->assertSame($secret, Crypt::decryptString($row->two_factor_secret));
        $this->assertStringNotContainsString($codes[0], Crypt::decryptString($row->two_factor_recovery_codes));

        // Nunca salen en /api/user.
        $this->assertStringNotContainsString('two_factor', $this->as($token)->getJson('/api/user')->getContent());
    }

    public function test_login_asks_for_the_code_and_then_issues_the_token(): void
    {
        $secret = $this->enableTwoFactor($this->student);

        $first = $this->login(['device_name' => 'iPhone de Ana'])
            ->assertOk()
            ->assertJsonPath('two_factor_required', true)
            ->assertJsonPath('expires_in', TwoFactorChallenge::TTL_SECONDS)
            ->assertJsonMissingPath('token');

        $this->postJson('/api/login/two-factor', [
            'challenge_token' => $first->json('challenge_token'),
            'code' => '123456',
        ])->assertInvalid(['code' => 'El código no es correcto.']);

        $response = $this->postJson('/api/login/two-factor', [
            'challenge_token' => $first->json('challenge_token'),
            'code' => $this->otp($secret),
        ])->assertCreated()->assertJsonStructure(['token', 'token_type', 'expires_at', 'user']);

        $this->assertSame('iPhone de Ana', $this->student->tokens()->latest('id')->firstOrFail()->name);
        $this->as($response->json('token'))->getJson('/api/user')->assertOk();

        // El reto es de un solo uso.
        $this->postJson('/api/login/two-factor', [
            'challenge_token' => $first->json('challenge_token'),
            'code' => $this->otp($secret),
        ])->assertInvalid(['challenge_token']);
    }

    public function test_a_code_cannot_be_used_twice(): void
    {
        $secret = $this->enableTwoFactor($this->student);
        $code = $this->otp($secret);

        $this->postJson('/api/login/two-factor', ['challenge_token' => $this->challenge(), 'code' => $code])->assertCreated();
        $this->postJson('/api/login/two-factor', ['challenge_token' => $this->challenge(), 'code' => $code])->assertInvalid(['code']);
    }

    public function test_recovery_codes_work_once(): void
    {
        $this->enableTwoFactor($this->student);
        $codes = $this->recoveryCodes;

        $this->postJson('/api/login/two-factor', ['challenge_token' => $this->challenge(), 'recovery_code' => strtoupper($codes[0])])
            ->assertCreated();
        $this->postJson('/api/login/two-factor', ['challenge_token' => $this->challenge(), 'recovery_code' => $codes[0]])
            ->assertInvalid(['recovery_code']);

        $this->assertCount(7, $this->student->fresh()->two_factor_recovery_codes);
    }

    public function test_the_challenge_dies_after_five_wrong_codes_or_five_minutes(): void
    {
        $this->enableTwoFactor($this->student);
        $challenge = $this->challenge();

        foreach (range(1, TwoFactorChallenge::MAX_ATTEMPTS - 1) as $attempt) {
            $this->postJson('/api/login/two-factor', ['challenge_token' => $challenge, 'code' => '000000'])->assertInvalid(['code']);
        }

        $this->postJson('/api/login/two-factor', ['challenge_token' => $challenge, 'code' => '000000'])->assertInvalid(['challenge_token']);
        $this->postJson('/api/login/two-factor', ['challenge_token' => $challenge, 'recovery_code' => $this->recoveryCodes[0]])
            ->assertInvalid(['challenge_token']);

        $late = $this->challenge();
        $this->travel(TwoFactorChallenge::TTL_SECONDS + 1)->seconds();
        $this->postJson('/api/login/two-factor', ['challenge_token' => $late, 'recovery_code' => $this->recoveryCodes[0]])
            ->assertInvalid(['challenge_token']);
    }

    public function test_disabling_and_regenerating_codes_ask_for_the_password(): void
    {
        $this->enableTwoFactor($this->student);
        $token = $this->tokenFor($this->student);
        $oldCodes = $this->recoveryCodes;

        $this->as($token)->postJson('/api/two-factor/recovery-codes', ['password' => 'incorrecta'])->assertInvalid(['password']);
        $newCodes = $this->as($token)->postJson('/api/two-factor/recovery-codes', ['password' => 'password'])
            ->assertOk()
            ->json('data.recovery_codes');
        $this->assertEmpty(array_intersect($oldCodes, $newCodes));
        $this->postJson('/api/login/two-factor', ['challenge_token' => $this->challenge(), 'recovery_code' => $oldCodes[0]])
            ->assertInvalid(['recovery_code']);

        // Estas rutas aceptan 6 peticiones por minuto.
        $this->travel(61)->seconds();
        $this->as($token)->deleteJson('/api/two-factor', ['password' => 'incorrecta'])->assertInvalid(['password']);
        $this->as($token)->deleteJson('/api/two-factor', ['password' => 'password'])->assertNoContent();

        $this->assertNull($this->student->fresh()->two_factor_secret);
        $this->login()->assertCreated()->assertJsonStructure(['token']);
    }

    public function test_it_is_the_second_factor_when_resetting_the_password(): void
    {
        $secret = $this->enableTwoFactor($this->student);
        $resetToken = Password::broker()->createToken($this->student);
        $payload = [
            'email' => 'ana@utt.edu.mx',
            'token' => $resetToken,
            'password' => 'NuevaClave2026',
            'password_confirmation' => 'NuevaClave2026',
        ];

        $this->postJson('/api/reset-password', $payload)->assertInvalid(['code']);
        $this->postJson('/api/reset-password', [...$payload, 'code' => '000000'])->assertInvalid(['code' => 'El código no es correcto.']);
        $this->assertTrue(Hash::check('password', $this->student->fresh()->password));

        $this->postJson('/api/reset-password', [...$payload, 'code' => $this->otp($secret)])->assertOk();
        $this->assertTrue(Hash::check('NuevaClave2026', $this->student->fresh()->password));
    }

    public function test_an_invalid_reset_link_does_not_reveal_that_the_account_has_two_factor(): void
    {
        $this->enableTwoFactor($this->student);

        $this->postJson('/api/reset-password', [
            'email' => 'ana@utt.edu.mx',
            'token' => 'inventado',
            'password' => 'NuevaClave2026',
            'password_confirmation' => 'NuevaClave2026',
        ])->assertInvalid(['email'])->assertJsonMissingValidationErrors(['code']);
    }

    public function test_staff_can_turn_it_on_too(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'admin@utt.edu.mx']);
        $token = $this->tokenFor($admin);
        $secret = $this->as($token)->postJson('/api/two-factor', ['password' => 'password'])->assertCreated()->json('data.secret');

        $this->as($token)->postJson('/api/two-factor/confirm', ['code' => $this->otp($secret)])->assertOk();

        $this->postJson('/api/login', ['email' => 'admin@utt.edu.mx', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('two_factor_required', true);
    }

    /** @var list<string> */
    private array $recoveryCodes = [];

    private function enableTwoFactor(User $user): string
    {
        $token = $this->tokenFor($user);
        $secret = $this->as($token)->postJson('/api/two-factor', ['password' => 'password'])->json('data.secret');
        $this->recoveryCodes = $this->as($token)->postJson('/api/two-factor/confirm', ['code' => $this->otp($secret)])
            ->assertOk()
            ->json('data.recovery_codes');

        // El código de la confirmación ya se usó: el siguiente tiene que ser de otro periodo.
        $this->travel(31)->seconds();

        return $secret;
    }

    private function challenge(): string
    {
        $this->app['auth']->forgetGuards();

        return $this->login()->assertOk()->json('challenge_token');
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function login(array $extra = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/login', ['email' => 'ana@utt.edu.mx', 'password' => 'password', ...$extra]);
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('pruebas')->plainTextToken;
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    /**
     * El código que mostraría la app del celular en este momento (según el reloj de Laravel).
     */
    private function otp(string $secret): string
    {
        return (new Google2FA)->oathTotp($secret, intdiv(now()->getTimestamp(), 30));
    }
}
