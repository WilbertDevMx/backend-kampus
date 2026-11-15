<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GroupStatus;
use App\Enums\ModerationActionType;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminGroupResource;
use App\Models\Group;
use App\Models\ModerationAction;
use App\Notifications\GroupReviewedNotification;
use Dedoc\Scramble\Attributes\Group as ApiGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Revisión de los grupos que crean los alumnos (T-42, RF-23). El moderador solo ve y revisa los de su
 * universidad; el administrador, todos. Cada decisión queda en `moderation_actions` y se le avisa al creador.
 */
#[ApiGroup('Administración', weight: 12)]
class GroupReviewController extends Controller
{
    /**
     * Cola de revisión (`status=pending`, por defecto, del más antiguo al más nuevo) o historial de
     * aprobados y rechazados (del más reciente al más antiguo).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::enum(GroupStatus::class)],
        ]);
        $status = GroupStatus::from($validated['status'] ?? GroupStatus::Pending->value);

        $groups = Group::query()
            ->reviewableBy($request->user())
            ->where('groups.status', $status)
            ->with(['university', 'creator', 'reviewer'])
            ->withCount('members')
            ->when(
                $status === GroupStatus::Pending,
                fn ($query) => $query->orderBy('groups.created_at'),
                fn ($query) => $query->orderByDesc('groups.reviewed_at'),
            )
            ->orderBy('groups.id')
            ->paginate(20);

        return AdminGroupResource::collection($groups);
    }

    public function approve(Request $request, Group $group): AdminGroupResource
    {
        $validated = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        return $this->review($request, $group, GroupStatus::Approved, $validated['reason'] ?? 'Grupo aprobado.');
    }

    /**
     * Rechazar exige un motivo, que ve quien creó el grupo.
     */
    public function reject(Request $request, Group $group): AdminGroupResource
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:300'],
        ]);

        return $this->review($request, $group, GroupStatus::Rejected, $validated['reason']);
    }

    private function review(Request $request, Group $group, GroupStatus $decision, string $reason): AdminGroupResource
    {
        Gate::authorize('review', $group);

        if ($group->status !== GroupStatus::Pending) {
            throw ValidationException::withMessages([
                'group' => 'El grupo ya fue revisado.',
            ]);
        }

        DB::transaction(function () use ($request, $group, $decision, $reason): void {
            $group->forceFill([
                'status' => $decision,
                'reviewed_by' => $request->user()->getKey(),
                'reviewed_at' => now(),
                'rejection_reason' => $decision === GroupStatus::Rejected ? $reason : null,
            ])->save();

            ModerationAction::recordOnGroup(
                $request->user(),
                $decision === GroupStatus::Approved ? ModerationActionType::ApproveGroup : ModerationActionType::RejectGroup,
                $group,
                $reason,
            );
        });

        $group->creator?->notify(new GroupReviewedNotification($group));

        return new AdminGroupResource($group->load(['university', 'creator', 'reviewer'])->loadCount('members'));
    }
}
