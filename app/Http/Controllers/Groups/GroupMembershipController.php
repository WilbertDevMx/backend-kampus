<?php

namespace App\Http\Controllers\Groups;

use App\Enums\GroupMemberRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\GroupMemberResource;
use App\Http\Resources\GroupResource;
use App\Models\Group;
use Dedoc\Scramble\Attributes\Group as ApiGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Unirse a un grupo, salir de él y ver a sus integrantes (T-17, RF-11).
 */
#[ApiGroup('Grupos', weight: 11)]
class GroupMembershipController extends Controller
{
    /**
     * Integrantes del grupo, primero los administradores. Solo los ven los integrantes.
     */
    public function index(Group $group): AnonymousResourceCollection
    {
        Gate::authorize('viewMembers', $group);

        $members = $group->members()
            ->orderByRaw("CASE WHEN group_members.role = 'admin' THEN 0 ELSE 1 END")
            ->orderBy('group_members.created_at')
            ->orderBy('users.id')
            ->paginate(50);

        return GroupMemberResource::collection($members);
    }

    /**
     * Unirse a un grupo aprobado.
     */
    public function store(Request $request, Group $group): JsonResponse
    {
        Gate::authorize('view', $group);
        $user = $request->user();

        if ($group->roleOf($user) !== null) {
            return response()->json(['message' => 'Ya eres integrante de este grupo.'], 409);
        }

        $group->members()->attach($user->getKey(), ['role' => GroupMemberRole::Member->value]);

        return (new GroupResource(Group::query()->forCard($user)->findOrFail($group->getKey())))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Salir del grupo. Si se va el último administrador, el integrante más antiguo toma su lugar; el único
     * integrante no puede salir, tiene que borrar el grupo.
     */
    public function destroy(Request $request, Group $group): Response
    {
        Gate::authorize('view', $group);
        $user = $request->user();
        $role = $group->roleOf($user);

        if ($role === null) {
            throw ValidationException::withMessages([
                'group' => 'No eres integrante de este grupo.',
            ]);
        }

        $others = fn () => $group->members()->whereKeyNot($user->getKey());

        if (! $others()->exists()) {
            throw ValidationException::withMessages([
                'group' => 'Eres el único integrante. Si ya no quieres el grupo, bórralo.',
            ]);
        }

        DB::transaction(function () use ($group, $user, $role, $others): void {
            $otherAdmins = $others()->wherePivot('role', GroupMemberRole::Admin->value)->exists();

            if ($role === GroupMemberRole::Admin && ! $otherAdmins) {
                $successor = $others()->orderBy('group_members.created_at')->orderBy('users.id')->first();
                $group->members()->updateExistingPivot($successor->getKey(), ['role' => GroupMemberRole::Admin->value]);
            }

            $group->members()->detach($user->getKey());
        });

        return response()->noContent();
    }
}
