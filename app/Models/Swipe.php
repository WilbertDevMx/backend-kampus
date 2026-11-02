<?php

namespace App\Models;

use Database\Factories\SwipeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evaluación de un perfil en el descubrimiento: `liked` es "Me interesa" y `false` es "Pasar" (RF-05).
 */
#[Fillable(['swiper_id', 'swiped_id', 'liked'])]
class Swipe extends Model
{
    /** @use HasFactory<SwipeFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'liked' => 'boolean',
        ];
    }

    public function swiper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'swiper_id');
    }

    public function swiped(): BelongsTo
    {
        return $this->belongsTo(User::class, 'swiped_id');
    }
}
