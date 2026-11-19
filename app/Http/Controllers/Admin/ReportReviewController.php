<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ModerationActionType;
use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReviewReportRequest;
use App\Http\Resources\AdminReportResource;
use App\Models\ModerationAction;
use App\Models\Report;
use App\Models\User;
use App\Moderation\AccountSuspension;
use Dedoc\Scramble\Attributes\Group as ApiGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Revisión de los reportes de los alumnos (T-37, RF-15). El moderador solo ve y atiende los de su
 * universidad (la del reportado); el administrador, todos. Cada decisión queda en `moderation_actions`
 * con su motivo, y resolver un reporte puede suspender al reportado en el mismo paso.
 */
#[ApiGroup('Administración', weight: 12)]
class ReportReviewController extends Controller
{
    /**
     * Cola de reportes (`status=pending`, por defecto, del más antiguo al más nuevo), historial de
     * resueltos o descartados (del más reciente al más antiguo) o todos (`status=all`, del más nuevo al
     * más antiguo). `user_id` filtra los de un reportado y `reason`, por motivo.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(['all', ...array_column(ReportStatus::cases(), 'value')])],
            'user_id' => ['sometimes', 'integer'],
            'reason' => ['sometimes', Rule::enum(ReportReason::class)],
        ]);
        $value = $validated['status'] ?? ReportStatus::Pending->value;
        $status = $value === 'all' ? null : ReportStatus::from($value);

        $reports = Report::query()
            ->reviewableBy($request->user())
            ->when($status !== null, fn ($query) => $query->where('reports.status', $status))
            ->when(isset($validated['user_id']), fn ($query) => $query->where('reports.reported_id', $validated['user_id']))
            ->when(isset($validated['reason']), fn ($query) => $query->where('reports.reason', $validated['reason']))
            ->with([
                'university',
                'reporter',
                'reported' => fn ($query) => $query->withCount('reportsReceived'),
                'reviewer',
            ])
            ->when(
                $status === null,
                fn ($query) => $query->orderByDesc('reports.created_at')->orderByDesc('reports.id'),
                fn ($query) => $query
                    ->when(
                        $status === ReportStatus::Pending,
                        fn ($query) => $query->orderBy('reports.created_at'),
                        fn ($query) => $query->orderByDesc('reports.reviewed_at'),
                    )
                    ->orderBy('reports.id'),
            )
            ->paginate(20);

        return AdminReportResource::collection($reports);
    }

    public function show(Request $request, Report $report): AdminReportResource
    {
        Gate::authorize('review', $report);

        return new AdminReportResource($this->loadForPanel($report));
    }

    /**
     * Resuelve (`reviewed`) o descarta (`dismissed`) un reporte pendiente. Con `suspend: true`, al
     * resolverlo también suspende la cuenta del reportado y cierra sus sesiones.
     */
    public function update(ReviewReportRequest $request, Report $report, AccountSuspension $suspension): AdminReportResource
    {
        Gate::authorize('review', $report);

        $actor = $request->user();
        $decision = $request->decision();
        $reason = $request->string('reason')->toString();
        $suspend = $request->boolean('suspend');

        if ($suspend) {
            Gate::authorize('moderate', $report->reported);
        }

        DB::transaction(function () use ($actor, $report, $decision, $reason, $suspend, $suspension): void {
            // Bloquea el reporte para que dos personas del staff no lo atiendan al mismo tiempo.
            $locked = Report::query()->lockForUpdate()->findOrFail($report->getKey());

            if (! $locked->isPending()) {
                throw ValidationException::withMessages([
                    'status' => 'El reporte ya fue revisado.',
                ]);
            }

            $locked->forceFill([
                'status' => $decision,
                'reviewed_by' => $actor->getKey(),
                'reviewed_at' => now(),
            ])->save();

            ModerationAction::recordOnReport(
                $actor,
                $decision === ReportStatus::Reviewed ? ModerationActionType::ResolveReport : ModerationActionType::DismissReport,
                $locked,
                $reason,
            );

            if ($suspend) {
                $reported = User::query()->lockForUpdate()->findOrFail($locked->reported_id);

                // Si ya estaba suspendida, el reporte se resuelve sin repetir la suspensión.
                if (! $reported->isSuspended()) {
                    $suspension->suspend($actor, $reported, $reason, $locked);
                }
            }
        });

        return new AdminReportResource($this->loadForPanel($report->refresh()));
    }

    private function loadForPanel(Report $report): Report
    {
        return $report->load([
            'university',
            'reporter',
            'reported' => fn ($query) => $query->withCount('reportsReceived'),
            'reviewer',
        ]);
    }
}
