<?php

namespace App\Support\Database;

use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Contracts\Database\LostConnectionDetector;
use Illuminate\Http\JsonResponse;
use PDOException;

/**
 * Respuesta de la API cuando falla la base de datos (T-52).
 *
 * El mensaje de PDO trae host, puerto, base de datos y la consulta SQL; con `APP_DEBUG=true` Laravel
 * lo mandaba tal cual a la app. Aquí se traduce el SQLSTATE a un estado HTTP y un mensaje para el
 * usuario, con un `code` propio para que la app lo distinga. El detalle sigue yendo al log, porque
 * el render no evita que Laravel reporte la excepción.
 */
class DatabaseErrorResponse
{
    public function __construct(
        private readonly LostConnectionDetector $lostConnection,
        private readonly ConcurrencyErrorDetector $concurrency,
    ) {}

    public function render(PDOException $e): JsonResponse
    {
        [$status, $code, $message] = $this->classify($e);

        $headers = $status === 503 ? ['Retry-After' => '30'] : [];

        return response()->json(['message' => $message, 'code' => $code], $status, $headers);
    }

    /**
     * @return array{int, string, string}
     */
    private function classify(PDOException $e): array
    {
        $sqlState = $this->sqlState($e);

        // Clase 08: no hay conexión (servidor apagado, credenciales, red). En MySQL llega como HY000 [2002].
        if (str_starts_with($sqlState, '08') || $this->lostConnection->causedByLostConnection($e)) {
            return [503, 'database_unavailable', 'El servicio no está disponible en este momento. Intenta de nuevo en unos minutos.'];
        }

        // Interbloqueo o fallo de serialización: es pasajero, se puede reintentar.
        if ($this->concurrency->causedByConcurrencyError($e)) {
            return [503, 'database_busy', 'El servidor está ocupado. Intenta de nuevo en unos segundos.'];
        }

        // Clase 23: violación de integridad (duplicado, llave foránea, nulo). La validación debería
        // atraparlo antes; si llega aquí es una carrera entre dos peticiones.
        if (str_starts_with($sqlState, '23')) {
            return [409, 'database_conflict', 'No se pudo guardar porque el dato ya existe o está en uso. Revisa e intenta de nuevo.'];
        }

        return [500, 'database_error', 'Ocurrió un error al procesar la solicitud. Intenta de nuevo.'];
    }

    /**
     * SQLSTATE de cinco caracteres: de `errorInfo` si PDO lo llenó, si no del código o del mensaje.
     */
    private function sqlState(PDOException $e): string
    {
        $candidates = [$e->errorInfo[0] ?? null, $e->getCode()];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && preg_match('/^[0-9A-Z]{5}$/', $candidate)) {
                return $candidate;
            }
        }

        return preg_match('/SQLSTATE\[([0-9A-Z]{5})\]/', $e->getMessage(), $m) ? $m[1] : '';
    }
}
