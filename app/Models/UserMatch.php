<?php

namespace App\Models;

use Database\Factories\UserMatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Coincidencia mutua entre dos estudiantes (RF-07). Se llama `UserMatch` porque `match` es palabra
 * reservada de PHP; la tabla es `matches`.
 *
 * El par se guarda siempre ordenado (`user_one_id < user_two_id`), así un mismo par no puede tener dos
 * matches: la llave única de la tabla lo impide.
 */
#[Fillable(['user_one_id', 'user_two_id'])]
class UserMatch extends Model
{
    /** @use HasFactory<UserMatchFactory> */
    use HasFactory;

    protected $table = 'matches';

    /**
     * Columnas del par ordenado, para buscar o crear el match entre `$a` y `$b`.
     *
     * @return array{user_one_id: int, user_two_id: int}
     */
    public static function pairOf(User $a, User $b): array
    {
        $ids = [(int) $a->getKey(), (int) $b->getKey()];
        sort($ids);

        return ['user_one_id' => $ids[0], 'user_two_id' => $ids[1]];
    }

    /**
     * Relaciones que necesitan las tarjetas de los dos usuarios (`MatchedUserResource`).
     *
     * @return array<string, mixed>
     */
    public static function userSummaryRelations(): array
    {
        $photosInOrder = fn ($photos) => $photos->orderBy('position');

        return [
            'userOne.university',
            'userOne.profile.photos' => $photosInOrder,
            'userTwo.university',
            'userTwo.profile.photos' => $photosInOrder,
        ];
    }

    public function userOne(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_one_id');
    }

    public function userTwo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_two_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'match_id');
    }

    /**
     * La otra persona del match, vista desde `$user`.
     */
    public function otherUser(User $user): User
    {
        return (int) $this->user_one_id === (int) $user->getKey() ? $this->userTwo : $this->userOne;
    }

    /**
     * @param  Builder<UserMatch>  $query
     */
    public function scopeInvolving(Builder $query, User $user): void
    {
        $query->where(fn (Builder $pair) => $pair
            ->where('matches.user_one_id', $user->getKey())
            ->orWhere('matches.user_two_id', $user->getKey()));
    }

    /**
     * Matches que `$user` ve en su lista: los suyos, sin cuentas suspendidas y sin bloqueo entre los dos.
     * Un bloqueo quita el match de la lista de ambos (RF-13).
     *
     * @param  Builder<UserMatch>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->involving($user)
            ->whereHas('userOne', fn (Builder $one) => $one->whereNull('suspended_at'))
            ->whereHas('userTwo', fn (Builder $two) => $two->whereNull('suspended_at'))
            ->whereNotExists(fn (QueryBuilder $blocks) => $blocks->select(DB::raw(1))
                ->from('blocks')
                ->where(fn (QueryBuilder $pair) => $pair
                    ->where(fn (QueryBuilder $byOne) => $byOne
                        ->whereColumn('blocks.blocker_id', 'matches.user_one_id')
                        ->whereColumn('blocks.blocked_id', 'matches.user_two_id'))
                    ->orWhere(fn (QueryBuilder $byTwo) => $byTwo
                        ->whereColumn('blocks.blocker_id', 'matches.user_two_id')
                        ->whereColumn('blocks.blocked_id', 'matches.user_one_id'))));
    }
}
