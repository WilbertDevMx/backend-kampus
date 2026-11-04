<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

/**
 * Correo de verificación del registro (T-04, RF-01), enviado por la cola para que el registro no espere
 * al servidor de correo.
 */
class VerifyEmailNotification extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    /**
     * El enlace abre la pantalla `/auth/verify-email` del frontend con `id`, `hash`, `expires` y
     * `signature`; esa pantalla llama a `GET /api/email/verify/{id}/{hash}` con la firma.
     */
    protected function verificationUrl($notifiable): string
    {
        $id = $notifiable->getKey();
        $hash = sha1($notifiable->getEmailForVerification());

        // Firma relativa (la ruta usa `signed:relative`): no depende del host de la API.
        $signedPath = URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(Config::get('auth.verification.expire', 60)),
            ['id' => $id, 'hash' => $hash],
            absolute: false,
        );

        parse_str((string) parse_url($signedPath, PHP_URL_QUERY), $signature);

        return rtrim((string) Config::get('app.frontend_url'), '/').'/auth/verify-email?'
            .http_build_query(['id' => $id, 'hash' => $hash] + $signature);
    }
}
