<?php

namespace App\Http\Controllers;

use App\Discovery\DiscoveryFilters;
use App\Http\Resources\ProfileResource;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function show(Request $request): ProfileResource
    {
        return new ProfileResource($this->loadProfileRelations($request));
    }

    public function update(Request $request): ProfileResource
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'career' => ['sometimes', 'nullable', 'string', 'max:120'],
            'semester' => ['sometimes', 'nullable', 'integer', 'between:1,12'],
            'birthdate' => ['sometimes', 'nullable', 'date', 'before:today'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:300'],
            'zone_id' => ['sometimes', 'nullable', 'integer', 'exists:zones,id'],
            // "Cerca de ti" solo se activa con su celda y se desactiva borrándola: va por /location/nearby (T-28).
            'discovery_filters' => ['sometimes', 'nullable', 'array:universities,career,interests'],
            ...DiscoveryFilters::rules('discovery_filters.'),
        ]);

        if ($validated === []) {
            abort(422, 'Debes enviar al menos un dato de perfil.');
        }

        // Mismo formato que guarda PUT /discover/filters (RF-06).
        if (isset($validated['discovery_filters'])) {
            $validated['discovery_filters'] = DiscoveryFilters::fromArray($validated['discovery_filters'])->toArray();
        }

        if (array_key_exists('name', $validated)) {
            $request->user()->update(['name' => $validated['name']]);
        }

        $profileData = collect($validated)->except('name')->all();

        if ($profileData !== []) {
            $request->user()->profile()->updateOrCreate([], $profileData);
        }

        return new ProfileResource($this->loadProfileRelations($request));
    }

    private function loadProfileRelations(Request $request): mixed
    {
        return $request->user()->load(['profile.interests', 'profile.photos']);
    }
}
