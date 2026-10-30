<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProfileResource;
use App\Support\Images\ImageSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class ProfilePhotoController extends Controller
{
    public function store(Request $request, ImageSanitizer $sanitizer): ProfileResource|Response
    {
        $validated = $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'position' => ['sometimes', 'integer', 'between:1,5'],
        ]);

        // RNF-11: las fotos del celular traen la ubicación GPS exacta en el EXIF. Se re-codifican antes
        // de guardarlas, y nunca se guarda el archivo original.
        try {
            $cleanImage = $sanitizer->sanitize($validated['photo']);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'photo' => 'El archivo no es una imagen válida.',
            ]);
        }

        $user = $request->user();
        $profile = $user->profile()->firstOrCreate([]);
        $occupiedPositions = $profile->photos()->pluck('position')
            ->map(fn ($position): int => (int) $position)
            ->all();

        if (count($occupiedPositions) >= 5) {
            throw ValidationException::withMessages([
                'photo' => 'El perfil no puede tener más de 5 fotos.',
            ]);
        }

        $position = isset($validated['position'])
            ? (int) $validated['position']
            : collect(range(1, 5))->first(fn (int $candidate): bool => ! in_array($candidate, $occupiedPositions, true));

        if (in_array($position, $occupiedPositions, true)) {
            throw ValidationException::withMessages([
                'position' => 'La posición seleccionada ya está ocupada.',
            ]);
        }

        $disk = config('filesystems.profile_photos_disk');
        $path = 'profiles/'.$user->getKey().'/'.Str::uuid().'.'.$validated['photo']->extension();

        if (! Storage::disk($disk)->put($path, $cleanImage, ['visibility' => 'private'])) {
            return response()->json(['message' => 'No fue posible guardar la foto.'], 503);
        }

        $profile->photos()->create([
            'path' => $path,
            'position' => $position,
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
