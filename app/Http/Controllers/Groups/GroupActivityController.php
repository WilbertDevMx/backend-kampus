<?php

namespace App\Http\Controllers\Groups;

use App\Http\Controllers\Controller;
use App\Http\Requests\Groups\StoreGroupActivityRequest;
use App\Http\Resources\GroupActivityResource;
use App\Models\Group;
use App\Models\GroupActivity;
use Dedoc\Scramble\Attributes\Group as ApiGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Actividades de un grupo (T-17, RF-12): las ve quien puede ver el grupo y las publican sus administradores.
 */
#[ApiGroup('Grupos', weight: 11)]
class GroupActivityController extends Controller
{
    /**
     * Próximas actividades, de la más cercana a la más lejana. Con `past=1`, las pasadas, de la más reciente.
     */
    public function index(Request $request, Group $group): AnonymousResourceCollection
    {
        Gate::authorize('view', $group);

        $request->validate(['past' => ['sometimes', 'boolean']]);

        $activities = $group->activities()
            ->with('creator')
            ->when(
                $request->boolean('past'),
                fn ($query) => $query->where('starts_at', '<', now())->orderByDesc('starts_at'),
                fn ($query) => $query->where('starts_at', '>=', now())->orderBy('starts_at'),
            )
            ->orderBy('id')
            ->paginate(20);

        return GroupActivityResource::collection($activities);
    }

    public function store(StoreGroupActivityRequest $request, Group $group): JsonResponse
    {
        Gate::authorize('manage', $group);

        if (! $group->isApproved()) {
            throw ValidationException::withMessages([
                'group' => 'El grupo todavía no está aprobado.',
            ]);
        }

        $activity = $group->activities()->create([
            ...$request->validated(),
            'created_by' => $request->user()->getKey(),
        ]);

        return (new GroupActivityResource($activity->load('creator')))->response()->setStatusCode(201);
    }

    public function update(StoreGroupActivityRequest $request, Group $group, GroupActivity $activity): GroupActivityResource
    {
        Gate::authorize('manage', $group);

        $activity->update($request->validated());

        return new GroupActivityResource($activity->load('creator'));
    }

    public function destroy(Group $group, GroupActivity $activity): Response
    {
        Gate::authorize('manage', $group);

        $activity->delete();

        return response()->noContent();
    }
}
