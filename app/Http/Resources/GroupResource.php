<?php

namespace App\Http\Resources;

use App\Enums\GroupMemberRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Grupo visto por quien hace la petición: `my_role` dice si es integrante y con qué rol.
 * Espera el modelo cargado con `Group::forCard()`.
 */
class GroupResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $myRole = $this->my_role instanceof GroupMemberRole ? $this->my_role->value : $this->my_role;

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
            // El motivo del rechazo solo lo ven los administradores del grupo (RF-23).
            'rejection_reason' => $myRole === GroupMemberRole::Admin->value ? $this->rejection_reason : null,
            'image_url' => $this->imageUrl(),
            'members_count' => (int) $this->members_count,
            'my_role' => $myRole,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
