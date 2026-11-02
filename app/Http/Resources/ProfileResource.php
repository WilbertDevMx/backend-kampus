<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfileResource extends JsonResource
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
            'user' => [
                'id' => $this->id,
                'name' => $this->name,
                'email' => $this->email,
            ],
            'profile' => $profile === null ? null : [
                'career' => $profile->career,
                'semester' => $profile->semester,
                'birthdate' => $profile->birthdate?->toDateString(),
                'bio' => $profile->bio,
                'zone_id' => $profile->zone_id,
                'nearby_enabled' => $profile->nearby_enabled,
                'discovery_filters' => $profile->discovery_filters,
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
            ],
        ];
    }
}
