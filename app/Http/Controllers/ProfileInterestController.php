<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProfileResource;
use App\Models\Interest;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Perfil', weight: 4)]
class ProfileInterestController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Interest::query()->orderBy('name')->get(['id', 'name', 'category']),
        ]);
    }

    public function update(Request $request): ProfileResource
    {
        $validated = $request->validate([
            'interest_ids' => ['required', 'array', 'min:3', 'max:20'],
            'interest_ids.*' => ['integer', 'distinct', 'exists:interests,id'],
        ]);

        $profile = $request->user()->profile()->firstOrCreate([]);
        $profile->interests()->sync($validated['interest_ids']);
        $profile->syncCompletion();

        return new ProfileResource($request->user()->load(['profile.interests', 'profile.photos']));
    }
}
