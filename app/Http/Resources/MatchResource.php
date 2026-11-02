<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un match visto desde quien hace la petición: `user` es siempre la otra persona.
 */
class MatchResource extends JsonResource
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
            'matched_at' => $this->created_at?->toIso8601String(),
            'user' => new MatchedUserResource($this->otherUser($request->user())),
        ];
    }
}
