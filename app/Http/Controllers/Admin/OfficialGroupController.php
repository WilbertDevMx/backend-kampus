<?php

namespace App\Http\Controllers\Admin;

use App\Enums\GroupMemberRole;
use App\Enums\GroupStatus;
use App\Enums\ModerationActionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreOfficialGroupRequest;
use App\Http\Resources\AdminGroupResource;
use App\Models\Group;
use App\Models\ModerationAction;
use Dedoc\Scramble\Attributes\Group as ApiGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Grupos oficiales (T-42, RF-23): los crea el staff, se publican sin revisión y llevan la insignia "Oficial".
 */
#[ApiGroup('Administración', weight: 12)]
class OfficialGroupController extends Controller
{
    public function store(StoreOfficialGroupRequest $request): JsonResponse
    {
        $staff = $request->user();
        $groupAdmin = $request->groupAdmin();

        $group = DB::transaction(function () use ($request, $staff, $groupAdmin): Group {
            $group = new Group([
                ...$request->safe()->only(['name', 'description', 'category']),
                'university_id' => $request->universityId(),
                'created_by' => $staff->getKey(),
            ]);
            $group->forceFill([
                'status' => GroupStatus::Approved,
                'is_official' => true,
                'reviewed_by' => $staff->getKey(),
                'reviewed_at' => now(),
            ])->save();

            // El staff no usa las funciones sociales: un estudiante administra el grupo y publica actividades.
            if ($groupAdmin !== null) {
                $group->members()->attach($groupAdmin->getKey(), ['role' => GroupMemberRole::Admin->value]);
            }

            ModerationAction::recordOnGroup($staff, ModerationActionType::ApproveGroup, $group, 'Grupo oficial creado por el staff.');

            return $group;
        });

        return (new AdminGroupResource($group->load(['university', 'creator', 'reviewer'])->loadCount('members')))
            ->response()
            ->setStatusCode(201);
    }
}
