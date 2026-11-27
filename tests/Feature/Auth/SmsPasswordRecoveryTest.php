<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\SmsCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SmsPasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const GENERIC_MESSAGE = 'Si la cuenta tiene un celular verificado, te enviamos un código por SMS. Vence en 10 minutos.';

    private const NEW_PASSWORD = 'Kampus2026nueva';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->user = User::factory()->create(['email' => 'alumno@uttcampus.edu.mx']);
        $this->user->forceFill(['phone' => '+528711234567', 'phone_verified_at' => now()])->save();
    }

    public function test_a_code_by_sms_is_exchanged_for_a_token_that_resets_the_password(): void
    {
        $this->user->createToken('celular viejo');

        $this->postJson('/api/forgot-password/sms', ['email' => ' Alumno@UTTcampus.edu.mx '])
            ->assertOk()
            ->assertExactJson(['message' => self::GENERIC_MESSAGE]);

        $code = $this->sentCode();

        $response = $this->postJson('/api/forgot-password/sms/verify', ['email' => 'alumno@uttcampus.edu.mx', 'code' => $code])
            ->assertOk()
            ->assertJsonPath('message', 'Código correcto. Ahora elige tu nueva contraseña.')
            ->assertJsonPath('email', 'alumno@uttcampus.edu.mx')
            ->assertJsonPath('two_factor_required', false);

        $this->postJson('/api/reset-password', [
            'token' => $response->json('token'),
            'email' => 'alumno@uttcampus.edu.mx',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $this->user->fresh()->password));
        $this->assertSame(0, $this->user->tokens()->count());

        // El código es de un solo uso.
        $this->postJson('/api/forgot-password/sms/verify', ['email' => 'alumno@uttcampus.edu.mx', 'code' => $code])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code' => 'El código no es correcto o ya venció.']);
    }

    public function test_accounts_without_a_verified_phone_or_unknown_emails_get_the_same_answer_and_no_sms(): void
    {
        User::factory()->create(['email' => 'sincelular@uttcampus.edu.mx']);

        $this->postJson('/api/forgot-password/sms', ['email' => 'sincelular@uttcampus.edu.mx'])
            ->assertOk()
            ->assertExactJson(['message' => self::GENERIC_MESSAGE]);
        $this->postJson('/api/forgot-password/sms', ['email' => 'nadie@uttcampus.edu.mx'])
            ->assertOk()
            ->assertExactJson(['message' => self::GENERIC_MESSAGE]);

        Notification::assertNothingSent();

        $this->postJson('/api/forgot-password/sms/verify', ['email' => 'nadie@uttcampus.edu.mx', 'code' => '123456'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code' => 'El código no es correcto o ya venció.']);
    }

    public function test_a_wrong_code_does_not_give_a_token(): void
    {
        $this->postJson('/api/forgot-password/sms', ['email' => 'alumno@uttcampus.edu.mx'])->assertOk();
        $code = $this->sentCode();

        $this->postJson('/api/forgot-password/sms/verify', [
            'email' => 'alumno@uttcampus.edu.mx',
            'code' => $code === '000000' ? '111111' : '000000',
        ])
            ->assertUnprocessable()
            ->assertJsonMissingPath('token');

        $this->postJson('/api/forgot-password/sms/verify', ['email' => 'alumno@uttcampus.edu.mx', 'code' => 'abc'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_the_second_factor_is_still_required_after_the_sms(): void
    {
        $this->user->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();

        $this->postJson('/api/forgot-password/sms', ['email' => 'alumno@uttcampus.edu.mx'])->assertOk();
        $response = $this->postJson('/api/forgot-password/sms/verify', ['email' => 'alumno@uttcampus.edu.mx', 'code' => $this->sentCode()])
            ->assertOk()
            ->assertJsonPath('two_factor_required', true);

        $this->postJson('/api/reset-password', [
            'token' => $response->json('token'),
            'email' => 'alumno@uttcampus.edu.mx',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_only_one_code_is_sent_per_minute(): void
    {
        $this->postJson('/api/forgot-password/sms', ['email' => 'alumno@uttcampus.edu.mx'])->assertOk();
        $this->postJson('/api/forgot-password/sms', ['email' => 'alumno@uttcampus.edu.mx'])
            ->assertOk()
            ->assertExactJson(['message' => self::GENERIC_MESSAGE]);

        Notification::assertSentOnDemandTimes(SmsCodeNotification::class, 1);
    }

    private function sentCode(): string
    {
        $code = null;

        Notification::assertSentOnDemand(
            SmsCodeNotification::class,
            function (SmsCodeNotification $notification, array $channels, AnonymousNotifiable $notifiable) use (&$code): bool {
                $code = $notification->code;

                return $notifiable->routes['sms'] === '+528711234567'
                    && str_contains($notification->toSms($notifiable), 'restablecer tu contraseña');
            },
        );

        return $code;
    }
}
