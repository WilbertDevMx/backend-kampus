<?php

namespace App\Discovery;

use App\Events\MatchCreated;
use App\Models\Swipe;
use App\Models\User;
use App\Models\UserMatch;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Guarda un swipe y, si es un "Me interesa" correspondido, crea el match y dispara `MatchCreated` (RF-07).
 */
final class RecordSwipe
{
    /**
     * @return UserMatch|null El match, o `null` si todavía no hay "Me interesa" de los dos lados.
     *
     * @throws UniqueConstraintViolationException Si `$swiper` ya había evaluado a `$target`.
     */
    public function handle(User $swiper, User $target, bool $liked): ?UserMatch
    {
        return DB::transaction(function () use ($swiper, $target, $liked): ?UserMatch {
            // Bloquea a los dos usuarios, siempre en el mismo orden. Si se dan "Me interesa" al mismo tiempo,
            // la segunda transacción espera a la primera y sí ve su swipe, así que el match no se pierde.
            User::query()
                ->whereKey([$swiper->getKey(), $target->getKey()])
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);

            Swipe::create([
                'swiper_id' => $swiper->getKey(),
                'swiped_id' => $target->getKey(),
                'liked' => $liked,
            ]);

            if (! $liked || ! $this->likedBack($swiper, $target)) {
                return null;
            }

            $match = UserMatch::createOrFirst(UserMatch::pairOf($swiper, $target));

            if ($match->wasRecentlyCreated) {
                MatchCreated::dispatch($match);
            }

            return $match;
        });
    }

    private function likedBack(User $swiper, User $target): bool
    {
        return Swipe::query()
            ->where('swiper_id', $target->getKey())
            ->where('swiped_id', $swiper->getKey())
            ->where('liked', true)
            ->exists();
    }
}
