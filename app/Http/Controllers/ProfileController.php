<?php

namespace App\Http\Controllers;

use App\Discovery\DiscoveryFilters;
use App\Enums\Gender;
use App\Http\Resources\ProfileResource;
use App\Models\Profile;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Perfil', 'Datos del perfil, intereses y fotos (T-07, T-49).', weight: 4)]
class ProfileController extends Controller
{
    public function show(Request $request): ProfileResource
    {
        return new ProfileResource($this->loadProfileRelations($request));
    }

    public function update(Request $request): ProfileResource
    {
        $validated = $request->validate([
            // El nombre es obligatorio en el perfil (T-49): no se puede dejar vacío.
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            'career' => ['sometimes', 'nullable', 'string', 'max:120'],
            'semester' => ['sometimes', 'nullable', 'integer', 'between:1,12'],
            // Edad mínima de 17 años (T-49).
            'birthdate' => [
                'sometimes', 'nullable', 'date',
                'before_or_equal:'.now()->subYears(Profile::MinimumAge)->toDateString(),
            ],
            'gender' => ['sometimes', 'nullable', Rule::enum(Gender::class)],
            'bio' => ['sometimes', 'nullable', 'string', 'max:300'],
            'zone_id' => ['sometimes', 'nullable', 'integer', 'exists:zones,id'],
            // "Cerca de ti" solo se activa con su celda y se desactiva borrándola: va por /location/nearby (T-28).
            'discovery_filters' => ['sometimes', 'nullable', 'array:universities,career,interests'],
            ...DiscoveryFilters::rules('discovery_filters.'),
        ], [
            'birthdate.before_or_equal' => 'Debes tener al menos '.Profile::MinimumAge.' años para usar Kampus.',
            'gender.enum' => 'Elige una opción de la lista.',
            'name.min' => 'Escribe tu nombre.',
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

        // Cualquier cambio puede completar el perfil, o dejarlo incompleto si se borra un dato (T-49).
        $request->user()->profile()->first()?->syncCompletion();

        return new ProfileResource($this->loadProfileRelations($request));
    }

    private function loadProfileRelations(Request $request): mixed
    {
        return $request->user()->load(['profile.interests', 'profile.photos']);
    }
}
