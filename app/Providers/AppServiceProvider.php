<?php

namespace App\Providers;

use App\Discovery\Ranking\DiscoveryRanking;
use App\Discovery\Ranking\SharedInterestsRanking;
use App\Models\User;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Peticiones por minuto permitidas a la API, por usuario o por IP.
     */
    public const API_REQUESTS_PER_MINUTE = 120;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Criterio de orden del descubrimiento (Strategy): se cambia aquí sin tocar la consulta.
        $this->app->bind(DiscoveryRanking::class, SharedInterestsRanking::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configurePasswordRules();
        $this->configureRateLimiting();

        // En producción todas las URL generadas (verificación de correo, etc.) usan HTTPS (RNF-02).
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        // Los eventos de seguridad los registra App\Listeners\SecurityEventSubscriber,
        // que Laravel descubre solo en app/Listeners.

        $this->configureApiDocs();
    }

    /**
     * Contrato OpenAPI generado desde el código con Scramble (T-33, RNF-07). Todas las rutas usan el token de
     * Sanctum, salvo las marcadas con `@unauthenticated` (registro, login, recuperar contraseña…).
     */
    private function configureApiDocs(): void
    {
        Scramble::configure()->withDocumentTransformers(function (OpenApi $openApi): void {
            $openApi->secure(SecurityScheme::http('bearer'));
        });

        // `/docs/api` se ve sin sesión, pero solo donde está habilitado (fuera de producción por defecto).
        Gate::define('viewApiDocs', fn (?User $user = null): bool => (bool) config('kampus.api_docs_enabled'));
    }

    /**
     * Política de contraseñas del proyecto: úsala con `Password::defaults()` (RNF-02).
     */
    private function configurePasswordRules(): void
    {
        Password::defaults(function () {
            $rule = Password::min(8)->letters()->mixedCase()->numbers();

            // Consulta Have I Been Pwned solo en producción, para que las pruebas no dependan de la red.
            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });
    }

    /**
     * Límite general de la API, además de los límites específicos de login y registro.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(self::API_REQUESTS_PER_MINUTE)
                ->by($request->user()?->getAuthIdentifier() ?: $request->ip());
        });
    }
}
