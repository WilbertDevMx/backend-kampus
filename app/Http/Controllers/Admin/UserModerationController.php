<?php

namespace App\Http\Controllers\Admin;

use App\Accounts\AccountDeletion;
use App\Enums\ModerationActionType;
use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminUserResource;
use App\Models\ModerationAction;
use App\Models\Report;
use App\Models\User;
use App\Moderation\AccountSuspension;
use Dedoc\Scramble\Attributes\Group as ApiGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Consulta de usuarios, suspensión y eliminación de cuentas (T-43 y T-23, RF-15, RF-16 y RF-24). El
 * moderador solo ve a los estudiantes de su universidad y solo suspende o elimina con un reporte sobre la
 * cuenta (`report_id`); el administrador ve todas las cuentas. Toda acción lleva motivo y queda en
 * `moderation_actions`. Las cuentas de staff se desactivan en la gestión de staff (T-41).
 */
#[ApiGroup('Administración', weight: 12)]
class UserModerationController extends Controller
{
    /**
     * Lista y busca cuentas. `search` busca en el nombre y el correo; `status` filtra activas o
     * suspendidas; `sort=reports` pone primero a quien tiene más reportes pendientes.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['all', 'active', 'suspended'])],
            'role' => ['sometimes', Rule::enum(UserRole::class)],
            'university_id' => ['sometimes', 'integer'],
            'sort' => ['sometimes', Rule::in(['name', 'reports', 'newest'])],
        ]);
        $search = trim((string) ($validated['search'] ?? ''));
        $status = $validated['status'] ?? 'all';
        $sort = $validated['sort'] ?? 'name';

        $users = $this->panelQuery($request->user())
            ->when($search !== '', function (Builder $query) use ($search): void {
                $like = '%'.addcslashes(mb_strtolower($search), '%_\\').'%';
                $query->where(fn (Builder $match) => $match
                    ->whereRaw("LOWER(users.name) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("LOWER(users.email) LIKE ? ESCAPE '\\'", [$like]));
            })
            ->when($status === 'active', fn (Builder $query) => $query->whereNull('users.suspended_at'))
            ->when($status === 'suspended', fn (Builder $query) => $query->whereNotNull('users.suspended_at'))
            ->when(isset($validated['role']), fn (Builder $query) => $query->where('users.role', $validated['role']))
            ->when(isset($validated['university_id']), fn (Builder $query) => $query->where('users.university_id', $validated['university_id']))
            ->when($sort === 'reports', fn (Builder $query) => $query->orderByDesc('pending_reports_count')->orderByDesc('reports_received_count'))
            ->when($sort === 'newest', fn (Builder $query) => $query->orderByDesc('users.created_at'))
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->paginate(20);

        return AdminUserResource::collection($users);
    }

    /**
     * Detalle de una cuenta: perfil, reportes recibidos (los 20 más recientes) e historial de moderación.
     */
    public function show(Request $request, int $user): AdminUserResource
    {
        return new AdminUserResource($this->loadDetail($this->findManageable($request->user(), $user)));
    }

