<?php

namespace App\Http\Controllers\Groups;

use App\Enums\GroupCategory;
use App\Enums\GroupMemberRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Groups\StoreGroupRequest;
use App\Http\Requests\Groups\UpdateGroupRequest;
use App\Http\Resources\GroupResource;
use App\Models\Group;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Explorar, crear, editar y borrar grupos (T-17, RF-11, RF-12).
 */
class GroupController extends Controller
{
    /**
     * Grupos aprobados, filtrables por universidad y categoría (RF-11). Los oficiales van primero.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'university_id' => ['sometimes', 'integer', 'exists:universities,id'],
            'category' => ['sometimes', Rule::enum(GroupCategory::class)],
        ]);

        $groups = Group::query()
            ->approved()
            ->forCard($request->user())
            ->when(isset($validated['university_id']), fn ($query) => $query->where('groups.university_id', $validated['university_id']))
            ->when(isset($validated['category']), fn ($query) => $query->where('groups.category', $validated['category']))
            ->orderByDesc('groups.is_official')
            ->orderBy('groups.name')
            ->paginate(20);

        return GroupResource::collection($groups);
    }

    /**
     * Grupos del alumno, en cualquier estado: así ve los suyos que están en revisión o rechazados.
     */
    public function mine(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $groups = Group::query()
            ->forCard($user)
            ->whereHas('members', fn ($members) => $members->whereKey($user->getKey()))
            ->orderBy('groups.name')
            ->paginate(20);

        return GroupResource::collection($groups);
    }

    /**
     * El grupo de un alumno es de su universidad y queda en revisión hasta que lo aprueben (RF-23).
     * Quien lo crea queda como administrador.
     */
    public function store(StoreGroupRequest $request): JsonResponse
    {
        $user = $request->user();

        $group = DB::transaction(function () use ($request, $user): Group {
            $group = Group::create([
                ...$request->validated(),
                'university_id' => $user->university_id,
                'created_by' => $user->getKey(),
            ]);
            $group->members()->attach($user->getKey(), ['role' => GroupMemberRole::Admin->value]);

            return $group;
        });

        return $this->present($group, $user)->response()->setStatusCode(201);
    }

    public function show(Request $request, Group $group): GroupResource
    {
        Gate::authorize('view', $group);

        return $this->present($group, $request->user());
    }

    public function update(UpdateGroupRequest $request, Group $group): GroupResource
    {
        Gate::authorize('manage', $group);

        $group->update($request->validated());

        return $this->present($group, $request->user());
    }

    /**
     * Borra el grupo con sus integrantes, sus actividades y su imagen.
     */
    public function destroy(Group $group): Response
    {
        Gate::authorize('manage', $group);

        if ($group->image_path !== null) {
            Storage::disk(config('filesystems.group_images_disk'))->delete($group->image_path);
        }

        $group->delete();

        return response()->noContent();
    }

    /**
     * Vuelve a leer el grupo con `my_role` y `members_count` para la respuesta.
     */
    private function present(Group $group, User $user): GroupResource
    {
        return new GroupResource(Group::query()->forCard($user)->findOrFail($group->getKey()));
    }
}
