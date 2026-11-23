<?php

namespace Tests\Feature\Account;

use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LegalAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Kampus2026segura';

    public function test_registration_stores_when_the_privacy_notice_and_rules_were_accepted(): void
    {
        $this->seed(CatalogSeeder::class);
        Notification::fake();
        $this->freezeSecond();

        $this->postJson('/api/register', [
            'name' => 'Ada',
            'email' => 'ada@uttcampus.edu.mx',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'accept_privacy' => true,
            'accept_rules' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('user.privacy_accepted_at', now()->toIso8601String())
            ->assertJsonPath('user.rules_accepted_at', now()->toIso8601String());
    }

    public function test_registration_rejects_unaccepted_documents_and_still_works_without_them(): void
    {
        $this->seed(CatalogSeeder::class);
        Notification::fake();

        $this->postJson('/api/register', [
            'name' => 'Ada',
            'email' => 'ada@uttcampus.edu.mx',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'accept_privacy' => false,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['accept_privacy' => 'Debes aceptar el aviso de privacidad.']);

        // Mientras la app agrega las casillas, el registro sin ellas sigue funcionando.
        $this->postJson('/api/register', [
            'name' => 'Ada',
            'email' => 'ada@uttcampus.edu.mx',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])
            ->assertCreated()
            ->assertJsonPath('user.privacy_accepted_at', null);
    }

    public function test_an_existing_account_accepts_the_documents(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->freezeSecond();

        $this->postJson('/api/legal/accept')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['privacy' => 'Acepta el aviso de privacidad o las normas de comunidad.']);

        $this->postJson('/api/legal/accept', ['rules' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rules' => 'Debes aceptar las normas de comunidad.']);

        $this->postJson('/api/legal/accept', ['privacy' => true])
            ->assertOk()
            ->assertJsonPath('data.privacy_accepted_at', now()->toIso8601String())
            ->assertJsonPath('data.rules_accepted_at', null);

        $this->postJson('/api/legal/accept', ['privacy' => true, 'rules' => true])->assertOk();

        $this->getJson('/api/user')
            ->assertJsonPath('data.privacy_accepted_at', now()->toIso8601String())
            ->assertJsonPath('data.rules_accepted_at', now()->toIso8601String());
    }
}
