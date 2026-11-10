<?php

namespace App\Http\Resources;

use App\Discovery\NearbyPlacement;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Un estudiante en el mapa de "Cerca" (T-35, RF-19): la misma tarjeta del descubrimiento más su punto en el
 * mapa y la distancia aproximada. Nunca lleva su celda ni una coordenada suya (RNF-11).
 */
class NearbyUserResource extends DiscoveryCardResource
{
    public function __construct(User $user, private readonly NearbyPlacement $placement)
    {
        parent::__construct($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'nearby' => [
                'position' => $this->placement->position,
                'distance_km' => $this->placement->roundedKm(),
                'distance_label' => $this->placement->label(),
            ],
        ];
    }
}
