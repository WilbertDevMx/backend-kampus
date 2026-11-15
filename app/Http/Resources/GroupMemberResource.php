<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Integrante de un grupo: solo nombre, rol y desde cuándo está.
 *
 * @mixin User
 */
class GroupMemberResource extends JsonResource
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
            'role' => $this->pivot->role,
            'joined_at' => $this->pivot->created_at?->toIso8601String(),
        ];
    }
}
