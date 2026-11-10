<?php

namespace App\Http\Resources;

use App\Support\Security\DeviceLabel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Una sesión activa del usuario (T-46): un token de Sanctum. Nunca lleva el token ni su hash.
 *
 * @mixin PersonalAccessToken
 */
class SessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // `device_name` que mandó la app al iniciar sesión; "api" si no mandó ninguno.
            'name' => $this->name,
            'device' => DeviceLabel::fromUserAgent($this->user_agent),
            'current' => $this->id === $request->user()->currentAccessToken()?->getKey(),
            'created_at' => $this->created_at?->toIso8601String(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
