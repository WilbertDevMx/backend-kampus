<?php

namespace App\Http\Resources;

use App\Models\ModerationAction;
use App\Models\Photo;
use App\Models\Report;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Cuenta en la consulta de usuarios del panel (T-43). En el detalle agrega el perfil, los reportes que
 * recibió y su historial en la bitácora de moderación.
 *
 * @mixin User
 */
class AdminUserResource extends JsonResource
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
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'university' => $this->university === null ? null : [
                'id' => $this->university->id,
                'name' => $this->university->name,
                'short_name' => $this->university->short_name,
            ],
            'email_verified' => $this->email_verified_at !== null,
            'suspended' => $this->isSuspended(),
            'suspended_at' => $this->suspended_at?->toIso8601String(),
            'reports_count' => (int) ($this->reports_received_count ?? 0),
            'pending_reports_count' => (int) ($this->pending_reports_count ?? 0),
            'created_at' => $this->created_at?->toIso8601String(),
            'profile' => $this->whenLoaded('profile', fn (): ?array => $this->profile === null ? null : [
                'career' => $this->profile->career,
                'semester' => $this->profile->semester,
                'bio' => $this->profile->bio,
                'completed' => $this->profile->completed_at !== null,
                'photos' => $this->profile->photos->map(fn (Photo $photo): array => [
                    'id' => $photo->id,
                    'position' => $photo->position,
                    'temporary_url' => $photo->temporaryUrl(),
                ])->values(),
            ]),
            'reports' => $this->whenLoaded('reportsReceived', fn () => $this->reportsReceived->map(fn (Report $report): array => [
                'id' => $report->id,
                'reason' => $report->reason->value,
                'description' => $report->description,
                'status' => $report->status->value,
                'reporter' => $report->reporter === null ? null : ['id' => $report->reporter->id, 'name' => $report->reporter->name],
                'created_at' => $report->created_at?->toIso8601String(),
            ])->values()),
            'moderation_history' => $this->whenLoaded('moderationHistory', fn () => $this->moderationHistory->map(fn (ModerationAction $action): array => [
                'id' => $action->id,
                'action' => $action->action->value,
                'reason' => $action->reason,
                'report_id' => $action->report_id,
                'actor' => $action->actor === null ? null : ['id' => $action->actor->id, 'name' => $action->actor->name],
                'created_at' => $action->created_at?->toIso8601String(),
            ])->values()),
        ];
    }
}
