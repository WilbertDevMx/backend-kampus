<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Zona del catálogo (RF-18). El centro es el del campus o la colonia, no el de una persona.
 */
class ZoneResource extends JsonResource
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
            'type' => $this->type->value,
            'university_id' => $this->university_id,
            'city' => $this->city,
            'center' => ['lat' => $this->center_lat, 'lng' => $this->center_lng],
        ];
    }
}
