<?php

namespace App\Http\Controllers;

use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Models\Block;
use App\Models\Report;
use App\Models\UserMatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Reportar a un estudiante (T-21, RF-14). El reporte lo atiende el moderador de la universidad del
 * **reportado** (`reports.university_id`) o un administrador (T-37). Se puede bloquear en el mismo paso.
 */
class ReportController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
            'reason' => ['required', Rule::enum(ReportReason::class)],
            'description' => ['nullable', 'string', 'max:1000', 'required_if:reason,'.ReportReason::Otro->value],
            'match_id' => ['nullable', 'integer'],
            'block' => ['sometimes', 'boolean'],
        ]);

        $reporter = $request->user();
        $reported = BlockController::blockableStudent($reporter, (int) $validated['user_id']);

        // La conversación de evidencia tiene que ser un match entre las dos personas.
        if (isset($validated['match_id'])
            && ! UserMatch::query()->whereKey($validated['match_id'])->where(UserMatch::pairOf($reporter, $reported))->exists()) {
            throw ValidationException::withMessages([
                'match_id' => 'La conversación no es entre ustedes dos.',
            ]);
        }

        $alreadyPending = Report::query()
            ->where('reporter_id', $reporter->getKey())
            ->where('reported_id', $reported->getKey())
            ->where('status', ReportStatus::Pending)
            ->exists();

        if ($alreadyPending) {
            return response()->json(['message' => 'Ya reportaste a esta persona y tu reporte está en revisión.'], 409);
        }

        $block = $request->boolean('block');

        $report = DB::transaction(function () use ($validated, $reporter, $reported, $block): Report {
            $report = Report::create([
                'reporter_id' => $reporter->getKey(),
                'reported_id' => $reported->getKey(),
                'match_id' => $validated['match_id'] ?? null,
                'reason' => $validated['reason'],
                'description' => $validated['description'] ?? null,
                'university_id' => $reported->university_id,
            ]);

            if ($block) {
                Block::query()->firstOrCreate(['blocker_id' => $reporter->getKey(), 'blocked_id' => $reported->getKey()]);
            }

            return $report;
        });

        return response()->json([
            'message' => 'Recibimos tu reporte. Lo revisará el equipo de moderación.',
            'data' => [
                'id' => $report->id,
                'reason' => $report->reason->value,
                'status' => ReportStatus::Pending->value,
                'blocked' => $block,
            ],
        ], 201);
    }
}
