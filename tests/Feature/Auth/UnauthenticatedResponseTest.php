<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Las rutas protegidas responden 401 o 403 en JSON aunque el cliente no mande `Accept: application/json`.
 * Antes Laravel intentaba redirigir a las rutas web `login` y `verification.notice`, que la API no tiene,
 * y respondía 500 (por ejemplo, "Route [login] not defined").
 */
class UnauthenticatedResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_protected_route_without_accept_header_returns_json_401(): void
    {
        $this->get('/api/user')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_protected_route_with_json_accept_header_still_returns_401(): void
    {
        $this->getJson('/api/user')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_invalid_token_without_accept_header_returns_json_401(): void
    {
        $this->withHeader('Authorization', 'Bearer token-que-no-existe')
            ->get('/api/user')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    public function test_unverified_account_without_accept_header_returns_json_403(): void
    {
        Sanctum::actingAs(User::factory()->unverified()->create());

        $this->get('/api/user')
            ->assertForbidden()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['message']);
    }
}
