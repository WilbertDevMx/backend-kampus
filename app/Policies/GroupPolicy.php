<?php

namespace App\Policies;

use App\Enums\GroupMemberRole;
use App\Models\Group;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Permisos sobre un grupo (RF-11, RF-12). Un grupo que el usuario no puede ver responde `404`, para no
 * revelar que existe (por ejemplo, uno en revisión de otra persona).
 */
class GroupPolicy
{
    /**
     * Un grupo aprobado lo ve cualquier estudiante; uno en revisión o rechazado, solo sus integrantes.
     */
    public function view(User $user, Group $group): Response
    {
        return $group->isApproved() || $group->roleOf($user) !== null
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * Editar el grupo, cambiar su imagen, borrarlo y publicar actividades: solo sus administradores.
     */
    public function manage(User $user, Group $group): Response
    {
        $role = $group->roleOf($user);

        if ($role === null && ! $group->isApproved()) {
            return Response::denyAsNotFound();
        }

        return $role === GroupMemberRole::Admin
            ? Response::allow()
            : Response::deny('Solo los administradores del grupo pueden hacerlo.');
    }

    /**
     * Aprobar o rechazar un grupo (T-42, RF-23): un moderador solo los de su universidad; un administrador,
     * todos. Un grupo fuera de su alcance responde `404`.
     */
    public function review(User $user, Group $group): Response
    {
        return Group::query()->reviewableBy($user)->whereKey($group->getKey())->exists()
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    /**
     * La lista de integrantes la ven solo los integrantes.
     */
    public function viewMembers(User $user, Group $group): Response
    {
        if ($group->roleOf($user) !== null) {
            return Response::allow();
        }

        return $group->isApproved()
            ? Response::deny('Solo los integrantes ven la lista del grupo.')
            : Response::denyAsNotFound();
    }
}
