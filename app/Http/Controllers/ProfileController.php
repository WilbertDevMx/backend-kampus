<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProfileResource;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

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
            'bio' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'birth_date' => ['sometimes', 'nullable', 'date', 'before:today'],
            'gender' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        if ($validated === []) {
            abort(422, 'Debes enviar al menos un dato de perfil.');
        }

        if (array_key_exists('name', $validated)) {
            $request->user()->update(['name' => $validated['name']]);
        }

        $profileData = Arr::only($validated, ['bio', 'birth_date', 'gender']);

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
