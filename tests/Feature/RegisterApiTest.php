<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class RegisterApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_assigns_active_university_and_sends_verification_email(): void
    {
        $this->seed(CatalogSeeder::class);
        Notification::fake();

        $response = $this->postJson('/api/register', [
            'name' => 'Ada Student',
            'email' => 'ada@utt.edu.mx',
            'password' => 'secure-password',
            'password_confirmation' => 'secure-password',
        ]);

        $user = User::where('email', 'ada@utt.edu.mx')->firstOrFail();

        $response->assertCreated()
            ->assertJsonPath('user.email', 'ada@utt.edu.mx')
            ->assertJsonPath('user.university_id', $user->university_id)
            ->assertJsonMissingPath('user.password');
        $this->assertDatabaseHas('users', [
            'email' => 'ada@utt.edu.mx',
            'university_id' => $user->university_id,
            'email_verified_at' => null,
            'role' => 'student',
        ]);

        Notification::assertSentToOnce($user, VerifyEmail::class);
    }

    public function test_registration_rejects_a_domain_not_in_an_active_university(): void
    {
        $this->seed(CatalogSeeder::class);
        Notification::fake();

        $response = $this->postJson('/api/register', [
            'name' => 'Ada Student',
            'email' => 'ada@example.com',
            'password' => 'secure-password',
            'password_confirmation' => 'secure-password',
        ]);

        $response->assertInvalid([
            'email' => 'El dominio del correo no está permitido.',
        ]);
        $this->assertDatabaseMissing('users', ['email' => 'ada@example.com']);
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
