<?php

namespace Tests\Feature\Console;

use App\Enums\UserRole;
use App\Models\University;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Kampus2026segura';

    protected function setUp(): void
    {
        parent::setUp();

        University::factory()->create(['short_name' => 'UTT', 'email_domains' => ['utt.edu.mx']]);
    }

    public function test_creates_a_verified_admin_without_university(): void
    {
        $this->artisan('kampus:create-admin', ['--name' => 'Ana Admin', '--email' => '  Ana.Admin@UTT.edu.mx '])
            ->expectsQuestion('Contraseña', self::PASSWORD)
            ->expectsQuestion('Confirma la contraseña', self::PASSWORD)
            ->assertSuccessful();

        $admin = User::where('email', 'ana.admin@utt.edu.mx')->firstOrFail();

        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertNull($admin->university_id);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertTrue(Hash::check(self::PASSWORD, $admin->password));
    }

    public function test_asks_for_name_and_email_when_not_given(): void
    {
        $this->artisan('kampus:create-admin')
            ->expectsQuestion('Nombre', 'Ana Admin')
            ->expectsQuestion('Correo institucional', 'ana@utt.edu.mx')
            ->expectsQuestion('Contraseña', self::PASSWORD)
            ->expectsQuestion('Confirma la contraseña', self::PASSWORD)
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'ana@utt.edu.mx', 'role' => 'admin']);
    }

    public function test_rejects_an_email_outside_the_participating_institutions(): void
    {
        $this->artisan('kampus:create-admin', ['--name' => 'Ana', '--email' => 'ana@gmail.com'])
            ->expectsQuestion('Contraseña', self::PASSWORD)
            ->expectsQuestion('Confirma la contraseña', self::PASSWORD)
            ->expectsOutputToContain('El correo debe ser de una institución participante.')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_rejects_a_weak_password(): void
    {
        $this->artisan('kampus:create-admin', ['--name' => 'Ana', '--email' => 'ana@utt.edu.mx'])
            ->expectsQuestion('Contraseña', 'password')
            ->expectsQuestion('Confirma la contraseña', 'password')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_rejects_a_password_confirmation_that_does_not_match(): void
    {
        $this->artisan('kampus:create-admin', ['--name' => 'Ana', '--email' => 'ana@utt.edu.mx'])
            ->expectsQuestion('Contraseña', self::PASSWORD)
            ->expectsQuestion('Confirma la contraseña', 'Otra2026distinta')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_rejects_an_email_that_already_has_an_account(): void
    {
        User::factory()->create(['email' => 'ana@utt.edu.mx']);

        $this->artisan('kampus:create-admin', ['--name' => 'Ana', '--email' => 'ana@utt.edu.mx'])
            ->expectsQuestion('Contraseña', self::PASSWORD)
            ->expectsQuestion('Confirma la contraseña', self::PASSWORD)
            ->assertFailed();

        $this->assertSame(UserRole::Student, User::where('email', 'ana@utt.edu.mx')->first()->role);
    }
}
