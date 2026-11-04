<?php

namespace App\Broadcasting;

use App\Models\User;
use App\Models\UserMatch;

class ChatChannel
{
    /**
     * Solo los dos integrantes del match, con las mismas reglas que la lista de matches (T-10): ninguno de
     * los dos suspendido y sin bloqueo en ningún sentido (RF-13).
     */
    public function join(User $user, string $matchId): bool
    {
        if ($user->suspended_at !== null || ! ctype_digit($matchId)) {
            return false;
        }

        return UserMatch::query()
            ->visibleTo($user)
            ->whereKey((int) $matchId)
            ->exists();
    }
}
