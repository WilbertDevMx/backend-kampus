<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class RegisterApiTest extends TestCase
{
    use RefreshDatabase;

    /** Cumple `Password::defaults()`: letras, mayúsculas, minúsculas y números. */
    private const PASSWORD = 'Kampus2026segura';

    public function test_registration_assigns_active_university_and_sends_verification_email(): void
    {
        $this->seed(CatalogSeeder::class);
        Notification::fake();

        $response = $this->postJson('/api/register', [
            'name' => 'Ada Student',
            'email' => 'ada@uttcampus.edu.mx',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ]);

        $user = User::where('email', 'ada@uttcampus.edu.mx')->firstOrFail();

        $response->assertCreated()
            ->assertJsonPath('user.email', 'ada@uttcampus.edu.mx')
            ->assertJsonMissingPath('user.password');
        $this->assertDatabaseHas('users', [
            'email' => 'ada@uttcampus.edu.mx',
            'university_id' => $user->university_id,
            'email_verified_at' => null,
            'role' => 'student',
        ]);

        Notification::assertSentToOnce($user, VerifyEmailNotification::class);
    }

    public function test_verification_email_is_queued_instead_of_sent_during_the_request(): void
    {
        $this->seed(CatalogSeeder::class);
        Queue::fake();

        $this->postJson('/api/register', [
            'name' => 'Ada Student',
            'email' => 'ada@uttcampus.edu.mx',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertCreated();

        Queue::assertPushed(
            SendQueuedNotifications::class,
            fn (SendQueuedNotifications $job) => $job->notification instanceof VerifyEmailNotification,
        );
    }

    public function test_registration_rejects_a_domain_not_in_an_active_university(): void
    {
        $this->seed(CatalogSeeder::class);
        Notification::fake();

        $response = $this->postJson('/api/register', [
            'name' => 'Ada Student',
            'email' => 'ada@example.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ]);

        $response->assertInvalid([
            'email' => 'El dominio del correo no está permitido.',
        ]);
        $this->assertDatabaseMissing('users', ['email' => 'ada@example.com']);
        Notification::assertNothingSent();
    }

    public function test_registration_stores_the_email_trimmed_and_in_lowercase(): void
    {
        $this->seed(CatalogSeeder::class);
        Notification::fake();

        $this->postJson('/api/register', [
            'name' => 'Ada Student',
            'email' => '  Ada@UTTcampus.edu.mx ',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertCreated()->assertJsonPath('user.email', 'ada@uttcampus.edu.mx');

        $this->assertDatabaseHas('users', ['email' => 'ada@uttcampus.edu.mx']);
    }

    public function test_registration_rejects_an_email_that_differs_only_in_case(): void
    {
        $this->seed(CatalogSeeder::class);
        Notification::fake();
        User::factory()->create(['email' => 'ada@uttcampus.edu.mx']);

        $this->postJson('/api/register', [
            'name' => 'Ada Student',
            'email' => 'ADA@uttcampus.edu.mx',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertInvalid(['email']);

        $this->assertDatabaseCount('users', 1);
    }

    public function test_public_registration_always_creates_a_student(): void
    {
        $this->seed(CatalogSeeder::class);
        Notification::fake();

        $this->postJson('/api/register', [
            'name' => 'Ada Student',
            'email' => 'ada@uttcampus.edu.mx',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'role' => 'admin',
        ])->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'ada@uttcampus.edu.mx', 'role' => 'student']);
    }

    public function test_registration_rejects_a_weak_password(): void
    {
        $this->seed(CatalogSeeder::class);
        Notification::fake();

        $this->postJson('/api/register', [
            'name' => 'Ada Student',
            'email' => 'ada@uttcampus.edu.mx',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertInvalid(['password']);

        $this->assertDatabaseMissing('users', ['email' => 'ada@uttcampus.edu.mx']);
        Notification::assertNothingSent();
    }

    public function test_signed_verification_link_marks_the_user_as_verified(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['email_verified_at' => null])->save();
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)],
            absolute: false,
        );

        $this->getJson($verificationUrl)
            ->assertOk()
            ->assertJsonPath('message', 'Correo verificado correctamente.');

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_verification_email_links_to_the_frontend_and_its_params_verify_the_user(): void
    {
        config(['app.frontend_url' => 'http://app.test']);
        $user = User::factory()->create();
        $user->forceFill(['email_verified_at' => null])->save();

        $mail = (new VerifyEmailNotification)->toMail($user);

        $this->assertStringStartsWith('http://app.test/auth/verify-email?', $mail->actionUrl);

        // Lo mismo que hace la pantalla del frontend: arma la llamada a la API con los parámetros.
        parse_str((string) parse_url($mail->actionUrl, PHP_URL_QUERY), $params);
        $query = http_build_query(['expires' => $params['expires'], 'signature' => $params['signature']]);

        $this->getJson("/api/email/verify/{$params['id']}/{$params['hash']}?{$query}")->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_verification_rejects_a_tampered_signature(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['email_verified_at' => null])->save();
        $hash = sha1($user->email);

        $this->getJson("/api/email/verify/{$user->id}/{$hash}?expires=".now()->addHour()->timestamp.'&signature=falsa')
            ->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_verification_rejects_a_hash_for_a_different_email(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['email_verified_at' => null])->save();
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('other@uttcampus.edu.mx')],
            absolute: false,
        );

        $this->getJson($verificationUrl)->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }
}
