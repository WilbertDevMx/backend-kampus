<?php

namespace App\Models;

use App\Enums\ModerationActionType;
use App\Enums\ModerationTarget;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bitácora de moderación: quién hizo qué, sobre quién y por qué (RF-15, RF-22).
 *
 * Es inmutable: solo tiene `created_at` y el modelo no deja editar ni borrar un registro.
 */
#[Fillable(['actor_id', 'action', 'target_type', 'target_id', 'report_id', 'university_id', 'reason'])]
class ModerationAction extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn (): bool => false);
        static::deleting(fn (): bool => false);
    }

    /**
     * Registra una acción del staff sobre una cuenta.
     */
    public static function recordOnUser(
        User $actor,
        ModerationActionType $action,
        User $target,
        string $reason,
        ?int $universityId = null,
        ?int $reportId = null,
    ): self {
        return static::create([
            'actor_id' => $actor->getKey(),
            'action' => $action,
            'target_type' => ModerationTarget::User,
            'target_id' => $target->getKey(),
            'report_id' => $reportId,
            'university_id' => $universityId,
            'reason' => $reason,
        ]);
    }

    /**
     * Registra una acción del staff sobre un grupo (T-42), en el ámbito de la universidad del grupo.
     */
    public static function recordOnGroup(User $actor, ModerationActionType $action, Group $group, string $reason): self
    {
        return static::create([
            'actor_id' => $actor->getKey(),
            'action' => $action,
            'target_type' => ModerationTarget::Group,
            'target_id' => $group->getKey(),
            'university_id' => $group->university_id,
            'reason' => $reason,
        ]);
    }

    protected function casts(): array
    {
        return [
            'action' => ModerationActionType::class,
            'target_type' => ModerationTarget::class,
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
