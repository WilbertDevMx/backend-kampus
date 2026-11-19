<?php

namespace App\Http\Resources;

use App\Models\Report;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Reporte en el panel de moderación (T-37): quién reportó, a quién, por qué y quién lo revisó.
 *
 * @mixin Report
 */
class AdminReportResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reason' => $this->reason->value,
            'description' => $this->description,
            'status' => $this->status->value,
            'university' => $this->university === null ? null : [
                'id' => $this->university->id,
                'name' => $this->university->name,
                'short_name' => $this->university->short_name,
            ],
            // `null` si quien reportó ya borró su cuenta: el reporte se conserva.
            'reporter' => $this->reporter === null ? null : [
                'id' => $this->reporter->id,
                'name' => $this->reporter->name,
                'email' => $this->reporter->email,
            ],
            'reported' => $this->reportedSummary($this->reported),
            'match_id' => $this->match_id,
            'reviewed_by' => $this->reviewer === null ? null : ['id' => $this->reviewer->id, 'name' => $this->reviewer->name],
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reportedSummary(User $reported): array
    {
        return [
            'id' => $reported->id,
            'name' => $reported->name,
            'email' => $reported->email,
            'suspended' => $reported->isSuspended(),
            'suspended_at' => $reported->suspended_at?->toIso8601String(),
            // Cuántos reportes ha recibido en total, para ver si es reincidente.
            'reports_count' => (int) ($reported->reports_received_count ?? $reported->reportsReceived()->count()),
        ];
    }
}
