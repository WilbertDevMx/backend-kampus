<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Correo para restablecer la contraseña (T-45), en español y por la cola.
 *
 * El enlace abre la pantalla de la app (`auth.password_reset_url`) con el token de un solo uso y el correo,
 * que la app manda después a `POST /api/reset-password`.
 */
class ResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;

    protected function resetUrl($notifiable): string
    {
        return config('auth.password_reset_url').'?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
    }

    protected function buildMailMessage($url): MailMessage
    {
        $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject('Restablece tu contraseña de Kampus')
            ->greeting('Hola')
            ->line('Recibimos una solicitud para restablecer la contraseña de tu cuenta de Kampus.')
            ->action('Restablecer contraseña', $url)
            ->line("El enlace vence en {$minutes} minutos y solo se puede usar una vez.")
            ->line('Si no lo pediste, ignora este correo: tu contraseña no cambia.')
            ->salutation('El equipo de Kampus');
    }
}
