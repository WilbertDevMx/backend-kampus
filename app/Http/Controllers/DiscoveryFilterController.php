<?php

namespace App\Http\Controllers;

use App\Discovery\DiscoveryFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Filtros de descubrimiento guardados en el perfil, para que persistan entre sesiones (RF-06).
 */
class DiscoveryFilterController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $filters = DiscoveryFilters::fromArray($request->user()->profile?->discovery_filters);

        return response()->json(['data' => $filters->toArray()]);
    }

    /**
     * Reemplaza todos los filtros: un campo que no se envía queda sin filtro.
     */
    public function update(Request $request): JsonResponse
    {
        $filters = DiscoveryFilters::fromArray($request->validate(DiscoveryFilters::rules()));

        $request->user()->profile()->updateOrCreate([], ['discovery_filters' => $filters->toArray()]);

        return response()->json(['data' => $filters->toArray()]);
    }
}
