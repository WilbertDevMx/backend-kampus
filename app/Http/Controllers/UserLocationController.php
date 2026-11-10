<?php

namespace App\Http\Controllers;

use App\Http\Resources\ZoneResource;
use App\Models\User;
use App\Models\UserLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Ubicación del alumno (T-28, RF-18, RNF-11): la zona declarada y, solo si activa "Cerca de ti", una celda
 * geohash de ~1 km calculada en el dispositivo.
 *
 * La API nunca recibe ni guarda una coordenada exacta: si la petición trae latitud o longitud, la rechaza.
 * Al desactivar "Cerca de ti", la celda se borra.
 */
class UserLocationController extends Controller
{
    /**
     * Precisión 6 en el alfabeto base32 de geohash: celdas de ~1.2 km × 0.6 km. Más caracteres serían más
     * precisos, así que se rechazan.
     */
    private const GEOHASH_PATTERN = '/^[0-9bcdefghjkmnpqrstuvwxyz]{6}$/';

    /**
     * Campos de coordenadas que la API rechaza en cualquier petición de ubicación.
     */
    private const COORDINATE_FIELDS = ['lat', 'lng', 'lon', 'latitude', 'longitude', 'coords', 'coordinates'];

    public function show(Request $request): JsonResponse
    {
        return $this->present($request->user());
    }

    /**
     * Zona declarada: un campus o una colonia del catálogo, o `null` para quitarla.
     */
    public function updateZone(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'zone_id' => ['present', 'nullable', 'integer', 'exists:zones,id'],
            ...$this->coordinateRules(),
        ]);

        $request->user()->profile()->updateOrCreate([], ['zone_id' => $validated['zone_id']]);

        return $this->present($request->user());
    }

    /**
     * Activa o actualiza "Cerca de ti" con la celda que calculó el dispositivo.
     */
    public function enableNearby(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'geohash' => ['required', 'string', 'regex:'.self::GEOHASH_PATTERN],
            ...$this->coordinateRules(),
        ], [
            'geohash.regex' => 'La celda debe ser un geohash de 6 caracteres.',
        ]);

        $user = $request->user();

        DB::transaction(function () use ($user, $validated): void {
            UserLocation::query()->updateOrCreate(['user_id' => $user->getKey()], ['geohash' => $validated['geohash']]);
            $user->profile()->updateOrCreate([], ['nearby_enabled' => true]);
        });

        return $this->present($user);
    }

    /**
     * Desactiva "Cerca de ti" y borra la celda (RNF-11).
     */
    public function disableNearby(Request $request): JsonResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($user): void {
            UserLocation::query()->where('user_id', $user->getKey())->delete();
            $user->profile()->update(['nearby_enabled' => false]);
        });

        return $this->present($user);
    }

    /**
     * @return array<string, list<string>>
     */
    private function coordinateRules(): array
    {
        return collect(self::COORDINATE_FIELDS)
            ->mapWithKeys(fn (string $field): array => [$field => ['prohibited']])
            ->all();
    }

    private function present(User $user): JsonResponse
    {
        $profile = $user->profile()->with('zone')->first();

        return response()->json([
            'data' => [
                'zone' => $profile?->zone === null ? null : new ZoneResource($profile->zone),
                'nearby_enabled' => (bool) $profile?->nearby_enabled,
                'geohash' => UserLocation::query()->where('user_id', $user->getKey())->value('geohash'),
            ],
        ]);
    }
}
