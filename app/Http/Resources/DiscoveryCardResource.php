<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tarjeta de un perfil en el descubrimiento (RF-05).
 *
 * Solo lleva lo que se muestra: ni correo, ni fecha de nacimiento (va la edad), ni zona (RNF-03).
 */
class DiscoveryCardResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $profile = $this->profile;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'university' => $this->university === null ? null : [
                'id' => $this->university->id,
                'name' => $this->university->name,
                'short_name' => $this->university->short_name,
            ],
            'career' => $profile->career,
            'semester' => $profile->semester,
            'age' => $profile->birthdate?->age,
            'bio' => $profile->bio,
            'shared_interests' => (int) $this->shared_interests,
            'interests' => $profile->interests->map(fn ($interest): array => [
                'id' => $interest->id,
                'name' => $interest->name,
                'category' => $interest->category,
            ])->values(),
            'photos' => $profile->photos->map(fn ($photo): array => [
                'id' => $photo->id,
                'position' => $photo->position,
                'temporary_url' => $photo->temporaryUrl(),
            ])->values(),
        ];
    }
}
