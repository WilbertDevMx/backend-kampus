<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * SMS con un código de un solo uso (T-45). Va por la cola, como los correos: así la API responde igual de
 * rápido exista o no la cuenta, y una falla del proveedor no rompe la petición.
 */
class SmsCodeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const PURPOSE_PHONE = 'phone';

    public const PURPOSE_PASSWORD = 'password';

    /** Reintentos si el proveedor falla (por ejemplo, un error de red). */
    public int $tries = 3;

    public function __construct(
        public readonly string $purpose,
        public readonly string $code,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['sms'];
    }

    public function toSms(object $notifiable): string
    {
        $action = $this->purpose === self::PURPOSE_PASSWORD
            ? 'restablecer tu contraseña'
            : 'verificar tu celular';

        return "Kampus: tu código para {$action} es {$this->code}. Vence en 10 minutos. Si no lo pediste, ignora este mensaje.";
    }
}
