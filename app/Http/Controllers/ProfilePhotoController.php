<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProfileResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ProfilePhotoController extends Controller
{
    public function store(Request $request): ProfileResource|Response
    {
        $validated = $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $user = $request->user();
        $profile = $user->profile()->firstOrCreate([]);
        $uploadedPhoto = $validated['photo'];
        $disk = config('filesystems.profile_photos_disk');
        $filename = Str::uuid().'.'.$uploadedPhoto->extension();
        $path = Storage::disk($disk)->putFileAs(
            'profiles/'.$user->getKey(),
            $uploadedPhoto,
            $filename,
            ['visibility' => 'private'],
        );

        if (! is_string($path)) {
            return response()->json(['message' => 'No fue posible guardar la foto.'], 503);
        }

        $profile->photos()->create([
            'path' => $path,
            'mime_type' => $uploadedPhoto->getMimeType(),
            'size' => $uploadedPhoto->getSize(),
        ]);

        return (new ProfileResource($user->load(['profile.interests', 'profile.photos'])))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(Request $request, int $photo): ProfileResource|Response
    {
        $profile = $request->user()->profile()->firstOrFail();
        $photoRecord = $profile->photos()->findOrFail($photo);

        if (! Storage::disk(config('filesystems.profile_photos_disk'))->delete($photoRecord->path)) {
            return response()->json(['message' => 'No fue posible eliminar la foto.'], 503);
        }

        $photoRecord->delete();

        return new ProfileResource($request->user()->load(['profile.interests', 'profile.photos']));
    }
}
