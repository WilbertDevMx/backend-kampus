<?php

namespace App\Http\Controllers;

use App\Enums\ZoneType;
use App\Http\Resources\ZoneResource;
use App\Models\Zone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ZoneController extends Controller
{
    /**
     * Catálogo de zonas para elegir la propia (RF-18): primero los campus, después las colonias. Es corto,
     * así que no se pagina.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'type' => ['sometimes', Rule::enum(ZoneType::class)],
            'university_id' => ['sometimes', 'integer', 'exists:universities,id'],
        ]);

        $zones = Zone::query()
            ->when(isset($validated['type']), fn ($query) => $query->where('type', $validated['type']))
            ->when(isset($validated['university_id']), fn ($query) => $query->where('university_id', $validated['university_id']))
            ->orderByRaw("CASE WHEN type = 'campus' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get();

        return ZoneResource::collection($zones);
    }
}
