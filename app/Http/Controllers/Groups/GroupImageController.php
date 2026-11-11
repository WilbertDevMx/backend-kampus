<?php

namespace App\Http\Controllers\Groups;

use App\Http\Controllers\Controller;
use App\Http\Resources\GroupResource;
use App\Models\Group;
use App\Support\Images\ImageSanitizer;
use Dedoc\Scramble\Attributes\Group as ApiGroup;
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
#[ApiGroup('Grupos', weight: 11)]
class GroupImageController extends Controller
{
    public function store(Request $request, Group $group, ImageSanitizer $sanitizer): GroupResource|JsonResponse
    {
        Gate::authorize('manage', $group);

        $validated = $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        // RNF-11: se re-codifica para quitar el EXIF (con el GPS) y nunca se guarda el archivo original.
        try {
            $cleanImage = $sanitizer->sanitize($validated['image']);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'image' => 'El archivo no es una imagen válida.',
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
