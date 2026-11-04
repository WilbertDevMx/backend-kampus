<?php

namespace App\Broadcasting;

use App\Models\User;

class UserChannel
{
    public function join(User $user, string $userId): bool
    {
        return $user->suspended_at === null
            && ctype_digit($userId)
            && (string) $user->getKey() === $userId;
    }
}
