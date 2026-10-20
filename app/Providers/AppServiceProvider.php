<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
        //
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
