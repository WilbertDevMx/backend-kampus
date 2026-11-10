<?php

namespace App\Models;

use Database\Factories\GroupActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Actividad que publica un administrador de grupo (RF-12).
 */
#[Fillable(['group_id', 'created_by', 'title', 'description', 'location', 'starts_at'])]
class GroupActivity extends Model
{
    /** @use HasFactory<GroupActivityFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
