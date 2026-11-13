<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ModerationActionType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStaffRequest;
use App\Http\Requests\Admin\UpdateStaffRequest;
use App\Http\Resources\StaffResource;
use App\Models\ModerationAction;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gestión de las cuentas de staff: solo para administradores (T-41, RF-22).
 *
 * Las cuentas se desactivan, no se borran, para conservar la bitácora `moderation_actions`, que guarda
 * cada alta, desactivación y reactivación con su motivo.
 */
#[Group('Administración', 'Gestión de staff y revisión de grupos para moderadores y administradores (T-41, T-42).', weight: 12)]
class StaffController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $staff = $this->staffQuery()
            ->with('university')
            ->orderBy('name')
            ->paginate(20);

        return StaffResource::collection($staff);
    }

    public function store(StoreStaffRequest $request): JsonResponse
    {
        $staff = DB::transaction(function () use ($request): User {
            // `role` no es asignable en masa: solo esta acción y `kampus:create-admin` crean staff.
            $staff = (new User)->forceFill([
                'name' => $request->string('name')->toString(),
                'email' => $request->string('email')->toString(),
                'password' => $request->string('password')->toString(),
                'role' => $request->role(),
                'university_id' => $request->universityId(),
                // Lo da de alta un administrador con un correo institucional ya validado.
                'email_verified_at' => now(),
            ]);
            $staff->save();

            ModerationAction::recordOnUser(
                $request->user(),
                ModerationActionType::CreateStaff,
                $staff,
                $request->string('reason')->toString(),
                $staff->university_id,
            );

            return $staff;
        });

        return (new StaffResource($staff->load('university')))->response()->setStatusCode(201);
    }

    /**
     * Desactiva (`active: false`) o reactiva (`active: true`) una cuenta de staff.
     */
    public function update(UpdateStaffRequest $request, int $staff): StaffResource
    {
        $target = $this->staffQuery()->findOrFail($staff);
        $activate = $request->boolean('active');

        if ($target->is($request->user())) {
            throw ValidationException::withMessages([
                'active' => 'No puedes cambiar el estado de tu propia cuenta.',
            ]);
        }

        if ($activate === ($target->suspended_at === null)) {
            throw ValidationException::withMessages([
                'active' => $activate ? 'La cuenta ya está activa.' : 'La cuenta ya está desactivada.',
            ]);
        }

        DB::transaction(function () use ($request, $target, $activate): void {
            $target->forceFill(['suspended_at' => $activate ? null : now()])->save();

            if (! $activate) {
                // Cierra las sesiones abiertas: el login ya no le dará un token nuevo.
                $target->tokens()->delete();
            }

            ModerationAction::recordOnUser(
                $request->user(),
                $activate ? ModerationActionType::ReactivateUser : ModerationActionType::DeactivateStaff,
                $target,
                $request->string('reason')->toString(),
                $target->university_id,
            );
        });

        return new StaffResource($target->load('university'));
    }

    /**
     * @return Builder<User>
     */
    private function staffQuery(): Builder
    {
        return User::query()->whereIn('role', [UserRole::Moderator, UserRole::Admin]);
    }
}
