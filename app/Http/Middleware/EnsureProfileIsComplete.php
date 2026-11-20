<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Solo deja descubrir y hacer swipe a quien ya completó su perfil (T-49). Va después de
 * `role:student`. La app usa `code` para mandar al estudiante a terminar su perfil.
 */
class EnsureProfileIsComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->profile?->completed_at === null) {
            return response()->json([
                'message' => 'Completa tu perfil para usar esta sección.',
                'code' => 'profile_incomplete',
            ], 403);
        }

        return $next($request);
    }
}
