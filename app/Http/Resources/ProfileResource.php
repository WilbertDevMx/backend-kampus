<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

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
                'bio' => $profile->bio,
                'birth_date' => $profile->birth_date?->toDateString(),
                'gender' => $profile->gender,
                'interests' => $profile->interests->map(fn ($interest): array => [
                    'id' => $interest->id,
                    'name' => $interest->name,
                ])->values(),
                'photos' => $profile->photos->map(fn ($photo): array => [
                    'id' => $photo->id,
                    'mime_type' => $photo->mime_type,
                    'size' => $photo->size,
                    'temporary_url' => Storage::disk(config('filesystems.profile_photos_disk'))
                        ->temporaryUrl($photo->path, now()->addMinutes(15)),
                ])->values(),
            ],
        ];
    }
}
