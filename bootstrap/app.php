<?php

use App\Http\Middleware\EnsureProfileIsComplete;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Database\DatabaseErrorResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum', 'verified']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Detrás de Nginx: confía solo en los proxies indicados para leer la IP real y el esquema HTTPS.
        $middleware->trustProxies(at: array_filter(explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1'))));

        // Límite general de peticiones de la API (definido en AppServiceProvider).
        $middleware->throttleApi();

        // Global y al principio, antes de auth:sanctum y verified: así una ruta protegida responde 401 o 403 en JSON
        // aunque el cliente no mande `Accept: application/json`, en lugar de un 500 por la ruta `login` que no existe.
        $middleware->prepend(ForceJsonResponse::class);

        // Global, para que las cabeceras también salgan en respuestas de error (401, 403, 429...).
        $middleware->append(SecurityHeaders::class);

        // Acceso por rol (T-40): `role:student` en rutas sociales y `role:moderator,admin` en el panel.
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            // Perfil obligatorio (T-49): descubrir y swipe solo con el perfil completo.
            'profile.complete' => EnsureProfileIsComplete::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Errores de base de datos (T-52): sin host, puerto ni SQL en la respuesta, aunque APP_DEBUG esté activo.
        // QueryException extiende PDOException, así que esto cubre ambas.
        $exceptions->render(function (PDOException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return app(DatabaseErrorResponse::class)->render($e);
            }
        });
    })->create();
