<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Borra archivos del bucket después de eliminar una cuenta (T-23, RF-16): fotos del perfil, imágenes del
 * chat y la imagen de los grupos que se quedaron sin integrantes.
 *
 * Va por la cola para no depender de que el bucket responda en la misma petición. Si un archivo no se
 * borra, el trabajo falla y se reintenta durante varias horas, muy por debajo del límite de 72 horas.
 */
class DeleteStoredFiles implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    /**
     * @param  list<string>  $paths
     */
    public function __construct(
        public readonly string $disk,
        public readonly array $paths,
    ) {}

    /**
     * Segundos entre intentos: 1 minuto, 5 minutos, 30 minutos, 2 horas y 6 horas.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 1800, 7200, 21600];
    }

    public function handle(): void
    {
        $storage = Storage::disk($this->disk);
        $pending = [];

        foreach ($this->paths as $path) {
            // `delete` devuelve false si el archivo ya no existe; eso también cuenta como borrado.
            if (! $storage->delete($path) && $storage->exists($path)) {
                $pending[] = $path;
            }
        }

        if ($pending !== []) {
            throw new RuntimeException(count($pending).' archivo(s) del disco '.$this->disk.' no se pudieron borrar.');
        }
    }

    public function failed(?Throwable $exception): void
    {
        // Sin rutas en el log: incluyen el id de la cuenta borrada. Se ven en `failed_jobs` si hace falta.
        Log::channel('security')->error('No se borraron los archivos de una cuenta eliminada.', [
            'disk' => $this->disk,
            'files' => count($this->paths),
            'error' => $exception?->getMessage(),
        ]);
    }
}
