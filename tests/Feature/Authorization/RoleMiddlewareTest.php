<?php

namespace Tests\Feature\Authorization;

use App\Models\University;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * El middleware `role` separa las rutas sociales (solo estudiantes) del panel (solo staff).
 * Las rutas reales llegan con T-09 y T-41 a T-43; aquí se prueban con rutas de ejemplo.
 */
class RoleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private University $utt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->utt = University::factory()->create();

        Route::middleware(['api', 'auth:sanctum', 'role:student'])
            ->get('/api/test/social', fn () => response()->json(['ok' => true]));
        Route::middleware(['api', 'auth:sanctum', 'role:moderator,admin'])
            ->get('/api/test/admin', fn () => response()->json(['ok' => true]));
    }

    public function test_student_reaches_social_routes_but_not_the_admin_panel(): void
    {
        Sanctum::actingAs(User::factory()->student($this->utt)->create());

        $this->getJson('/api/test/social')->assertOk();
        $this->getJson('/api/test/admin')
            ->assertForbidden()
            ->assertJsonPath('message', 'Tu cuenta no tiene acceso a esta sección.');
    }

    public function test_moderator_reaches_the_admin_panel_but_not_social_routes(): void
    {
        Sanctum::actingAs(User::factory()->moderator($this->utt)->create());

        $this->getJson('/api/test/admin')->assertOk();
        $this->getJson('/api/test/social')->assertForbidden();
    }

    public function test_admin_reaches_the_admin_panel_but_not_social_routes(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/test/admin')->assertOk();
        $this->getJson('/api/test/social')->assertForbidden();
    }

    public function test_guest_is_rejected_before_the_role_check(): void
    {
        $this->getJson('/api/test/admin')->assertUnauthorized();
    }
}
