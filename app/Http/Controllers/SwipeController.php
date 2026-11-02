<?php

namespace App\Http\Controllers;

use App\Models\Swipe;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SwipeController extends Controller
{
    /**
     * "Me interesa" (`liked: true`) o "Pasar" (`liked: false`) sobre un perfil del descubrimiento (RF-05).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
            'liked' => ['required', 'boolean'],
        ]);

        $swiper = $request->user();

        // Mismo mensaje si el perfil no existe, es staff, está bloqueado o suspendido: no se revela cuál.
        $target = User::query()->discoverableBy($swiper)->find($validated['user_id']);

        if ($target === null) {
            throw ValidationException::withMessages([
                'user_id' => 'Este perfil no está disponible.',
            ]);
        }

        $alreadyEvaluated = Swipe::query()
            ->where('swiper_id', $swiper->getKey())
            ->where('swiped_id', $target->getKey())
            ->exists();

        if ($alreadyEvaluated) {
            return $this->alreadyEvaluated();
        }

        try {
            $swipe = Swipe::create([
                'swiper_id' => $swiper->getKey(),
                'swiped_id' => $target->getKey(),
                'liked' => $validated['liked'],
            ]);
        } catch (UniqueConstraintViolationException) {
            // Dos peticiones casi simultáneas: la llave única (swiper_id, swiped_id) ya guardó la primera.
            return $this->alreadyEvaluated();
        }

        return response()->json([
            'data' => [
                'user_id' => $target->getKey(),
                'liked' => $swipe->liked,
            ],
        ], 201);
    }

    private function alreadyEvaluated(): JsonResponse
    {
        return response()->json(['message' => 'Ya evaluaste este perfil.'], 409);
    }
}
