<?php

namespace App\Http\Controllers;

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
            'nearby_enabled' => ['sometimes', 'boolean'],
            'discovery_filters' => ['sometimes', 'nullable', 'array'],
        ]);

        if ($validated === []) {
            abort(422, 'Debes enviar al menos un dato de perfil.');
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
