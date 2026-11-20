<?php

namespace App\Sms;

use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Log;

/**
 * Escribe el SMS en el log en lugar de mandarlo (`SMS_DRIVER=log`). Es el valor por defecto en desarrollo
 * y en las pruebas. Como el texto lleva el código, **no** se usa en producción.
 */
class LogSmsSender implements SmsSender
{
    public function send(string $to, string $message): void
    {
        Log::info('SMS (driver log, no se envió)', [
            'to' => PhoneNumber::mask($to),
            'message' => $message,
        ]);
    }
}
