<?php

namespace Tests\Feature\Security;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Errores de base de datos en la API (T-52): estado y mensaje para el usuario, nunca el detalle de PDO.
 */
class DatabaseErrorResponseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Aunque el entorno tenga APP_DEBUG=true, el detalle no debe salir.
        config(['app.debug' => true]);
    }

    /**
     * @return array<string, array{string, string|int, int, string}>
     */
    public static function failures(): array
    {
        return [
            'PostgreSQL apagado' => [
                'SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 5432 failed: Connection refused',
                '08006', 503, 'database_unavailable',
            ],
            'MySQL apagado' => [
                'SQLSTATE[HY000] [2002] Connection refused',
                2002, 503, 'database_unavailable',
            ],
            'interbloqueo' => [
                'SQLSTATE[40P01]: Deadlock detected: 7 ERROR: deadlock detected',
                '40P01', 503, 'database_busy',
            ],
            'duplicado' => [
                'SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint "users_email_unique"',
                '23505', 409, 'database_conflict',
            ],
            'otro error' => [
                'SQLSTATE[42P01]: Undefined table: 7 ERROR: relation "cache" does not exist',
                '42P01', 500, 'database_error',
            ],
        ];
    }

    #[DataProvider('failures')]
    public function test_api_hides_database_details(string $pdoMessage, string|int $pdoCode, int $status, string $code): void
    {
        $this->throwOnRoute(new QueryException('pgsql', 'select * from "cache" where "key" in (?)', ['kampus-cache-x'], $this->pdoException($pdoMessage, $pdoCode)));

        $response = $this->getJson('/api/_test/db-error');

        $response->assertStatus($status)->assertJsonPath('code', $code);
        $body = $response->getContent();
        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('127.0.0.1', $body);
        $this->assertStringNotContainsString('select', $body);
        $this->assertArrayNotHasKey('trace', $response->json());
    }

    public function test_unavailable_database_tells_the_client_when_to_retry(): void
    {
        $this->throwOnRoute($this->pdoException('SQLSTATE[08006] [7] Connection refused', '08006'));

        $this->getJson('/api/_test/db-error')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '30')
            ->assertJsonPath('message', 'El servicio no está disponible en este momento. Intenta de nuevo en unos minutos.');
    }

    private function throwOnRoute(\Throwable $e): void
    {
        Route::get('/api/_test/db-error', fn () => throw $e);
    }

    private function pdoException(string $message, string|int $code): PDOException
    {
        $e = new PDOException($message);
        $property = new \ReflectionProperty(\Exception::class, 'code');
        $property->setValue($e, $code);

        if (is_string($code)) {
            $e->errorInfo = [$code, 7, $message];
        }

        return $e;
    }
}
