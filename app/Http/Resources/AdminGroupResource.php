<?php

namespace App\Http\Resources;

use App\Models\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Grupo en el panel de revisión (T-42): incluye quién lo creó y quién lo revisó.
 *
 * @mixin Group
 */
class AdminGroupResource extends JsonResource
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
            'description' => $this->description,
            'category' => $this->category->value,
            'university' => $this->university === null ? null : [
                'id' => $this->university->id,
                'name' => $this->university->name,
                'short_name' => $this->university->short_name,
            ],
            'status' => $this->status->value,
            'is_official' => $this->is_official,
            'rejection_reason' => $this->rejection_reason,
            'image_url' => $this->imageUrl(),
            'members_count' => (int) $this->members_count,
            'creator' => $this->creator === null ? null : ['id' => $this->creator->id, 'name' => $this->creator->name],
            'reviewed_by' => $this->reviewer === null ? null : ['id' => $this->reviewer->id, 'name' => $this->reviewer->name],
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
