<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\SessionResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Gestión de sesiones (T-46, RF-02, RNF-02): cada sesión es un token de Sanctum. El usuario ve las suyas,
 * cierra una de otro dispositivo o todas las demás. Todas las consultas parten de `$user->tokens()`, así
 * que nadie puede ver ni cerrar una sesión ajena: una que no es suya responde 404, igual que una que no existe.
 */
#[Group('Sesiones', 'Sesiones activas en otros dispositivos y cómo cerrarlas (T-46).', weight: 2)]
class SessionController extends Controller
{
    /**
     * Sesiones activas: la actual primero y luego por último uso.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $currentId = $request->user()->currentAccessToken()?->getKey();

        $sessions = $this->activeTokens($request)
            ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$currentId ?? 0])
            ->orderByRaw('COALESCE(last_used_at, created_at) DESC')
            ->get();

        return SessionResource::collection($sessions);
    }

    /**
     * Cierra una sesión. Si es la actual, equivale a cerrar sesión.
     */
    public function destroy(Request $request, int $session): Response
    {
        $token = $this->activeTokens($request)->whereKey($session)->firstOrFail();
        $token->delete();

        Log::channel('security')->info('Sesión cerrada desde otro dispositivo.', [
            'user_id' => $request->user()->getKey(),
            'token_id' => $session,
        ]);

        return response()->noContent();
    }

    /**
     * Cierra todas las sesiones menos la actual.
     */
    public function destroyOthers(Request $request): JsonResponse
    {
        $closed = $request->user()->tokens()
            ->whereKeyNot($request->user()->currentAccessToken()?->getKey() ?? 0)
            ->delete();

        Log::channel('security')->info('Se cerraron las demás sesiones.', [
            'user_id' => $request->user()->getKey(),
            'closed' => $closed,
        ]);

        return response()->json(['closed' => $closed]);
    }

    /**
     * Tokens del usuario que no han vencido.
     */
    private function activeTokens(Request $request): MorphMany
    {
        return $request->user()->tokens()
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
