<?php

namespace App\Http\Controllers;

use App\Events\VideoCallEnded;
use App\Events\VideoCallStarted;
use App\Models\User;
use App\Models\UserMatch;
use App\Video\VideoCallAccess;
use App\Video\VideoCallProvider;
use App\Video\VideoCallUnavailable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Videollamada 1 a 1 entre los dos de un match (T-19, RF-10).
 *
 * - Solo los dos del match reciben un token, y solo si el match sigue visible: nadie suspendido y sin bloqueo.
 * - La sala es una por match y admite como máximo 2 participantes.
 * - Las credenciales de LiveKit nunca salen del servidor: la app solo recibe un token de 15 minutos para su sala.
 */
class VideoCallController extends Controller
{
    private const MAX_PARTICIPANTS = 2;

    public function __construct(private readonly VideoCallProvider $provider) {}

    /**
     * Empieza la llamada: prepara la sala, entrega el token y avisa a la otra persona.
     */
    public function start(Request $request, int $match): JsonResponse
    {
        $user = $request->user();
        $conversation = $this->visibleMatch($user, $match);

        return $this->whenAvailable(function () use ($conversation, $user): JsonResponse {
            $access = $this->join($conversation, $user);

            VideoCallStarted::dispatch($conversation, $user, $conversation->otherUser($user));

            return $this->accessResponse($access, 201);
        });
    }

    /**
     * Contesta (o vuelve a entrar): solo entrega el token, sin avisar a nadie.
     */
    public function token(Request $request, int $match): JsonResponse
    {
        $user = $request->user();
        $conversation = $this->visibleMatch($user, $match);

        return $this->whenAvailable(fn (): JsonResponse => $this->accessResponse($this->join($conversation, $user)));
    }

    /**
     * Cuelga o rechaza: cierra la sala para los dos y avisa a la otra persona.
     */
    public function end(Request $request, int $match): JsonResponse|Response
    {
        $user = $request->user();
        $conversation = $this->visibleMatch($user, $match);

        return $this->whenAvailable(function () use ($conversation, $user): Response {
            $this->provider->closeRoom(self::roomFor($conversation));

            VideoCallEnded::dispatch($conversation, $user, $conversation->otherUser($user));

            return response()->noContent();
        });
    }

    /**
     * Nombre de la sala de un match. Es fijo, así los dos llegan a la misma.
     */
    public static function roomFor(UserMatch $match): string
    {
        return 'match-'.$match->getKey();
    }

    private function join(UserMatch $match, User $user): VideoCallAccess
    {
        $room = self::roomFor($match);

        // El límite de 2 va en la sala y en el token, como segunda barrera. La primera es esta API: solo los dos
        // del match reciben token, cada uno con su identidad fija (`user-{id}`).
        $this->provider->openRoom($room, self::MAX_PARTICIPANTS);

        return $this->provider->accessFor($room, 'user-'.$user->getKey(), (string) $user->name, self::MAX_PARTICIPANTS);
    }

    private function accessResponse(VideoCallAccess $access, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => [
                'url' => $access->url,
                'room' => $access->room,
                'token' => $access->token,
                'expires_at' => $access->expiresAt->toIso8601String(),
            ],
        ], $status);
    }

    /**
     * @param  callable(): (JsonResponse|Response)  $action
     */
    private function whenAvailable(callable $action): JsonResponse|Response
    {
        try {
            return $action();
        } catch (VideoCallUnavailable $error) {
            Log::warning('Videollamada no disponible: '.$error->getMessage());

            return response()->json([
                'message' => 'La videollamada no está disponible por ahora. Intenta más tarde o sigan por el chat.',
                'code' => 'video_unavailable',
            ], 503);
        }
    }

    private function visibleMatch(User $user, int $matchId): UserMatch
    {
        return UserMatch::query()
            ->visibleTo($user)
            ->whereKey($matchId)
            ->firstOrFail();
    }
}
