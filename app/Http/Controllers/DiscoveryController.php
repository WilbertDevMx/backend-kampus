<?php

namespace App\Http\Controllers;

use App\Discovery\DiscoveryFilters;
use App\Discovery\DiscoveryQuery;
use App\Discovery\Ranking\DiscoveryRanking;
use App\Http\Resources\DiscoveryCardResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Descubrimiento', 'Perfiles por evaluar, filtros y "Me interesa" o "Pasar" (T-09).', weight: 6)]
class DiscoveryController extends Controller
{
    /**
     * Siguiente tanda de perfiles por evaluar, con los filtros que el alumno guardó (RF-05, RF-06).
     * Un perfil evaluado ya no vuelve a salir, así que no hace falta paginar: basta con pedir otra tanda.
     */
    public function index(Request $request, DiscoveryRanking $ranking): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,20'],
        ]);

        $viewer = $request->user();
        $filters = DiscoveryFilters::fromArray($viewer->profile?->discovery_filters);

        $candidates = DiscoveryQuery::for($viewer)
            ->withFilters($filters)
            ->rankedBy($ranking)
            ->take((int) ($validated['limit'] ?? 10));

        return DiscoveryCardResource::collection($candidates)
            ->additional(['filters' => $filters->toArray()]);
    }
}
