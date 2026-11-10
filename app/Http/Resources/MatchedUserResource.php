<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lo mínimo para mostrar a la otra persona de un match: nombre, universidad y su primera foto.
 */
class MatchedUserResource extends JsonResource
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
            'university' => $this->university === null ? null : [
                'id' => $this->university->id,
                'name' => $this->university->name,
                'short_name' => $this->university->short_name,
            ],
            'photo_url' => $this->profile?->photos->first()?->temporaryUrl(),
        ];
    }
}
