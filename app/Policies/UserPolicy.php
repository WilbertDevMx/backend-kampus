<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/**
 * Qué puede hacer el staff con las cuentas de otros usuarios (RF-15, RF-24).
 * Es la misma regla que el scope `User::manageableBy()`, que filtra los listados.
 */
class UserPolicy
{
    /**
     * Listar usuarios en el panel de administración. El moderador solo verá los de su universidad.
     */
    public function viewAny(User $actor): bool
    {
        return $actor->isStaff();
    }

    /**
     * Ver el detalle de una cuenta. Cada quien puede ver la suya.
     */
    public function view(User $actor, User $target): bool
    {
        return $actor->is($target) || $this->manages($actor, $target);
    }

    /**
     * Suspender, reactivar o eliminar una cuenta. Nadie se modera a sí mismo. Exigir un reporte y el
     * motivo es responsabilidad de la acción que lo use (T-43).
     */
    public function moderate(User $actor, User $target): bool
    {
        return $this->manages($actor, $target);
    }

    private function manages(User $actor, User $target): bool
    {
        if ($actor->is($target)) {
            return false;
        }

        return match ($actor->role) {
            UserRole::Admin => true,
            UserRole::Moderator => $target->role === UserRole::Student
                && $actor->university_id !== null
                && $actor->university_id === $target->university_id,
            UserRole::Student => false,
        };
    }
}
