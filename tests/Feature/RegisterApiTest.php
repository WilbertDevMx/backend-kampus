<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class RegisterApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_accepts_allowed_domain_and_sends_verification_email(): void
    {
        config(['auth.allowed_email_domains' => ['kampus.edu']]);
        Notification::fake();

        $response = $this->postJson('/api/register', [
            'name' => 'Ada Student',
            'email' => 'ada@kampus.edu',
            'password' => 'secure-password',
            'password_confirmation' => 'secure-password',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.email', 'ada@kampus.edu')
            ->assertJsonMissingPath('user.password');
        $this->assertDatabaseHas('users', [
            'email' => 'ada@kampus.edu',
            'email_verified_at' => null,
        ]);

        Notification::assertSentToOnce(User::where('email', 'ada@kampus.edu')->firstOrFail(), VerifyEmail::class);
    }

    public function test_registration_rejects_unapproved_email_domain_without_creating_user(): void
    {
        config(['auth.allowed_email_domains' => ['kampus.edu']]);
        Notification::fake();

        $response = $this->postJson('/api/register', [
            'name' => 'Ada Student',
            'email' => 'ada@not-kampus.edu',
            'password' => 'secure-password',
            'password_confirmation' => 'secure-password',
        ]);

        $response->assertInvalid([
            'email' => 'El dominio del correo no está permitido.',
        ]);
        $this->assertDatabaseMissing('users', ['email' => 'ada@not-kampus.edu']);
        Notification::assertNothingSent();
    }

    public function test_signed_verification_link_marks_user_as_verified(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['email_verified_at' => null])->save();
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        $response = $this->getJson($verificationUrl);

        $response->assertOk()
            ->assertJsonPath('message', 'Correo verificado correctamente.');
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_signed_verification_link_rejects_a_wrong_email_hash(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['email_verified_at' => null])->save();
        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('someone-else@kampus.edu')],
        );

        $response = $this->getJson($verificationUrl);

        $response->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }
}
