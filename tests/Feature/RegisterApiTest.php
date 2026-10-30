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
            'email' => 'ada@utt.edu.mx',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ]);

        $user = User::where('email', 'ada@utt.edu.mx')->firstOrFail();

        $response->assertCreated()
            ->assertJsonPath('user.email', 'ada@utt.edu.mx')
            ->assertJsonMissingPath('user.password');
        $this->assertDatabaseHas('users', [
            'email' => 'ada@utt.edu.mx',
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
            'email' => 'ada@utt.edu.mx',
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
            'email' => '  Ada@UTT.edu.mx ',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertCreated()->assertJsonPath('user.email', 'ada@utt.edu.mx');

        $this->assertDatabaseHas('users', ['email' => 'ada@utt.edu.mx']);
    }

    public function test_registration_rejects_an_email_that_differs_only_in_case(): void
    {
        $this->seed(CatalogSeeder::class);
        Notification::fake();
        User::factory()->create(['email' => 'ada@utt.edu.mx']);

        $this->postJson('/api/register', [
            'name' => 'Ada Student',
            'email' => 'ADA@utt.edu.mx',
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
            'email' => 'ada@utt.edu.mx',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'role' => 'admin',
        ])->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'ada@utt.edu.mx', 'role' => 'student']);
    }

    public function test_registration_rejects_a_weak_password(): void
    {
        $this->seed(CatalogSeeder::class);
        Notification::fake();

        $this->postJson('/api/register', [
            'name' => 'Ada Student',
            'email' => 'ada@utt.edu.mx',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertInvalid(['password']);

        $this->assertDatabaseMissing('users', ['email' => 'ada@utt.edu.mx']);
        Notification::assertNothingSent();
    }

    public function test_validation_errors_are_returned_in_spanish(): void
    {
        $this->seed(CatalogSeeder::class);
        User::factory()->create(['email' => 'ada@utt.edu.mx']);

        $this->postJson('/api/register', [
            'email' => 'ada@utt.edu.mx',
            'password' => self::PASSWORD,
            'password_confirmation' => 'OtraDistinta2026',
        ])->assertInvalid([
            'name' => 'El campo nombre es obligatorio.',
            'email' => 'El correo ya está registrado.',
            'password' => 'La confirmación de contraseña no coincide.',
        ]);
    }

    public function test_signed_verification_link_marks_the_user_as_verified(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['email_verified_at' => null])->save();
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        $this->getJson($verificationUrl)
            ->assertOk()
            ->assertJsonPath('message', 'Correo verificado correctamente.');

        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_verification_rejects_a_hash_for_a_different_email(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['email_verified_at' => null])->save();
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('other@utt.edu.mx')],
        );

        $this->getJson($verificationUrl)->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }
}
