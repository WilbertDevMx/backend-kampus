<?php

namespace App\Discovery\Ranking;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Criterio para ordenar los perfiles del descubrimiento (patrón Strategy, RF-05).
 *
 * Se puede cambiar o combinar después de las pruebas de usabilidad sin tocar `DiscoveryQuery`.
 * La consulta que recibe ya trae la columna `shared_interests` (intereses en común con `$viewer`).
 */
interface DiscoveryRanking
{
    /**
     * @param  Builder<User>  $query
     */
    public function apply(Builder $query, User $viewer): void;
}
