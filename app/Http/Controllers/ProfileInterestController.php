<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProfileResource;
use App\Models\Interest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileInterestController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Interest::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request): ProfileResource
    {
        $validated = $request->validate([
            'interest_ids' => ['present', 'array', 'max:20'],
            'interest_ids.*' => ['integer', 'distinct', 'exists:interests,id'],
        ]);

        $profile = $request->user()->profile()->firstOrCreate([]);
        $profile->interests()->sync($validated['interest_ids']);

        return new ProfileResource($request->user()->load(['profile.interests', 'profile.photos']));
    }
}
