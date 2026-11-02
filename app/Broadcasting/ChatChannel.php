<?php

namespace App\Broadcasting;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ChatChannel
{
    public function join(User $user, string $matchId): bool
    {
        if ($user->suspended_at !== null || ! ctype_digit($matchId)) {
            return false;
        }

        $match = DB::table('matches')
            ->where('id', $matchId)
            ->where(function (Builder $query) use ($user): void {
                $query->where('user_one_id', $user->getKey())
                    ->orWhere('user_two_id', $user->getKey());
            })
            ->first(['user_one_id', 'user_two_id']);

        if ($match === null) {
            return false;
        }

        $otherUserId = (string) (
            (string) $match->user_one_id === (string) $user->getKey()
                ? $match->user_two_id
                : $match->user_one_id
        );

        return ! DB::table('blocks')
            ->where(function (Builder $query) use ($user, $otherUserId): void {
                $query->where(function (Builder $query) use ($user, $otherUserId): void {
                    $query->where('blocker_id', $user->getKey())
                        ->where('blocked_id', $otherUserId);
                })->orWhere(function (Builder $query) use ($user, $otherUserId): void {
                    $query->where('blocker_id', $otherUserId)
                        ->where('blocked_id', $user->getKey());
                });
            })
            ->exists();
    }
}
