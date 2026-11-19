<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\SmsCodeNotification;
use App\Sms\SmsSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhoneApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    }

    public function test_adding_a_phone_sends_a_code_and_saves_it_only_after_verifying(): void
    {
        Notification::fake();

        $this->postJson('/api/phone', ['phone' => '(871) 123-4567', 'password' => 'password'])
            ->assertStatus(202)
            ->assertJsonPath('message', 'Te enviamos un código por SMS al ******4567.')
            ->assertJsonPath('expires_in', 600);

        $this->assertNull($this->user->fresh()->phone);
        $code = $this->sentCode('+528711234567');

        $this->postJson('/api/phone/verify', ['code' => $code])
            ->assertOk()
            ->assertJsonPath('data.phone', '+528711234567')
            ->assertJsonPath('data.verified', true);

        $user = $this->user->fresh();
        $this->assertSame('+528711234567', $user->phone);
        $this->assertNotNull($user->phone_verified_at);

        $this->getJson('/api/user')
            ->assertJsonPath('data.phone', '+528711234567')
            ->assertJsonPath('data.phone_verified', true);

        // Un solo uso.
        $this->postJson('/api/phone/verify', ['code' => $code])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code' => 'El código no es correcto o ya venció.']);
    }

    public function test_adding_a_phone_asks_for_the_password_and_a_mexican_number(): void
    {
        Notification::fake();

        $this->postJson('/api/phone', ['phone' => '8711234567', 'password' => 'otra'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password' => 'La contraseña no es correcta.']);

        $this->postJson('/api/phone', ['phone' => '12345', 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone' => 'Escribe un número de celular de México de 10 dígitos.']);

        Notification::assertNothingSent();
    }

    public function test_a_number_of_another_account_is_rejected(): void
    {
        Notification::fake();
        User::factory()->create()->forceFill(['phone' => '+528711234567', 'phone_verified_at' => now()])->save();

        $this->postJson('/api/phone', ['phone' => '+52 1 871 123 4567', 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone' => 'Ese número ya está registrado en otra cuenta.']);

        Notification::assertNothingSent();
    }

    public function test_codes_cannot_be_requested_too_often(): void
    {
        Notification::fake();

        $this->postJson('/api/phone', ['phone' => '8711234567', 'password' => 'password'])->assertStatus(202);
        $this->postJson('/api/phone', ['phone' => '8711234567', 'password' => 'password'])
            ->assertStatus(429)
            ->assertJsonPath('message', 'Ya te enviamos un código hace poco. Espera un momento antes de pedir otro.');

        Notification::assertSentOnDemandTimes(SmsCodeNotification::class, 1);
    }

    public function test_a_code_is_invalidated_after_five_wrong_attempts(): void
    {
        Notification::fake();
        $this->postJson('/api/phone', ['phone' => '8711234567', 'password' => 'password'])->assertStatus(202);
        $code = $this->sentCode('+528711234567');
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/phone/verify', ['code' => $wrong])->assertUnprocessable();
        }

        // La ruta permite 6 peticiones por minuto; se espera a que pase para probar el código ya invalidado.
        $this->travel(61)->seconds();

        $this->postJson('/api/phone/verify', ['code' => $code])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
        $this->assertNull($this->user->fresh()->phone);
    }

    public function test_removing_the_phone_asks_for_the_password(): void
    {
        $this->user->forceFill(['phone' => '+528711234567', 'phone_verified_at' => now()])->save();

        $this->deleteJson('/api/phone', ['password' => 'otra'])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->deleteJson('/api/phone', ['password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.verified', false);
        $this->assertNull($this->user->fresh()->phone);
    }

    public function test_the_sms_goes_through_the_configured_sender_and_the_queue(): void
    {
        $sender = new class implements SmsSender
        {
            /** @var list<array{to: string, message: string}> */
            public array $sent = [];

            public function send(string $to, string $message): void
            {
                $this->sent[] = ['to' => $to, 'message' => $message];
            }
        };
        $this->app->instance(SmsSender::class, $sender);

        $this->assertInstanceOf(ShouldQueue::class, new SmsCodeNotification(SmsCodeNotification::PURPOSE_PHONE, '123456'));

        $this->postJson('/api/phone', ['phone' => '8711234567', 'password' => 'password'])->assertStatus(202);

        $this->assertCount(1, $sender->sent);
        $this->assertSame('+528711234567', $sender->sent[0]['to']);
        $this->assertMatchesRegularExpression(
            '/^Kampus: tu código para verificar tu celular es \d{6}\. Vence en 10 minutos\./',
            $sender->sent[0]['message'],
        );
    }

    private function sentCode(string $phone): string
    {
        $code = null;

        Notification::assertSentOnDemand(
            SmsCodeNotification::class,
            function (SmsCodeNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($phone, &$code): bool {
                $code = $notification->code;

                return $channels === ['sms'] && $notifiable->routes['sms'] === $phone;
            },
        );

        return $code;
    }
}
