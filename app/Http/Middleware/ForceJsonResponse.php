<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trata toda petición a la API como si pidiera JSON, aunque el cliente no mande `Accept: application/json`.
 *
 * Sin esto, `auth:sanctum` y `verified` intentan redirigir a las rutas web `login` y `verification.notice`,
 * que la API no tiene, y la respuesta es un 500 en lugar de 401 o 403.
 */
class ForceJsonResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/*')) {
            $request->headers->set('Accept', 'application/json');
        }

        return $next($request);
    }
}
