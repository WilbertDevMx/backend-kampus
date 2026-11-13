<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Cuenta de staff en el panel de administración (T-41).
 *
 * @mixin User
 */
class StaffResource extends JsonResource
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
            'active' => $this->suspended_at === null,
            'deactivated_at' => $this->suspended_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
