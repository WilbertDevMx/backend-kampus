<?php

namespace App\Http\Controllers;

use App\Discovery\RecordSwipe;
use App\Http\Resources\MatchResource;
use App\Models\Swipe;
use App\Models\User;
use App\Models\UserMatch;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SwipeController extends Controller
{
    /**
     * "Me interesa" (`liked: true`) o "Pasar" (`liked: false`) sobre un perfil del descubrimiento (RF-05).
     * Si el "Me interesa" es mutuo, crea el match (RF-07).
     */
    public function store(Request $request, RecordSwipe $recordSwipe): JsonResponse
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

        $liked = (bool) $validated['liked'];

        try {
            $match = $recordSwipe->handle($swiper, $target, $liked);
        } catch (UniqueConstraintViolationException) {
            // Dos peticiones casi simultáneas: la llave única (swiper_id, swiped_id) ya guardó la primera.
            return $this->alreadyEvaluated();
        }

        // `match` es el aviso inmediato para quien cierra el par; la otra persona lo recibe por WebSocket (T-10).
        return response()->json([
            'data' => [
                'user_id' => $target->getKey(),
                'liked' => $liked,
                'match' => $match === null
                    ? null
                    : new MatchResource($match->load(UserMatch::userSummaryRelations())),
            ],
        ], 201);
    }

    private function alreadyEvaluated(): JsonResponse
    {
        return response()->json(['message' => 'Ya evaluaste este perfil.'], 409);
    }
}
