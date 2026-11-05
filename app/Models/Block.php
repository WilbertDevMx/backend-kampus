<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bloqueo entre dos usuarios (RF-13). La tabla no tiene `id`: su llave es el par (blocker_id, blocked_id).
 *
 * Un bloqueo, en cualquier sentido, saca a las dos personas del descubrimiento (`User::discoverableBy()`),
 * de la lista de matches y del chat (`UserMatch::visibleTo()`).
 */
#[Fillable(['blocker_id', 'blocked_id'])]
class Block extends Model
{
    public $incrementing = false;

    protected $primaryKey = null;

    public function blocked(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_id');
    }
}
