<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetApiTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'Kampus2026nueva';

    private const GENERIC_MESSAGE = 'Si el correo está registrado, te enviamos un enlace para restablecer tu contraseña.';

    public function test_a_registered_email_receives_a_reset_link_that_opens_the_app(): void
    {
        Notification::fake();
        config(['auth.password_reset_url' => 'https://app.kampus.test/auth/reset-password']);
        $user = User::factory()->create(['email' => 'alumno@utt.edu.mx']);

        $this->postJson('/api/forgot-password', ['email' => '  Alumno@UTT.edu.mx '])
            ->assertOk()
            ->assertExactJson(['message' => self::GENERIC_MESSAGE]);

        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($user): bool {
            $mail = $notification->toMail($user);

            return $notification instanceof ShouldQueue
                && $mail->subject === 'Restablece tu contraseña de Kampus'
                && $mail->actionText === 'Restablecer contraseña'
                && str_starts_with($mail->actionUrl, 'https://app.kampus.test/auth/reset-password?token=')
                && str_contains($mail->actionUrl, 'email=alumno%40utt.edu.mx')
                && Password::tokenExists($user, $notification->token);
        });
    }

    public function test_an_unknown_email_gets_the_same_answer_and_no_email(): void
    {
        Notification::fake();

        $this->postJson('/api/forgot-password', ['email' => 'nadie@utt.edu.mx'])
            ->assertOk()
            ->assertExactJson(['message' => self::GENERIC_MESSAGE]);

        Notification::assertNothingSent();
    }

    public function test_requesting_links_is_rate_limited(): void
    {
        Notification::fake();

        foreach (range(1, 6) as $attempt) {
            $this->postJson('/api/forgot-password', ['email' => "nadie{$attempt}@utt.edu.mx"])->assertOk();
        }

        $this->postJson('/api/forgot-password', ['email' => 'nadie7@utt.edu.mx'])->assertTooManyRequests();
    }

    public function test_the_email_is_required_and_must_be_valid(): void
    {
        $this->postJson('/api/forgot-password', [])->assertInvalid(['email']);
        $this->postJson('/api/forgot-password', ['email' => 'no-es-correo'])->assertInvalid(['email']);
    }

    public function test_a_valid_token_resets_the_password_and_closes_every_session(): void
    {
        Event::fake([PasswordReset::class]);
        $user = User::factory()->create(['email' => 'alumno@utt.edu.mx']);
        $user->createToken('iphone');
        $user->createToken('laptop');
        $token = Password::createToken($user);

        $this->postJson('/api/reset-password', [
            'token' => $token,
            'email' => 'ALUMNO@utt.edu.mx',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])
            ->assertOk()
            ->assertExactJson(['message' => 'Tu contraseña se restableció. Inicia sesión con la nueva.']);

        $user->refresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->password));
        $this->assertSame(0, $user->tokens()->count());
        Event::assertDispatched(PasswordReset::class, fn (PasswordReset $event) => $event->user->is($user));

        $this->postJson('/api/login', ['email' => 'alumno@utt.edu.mx', 'password' => 'password'])
            ->assertUnprocessable();
        $this->postJson('/api/login', ['email' => 'alumno@utt.edu.mx', 'password' => self::NEW_PASSWORD])
            ->assertCreated();
    }

    public function test_a_token_works_only_once(): void
    {
        $user = User::factory()->create(['email' => 'alumno@utt.edu.mx']);
        $payload = $this->resetPayload(Password::createToken($user));

        $this->postJson('/api/reset-password', $payload)->assertOk();

        $this->postJson('/api/reset-password', [...$payload, 'password' => 'Otra2026clave', 'password_confirmation' => 'Otra2026clave'])
            ->assertInvalid(['email' => 'El enlace para restablecer la contraseña no es válido o ya venció.']);
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
    }

    public function test_an_invalid_or_expired_token_and_an_unknown_email_get_the_same_error(): void
    {
        $user = User::factory()->create(['email' => 'alumno@utt.edu.mx']);
        $token = Password::createToken($user);
        $message = 'El enlace para restablecer la contraseña no es válido o ya venció.';

        $this->postJson('/api/reset-password', $this->resetPayload('token-falso'))
            ->assertInvalid(['email' => $message]);
        $this->postJson('/api/reset-password', [...$this->resetPayload($token), 'email' => 'otro@utt.edu.mx'])
            ->assertInvalid(['email' => $message]);

        $this->travel(61)->minutes();
        $this->postJson('/api/reset-password', $this->resetPayload($token))
            ->assertInvalid(['email' => $message]);

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_the_new_password_must_follow_the_policy(): void
    {
        $user = User::factory()->create(['email' => 'alumno@utt.edu.mx']);
        $token = Password::createToken($user);

        $this->postJson('/api/reset-password', [
            ...$this->resetPayload($token),
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertInvalid(['password']);
        $this->postJson('/api/reset-password', [
            ...$this->resetPayload($token),
            'password_confirmation' => 'Distinta2026',
        ])->assertInvalid(['password']);
        $this->postJson('/api/reset-password', ['email' => 'alumno@utt.edu.mx'])
            ->assertInvalid(['token', 'password']);

        $this->assertTrue(Password::tokenExists($user, $token));
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    /**
     * @return array<string, string>
     */
    private function resetPayload(string $token): array
    {
        return [
            'token' => $token,
            'email' => 'alumno@utt.edu.mx',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ];
    }
}
