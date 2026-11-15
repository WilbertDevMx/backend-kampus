<?php

namespace App\Http\Controllers;

use App\Discovery\DiscoveryFilters;
use App\Discovery\DiscoveryQuery;
use App\Discovery\NearbyPlacement;
use App\Http\Resources\NearbyUserResource;
use App\Models\User;
use App\Support\Location\Geohash;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Vista "Cerca" del descubrimiento (T-35, RF-19, RNF-11): estudiantes con "Cerca de ti" activo a unos
 * kilómetros de quien consulta, en el centro de su zona y con la distancia aproximada.
 *
 * Sigue las mismas reglas del descubrimiento (`DiscoveryQuery`): solo estudiantes disponibles, sin
 * bloqueados en ningún sentido, sin perfiles ya evaluados y con los filtros que el alumno guardó.
 */
#[Group('Vista Cerca', 'Estudiantes cercanos con posición difuminada y distancia aproximada (T-35).', weight: 7)]
class NearbyController extends Controller
{
    /** Radio de búsqueda. La celda de 5 caracteres y sus vecinas cubren unos 15 km × 15 km. */
    public const RADIUS_KM = 10;

    /** Cuántos candidatos se leen como máximo antes de ordenar por distancia. */
    private const MAX_CANDIDATES = 200;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,50'],
        ]);

        /** @var User $viewer */
        $viewer = $request->user();
        $viewerCell = $viewer->profile?->nearby_enabled ? $viewer->location()->value('geohash') : null;

        // Solo ve a los demás quien también comparte su celda.
        if ($viewerCell === null) {
            return response()->json([
                'message' => 'Activa "Cerca de ti" para ver quién está cerca.',
                'code' => 'nearby_disabled',
            ], 403);
        }

        $origin = Geohash::center($viewerCell);
        $filters = DiscoveryFilters::fromArray($viewer->profile->discovery_filters);

        $nearby = DiscoveryQuery::for($viewer)
            ->withFilters($filters)
            ->inCells(Geohash::withNeighbors(substr($viewerCell, 0, 5)))
            ->take(self::MAX_CANDIDATES, ['location', 'profile.zone'])
            ->map(fn (User $user): array => [
                'user' => $user,
                'placement' => NearbyPlacement::for($user, $user->location->geohash, $origin),
            ])
            ->filter(fn (array $item): bool => $item['placement']->distanceKm <= self::RADIUS_KM)
            ->sortBy(fn (array $item): float => $item['placement']->distanceKm)
            ->take((int) ($validated['limit'] ?? 30));

        return response()->json([
            'data' => $nearby
                ->map(fn (array $item): array => (new NearbyUserResource($item['user'], $item['placement']))->resolve($request))
                ->values(),
            'meta' => [
                'radius_km' => self::RADIUS_KM,
                // Para centrar el mapa: el punto de quien consulta, calculado igual que el de los demás.
                'center' => NearbyPlacement::mapPosition($viewer->loadMissing('profile.zone'), $viewerCell),
            ],
        ]);
    }
}
