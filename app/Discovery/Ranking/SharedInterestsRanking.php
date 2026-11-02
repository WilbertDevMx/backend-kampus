<?php

namespace App\Discovery\Ranking;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Primero los perfiles con más intereses en común; a igualdad, los de la misma universidad.
 * El último criterio (id) deja el orden estable entre peticiones.
 */
final class SharedInterestsRanking implements DiscoveryRanking
{
    public function apply(Builder $query, User $viewer): void
    {
        $query->orderByDesc('shared_interests')
            ->orderByRaw('CASE WHEN users.university_id = ? THEN 0 ELSE 1 END', [$viewer->university_id])
            ->orderBy('users.id');
    }
}
