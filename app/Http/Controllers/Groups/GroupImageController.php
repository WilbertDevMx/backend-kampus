<?php

namespace App\Http\Controllers\Groups;

use App\Http\Controllers\Controller;
use App\Http\Resources\GroupResource;
use App\Models\Group;
use App\Support\Images\ImageSanitizer;
use App\Support\Images\ImageUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Imagen de un grupo (T-17, RF-12): misma limpieza y bucket privado que las fotos de perfil.
 */
class GroupImageController extends Controller
{
    public function store(Request $request, Group $group, ImageSanitizer $sanitizer): GroupResource|JsonResponse
    {
        Gate::authorize('manage', $group);

        $validated = $request->validate([
            'image' => ['required', ...ImageUpload::rules()],
        ], ImageUpload::messages('image'));

        // RNF-11: se re-codifica para quitar el EXIF (con el GPS) y nunca se guarda el archivo original.
        try {
            $cleanImage = $sanitizer->sanitize($validated['image']);
        } catch (InvalidArgumentException $error) {
            throw ValidationException::withMessages([
                'image' => $error->getMessage(),
            ]);
        }

        $disk = Storage::disk(config('filesystems.group_images_disk'));
        $path = 'groups/'.$group->getKey().'/'.Str::uuid().'.'.$validated['image']->extension();

        if (! $disk->put($path, $cleanImage, ['visibility' => 'private'])) {
            return response()->json(['message' => 'No fue posible guardar la imagen.'], 503);
        }

        if ($group->image_path !== null) {
            $disk->delete($group->image_path);
        }

        $group->update(['image_path' => $path]);

        return new GroupResource(Group::query()->forCard($request->user())->findOrFail($group->getKey()));
    }
}
