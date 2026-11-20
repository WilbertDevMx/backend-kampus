<?php

namespace App\Sms;

/**
 * Envío de SMS (Adapter, T-45). El resto del código solo conoce esta interfaz: para cambiar de proveedor
 * basta cambiar `SMS_DRIVER` y la clase que se registra en `AppServiceProvider`.
 *
 * - `log`: escribe el mensaje en el log. Para desarrollo y pruebas, sin gastar crédito.
 * - `vonage`: lo manda con la API de SMS de Vonage.
 */
interface SmsSender
{
    /**
     * @param  string  $to  Número en formato E.164, por ejemplo `+528711234567`.
     *
     * @throws SmsDeliveryFailed si el proveedor no aceptó el mensaje.
     */
    public function send(string $to, string $message): void;
}