    /**
     * Suspende una cuenta de estudiante y cierra sus sesiones. El moderador debe indicar un reporte sobre
     * esa cuenta (`report_id`); para el administrador es opcional. Si el reporte seguía pendiente, queda
     * resuelto con el mismo motivo.
     */
    public function suspend(Request $request, int $user, AccountSuspension $suspension): AdminUserResource
    {
        $actor = $request->user();
        $target = $this->findManageable($actor, $user);
        Gate::authorize('moderate', $target);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'report_id' => [Rule::requiredIf($actor->isModerator()), 'nullable', 'integer'],
        ], [
            'report_id.required' => 'Indica el reporte que justifica la suspensión.',
        ]);

        $this->ensureStudent($target);

        $report = isset($validated['report_id']) ? $this->findReportAbout($actor, $target, $validated['report_id']) : null;

        DB::transaction(function () use ($actor, $target, $report, $validated, $suspension): void {
            $locked = User::query()->lockForUpdate()->findOrFail($target->getKey());

            if ($locked->isSuspended()) {
                throw ValidationException::withMessages([
                    'user' => 'La cuenta ya está suspendida.',
                ]);
            }

            $suspension->suspend($actor, $locked, $validated['reason'], $report);

            if ($report !== null) {
                $pending = Report::query()->lockForUpdate()->whereKey($report->getKey())
                    ->where('status', ReportStatus::Pending)->first();

                if ($pending !== null) {
                    $pending->forceFill([
                        'status' => ReportStatus::Reviewed,
                        'reviewed_by' => $actor->getKey(),
                        'reviewed_at' => now(),
                    ])->save();

                    ModerationAction::recordOnReport($actor, ModerationActionType::ResolveReport, $pending, $validated['reason']);
                }
            }
        });

        return new AdminUserResource($this->loadDetail($target->refresh()));
    }

    /**
     * Reactiva una cuenta de estudiante suspendida. Puede volver a iniciar sesión.
     */
    public function reactivate(Request $request, int $user, AccountSuspension $suspension): AdminUserResource
    {
        $actor = $request->user();
        $target = $this->findManageable($actor, $user);
        Gate::authorize('moderate', $target);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $this->ensureStudent($target);

        DB::transaction(function () use ($actor, $target, $validated, $suspension): void {
            $locked = User::query()->lockForUpdate()->findOrFail($target->getKey());

            if (! $locked->isSuspended()) {
                throw ValidationException::withMessages([
                    'user' => 'La cuenta no está suspendida.',
                ]);
            }

            $suspension->reactivate($actor, $locked, $validated['reason']);
        });

        return new AdminUserResource($this->loadDetail($target->refresh()));
    }

    /**
     * Elimina una cuenta de estudiante con todos sus datos (T-23, RF-16). Mismas reglas que suspender: el
     * moderador debe indicar un reporte sobre esa cuenta (`report_id`) y el motivo es obligatorio. La
     * eliminación queda en `moderation_actions` con el id de la cuenta; el reporte se borra con ella, por
     * eso su id se copia al final del motivo. No se puede deshacer.
     */
    public function destroy(Request $request, int $user, AccountDeletion $deletion): JsonResponse
    {
        $actor = $request->user();
        $target = $this->findManageable($actor, $user);
        Gate::authorize('moderate', $target);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
            'report_id' => [Rule::requiredIf($actor->isModerator()), 'nullable', 'integer'],
        ], [
            'report_id.required' => 'Indica el reporte que justifica la eliminación.',
        ]);

        $this->ensureStudent($target);

        $report = isset($validated['report_id']) ? $this->findReportAbout($actor, $target, $validated['report_id']) : null;
        $reason = $report === null ? $validated['reason'] : $validated['reason'].' (reporte #'.$report->getKey().')';

        DB::transaction(function () use ($actor, $target, $report, $reason, $deletion): void {
            ModerationAction::recordOnUser(
                $actor,
                ModerationActionType::DeleteUser,
                $target,
                $reason,
                $target->university_id,
                $report?->getKey(),
            );

            $deletion->delete($target);
        });

        return response()->json([
            'message' => 'Eliminamos la cuenta y sus datos.',
        ]);
    }

    /**
     * Reporte que justifica una acción sobre `$target`: tiene que ser sobre esa cuenta y estar al alcance
     * de `$actor`.
     */
    private function findReportAbout(User $actor, User $target, int $reportId): Report
    {
        $report = Report::query()
            ->reviewableBy($actor)
            ->where('reports.reported_id', $target->getKey())
            ->find($reportId);

        if ($report === null) {
            throw ValidationException::withMessages([
                'report_id' => 'El reporte no es sobre esta cuenta.',
            ]);
        }

        return $report;
    }

    /**
     * Cuentas que `$actor` puede consultar, con su universidad y sus conteos de reportes.
     *
     * @return Builder<User>
     */
    private function panelQuery(User $actor): Builder
    {
        return User::query()
            ->manageableBy($actor)
            ->with('university')
            ->withCount([
                'reportsReceived',
                'reportsReceived as pending_reports_count' => fn (Builder $query) => $query
                    ->where('reports.status', ReportStatus::Pending),
            ]);
    }

    /**
     * Una cuenta fuera del alcance responde `404`, para no revelar que existe.
     */
    private function findManageable(User $actor, int $id): User
    {
        return $this->panelQuery($actor)->findOrFail($id);
    }

    private function loadDetail(User $user): User
    {
        return $user->load([
            'university',
            'profile.photos' => fn ($query) => $query->orderBy('position'),
            'reportsReceived' => fn ($query) => $query->with('reporter')->latest()->latest('id')->limit(20),
            'moderationHistory' => fn ($query) => $query->with('actor')->latest()->latest('id')->limit(20),
        ])->loadCount([
            'reportsReceived',
            'reportsReceived as pending_reports_count' => fn (Builder $query) => $query
                ->where('reports.status', ReportStatus::Pending),
        ]);
    }

    private function ensureStudent(User $target): void
    {
        if ($target->isStaff()) {
            throw ValidationException::withMessages([
                'user' => 'Las cuentas de staff se desactivan desde la gestión de staff.',
            ]);
        }
    }
}
