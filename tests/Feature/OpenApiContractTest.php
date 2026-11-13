<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contrato OpenAPI de la API (T-33, RNF-07). `docs/openapi.json` se genera desde el código con Scramble; esta
 * prueba falla si alguien cambia una ruta, una validación o una respuesta y no lo regenera.
 */
class OpenApiContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_committed_contract_matches_the_code(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'openapi');

        try {
            $this->artisan('scramble:export', ['--path' => $path])->assertSuccessful();

            $this->assertSame(
                $this->comparable((string) file_get_contents(base_path('docs/openapi.json'))),
                $this->comparable((string) file_get_contents($path)),
                'docs/openapi.json no coincide con el código. Regenéralo con `php artisan scramble:export` y súbelo en el mismo PR.',
            );
        } finally {
            @unlink($path);
        }
    }

    public function test_public_routes_are_marked_without_token_and_the_rest_use_sanctum(): void
    {
        $spec = json_decode((string) file_get_contents(base_path('docs/openapi.json')), true);

        $this->assertSame([['http' => []]], $spec['security']);
        $this->assertSame('bearer', $spec['components']['securitySchemes']['http']['scheme']);

        $public = [];
        foreach ($spec['paths'] as $route => $operations) {
            foreach ($operations as $method => $operation) {
                if (is_array($operation) && ($operation['security'] ?? null) === []) {
                    $public[] = strtoupper($method).' '.$route;
                }
            }
        }
        sort($public);

        $this->assertSame([
            'GET /email/verify/{id}/{hash}',
            'POST /forgot-password',
            'POST /login',
            'POST /login/two-factor',
            'POST /register',
            'POST /reset-password',
        ], $public);
    }

    public function test_the_interactive_docs_are_only_open_where_enabled(): void
    {
        config(['kampus.api_docs_enabled' => true]);
        $this->get('/docs/api')->assertOk();
        $this->get('/docs/api.json')->assertOk()->assertJsonPath('info.title', 'Kampus · API');

        config(['kampus.api_docs_enabled' => false]);
        $this->get('/docs/api')->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get('/docs/api.json')->assertForbidden();
    }

    /**
     * El servidor sale de APP_URL, que cambia en cada máquina: se compara todo lo demás.
     *
     * @return array<string, mixed>
     */
    private function comparable(string $json): array
    {
        $spec = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        unset($spec['servers']);

        return $spec;
    }
}
