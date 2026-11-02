<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Celda aproximada del alumno para "Cerca de ti" (RF-18, RNF-11): un geohash de precisión 6 (~1 km),
 * calculado en el dispositivo. Nunca se guarda una coordenada exacta. La fila solo existe mientras
 * "Cerca de ti" está activo.
 */
#[Fillable(['user_id', 'geohash'])]
class UserLocation extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
