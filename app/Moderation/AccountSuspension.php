<?php

namespace App\Moderation;

use App\Enums\ModerationActionType;
use App\Models\ModerationAction;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Suspende o reactiva la cuenta de un estudiante (RF-15). La usan la revisión de reportes (T-37) y la
 * consulta de usuarios del panel (T-43). Quién puede hacerlo lo decide `UserPolicy::moderate`.
 *
 * Una cuenta suspendida no inicia sesión (`AuthTokenController`), pierde sus tokens, deja de aparecer en
 * Descubrir, Cerca y los matches, y no puede suscribirse a los canales de tiempo real.
 */
class AccountSuspension
{
    public function suspend(User $actor, User $target, string $reason, ?Report $report = null): void
    {
        DB::transaction(function () use ($actor, $target, $reason, $report): void {
            $target->forceFill(['suspended_at' => now()])->save();

            // Cierra todas sus sesiones: el login ya no le dará un token nuevo.
            $target->tokens()->delete();

            ModerationAction::recordOnUser(
                $actor,
                ModerationActionType::SuspendUser,
                $target,
                $reason,
                $target->university_id,
                $report?->getKey(),
            );
        });
    }

    public function reactivate(User $actor, User $target, string $reason): void
    {
        DB::transaction(function () use ($actor, $target, $reason): void {
            $target->forceFill(['suspended_at' => null])->save();

            ModerationAction::recordOnUser(
                $actor,
                ModerationActionType::ReactivateUser,
                $target,
                $reason,
                $target->university_id,
            );
        });
    }
}
