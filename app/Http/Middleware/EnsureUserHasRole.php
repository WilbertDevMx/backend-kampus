<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deja pasar solo a los roles indicados. Va después de `auth:sanctum`.
 *
 *   ->middleware('role:student')          rutas sociales (descubrir, swipe, chat): el staff no entra
 *   ->middleware('role:moderator,admin')  panel de administración: los estudiantes no entran
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null || ! in_array($user->role->value, $roles, true)) {
            return response()->json([
                'message' => 'Tu cuenta no tiene acceso a esta sección.',
            ], 403);
        }

        return $next($request);
    }
}
