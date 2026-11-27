<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            // La app usa el rol para elegir la vista: estudiante o panel de administración (T-40).
            'role' => $this->role->value,
            'university_id' => $this->university_id,
            // Si es `false`, la app manda al estudiante a terminar su perfil antes de usarla (T-49).
            // El staff no tiene perfil social, así que para él siempre es `true`.
            'profile_completed' => $this->role->isStaff() || $this->profile?->completed_at !== null,
            // Celular verificado para recuperar la contraseña por SMS (T-45). Este recurso solo lo ve la propia cuenta.
            'phone' => $this->phone,
            'phone_verified' => $this->hasVerifiedPhone(),
            // Cuándo aceptó el aviso de privacidad y las normas de comunidad (T-23, RF-04). Si son `null`, la
            // app le pide aceptarlos con POST /api/legal/accept.
            'privacy_accepted_at' => $this->privacy_accepted_at?->toIso8601String(),
            'rules_accepted_at' => $this->rules_accepted_at?->toIso8601String(),
        ];
    }
}
