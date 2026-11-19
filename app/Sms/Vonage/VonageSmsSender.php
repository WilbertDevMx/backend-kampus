<?php

namespace App\Sms\Vonage;

use App\Sms\SmsDeliveryFailed;
use App\Sms\SmsSender;
use App\Support\PhoneNumber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Manda SMS con la API de SMS de Vonage (`SMS_DRIVER=vonage`). Usa su API HTTP directamente, sin SDK, como
 * LiveKit en las videollamadas. Las llaves salen de `VONAGE_KEY` y `VONAGE_SECRET`.
 *
 * Mientras la cuenta esté en modo demo, Vonage solo entrega a los números de prueba registrados en su panel
 * y agrega un aviso al texto.
 *
 * @see https://developer.vonage.com/en/api/sms
 */
class VonageSmsSender implements SmsSender
{
    public const ENDPOINT = 'https://rest.nexmo.com/sms/json';

    public function __construct(
        private readonly string $key,
        private readonly string $secret,
        private readonly string $from,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('services.vonage.key'),
            (string) config('services.vonage.secret'),
            (string) config('services.vonage.sms_from'),
        );
    }

    public function send(string $to, string $message): void
    {
        if ($this->key === '' || $this->secret === '') {
            throw new SmsDeliveryFailed('Faltan VONAGE_KEY o VONAGE_SECRET.');
        }

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(10)
                ->post(self::ENDPOINT, [
                    'api_key' => $this->key,
                    'api_secret' => $this->secret,
                    'from' => $this->from,
                    // Vonage espera el número sin el "+".
                    'to' => ltrim($to, '+'),
                    // Sin acentos ni "ñ" ("código" -> "codigo"): en texto normal (GSM-7) un SMS admite 160
                    // caracteres; en Unicode solo 70, y un código de 119 caracteres se cobraba como 2 SMS.
                    'text' => Str::ascii($message),
                    'type' => 'text',
                ]);
        } catch (ConnectionException $exception) {
            throw new SmsDeliveryFailed('No se pudo conectar con Vonage.', previous: $exception);
        }

        // Vonage responde 200 aunque rechace el mensaje: el resultado viene en messages[0].status ("0" = aceptado).
        $status = (string) $response->json('messages.0.status', '');

        if (! $response->successful() || $status !== '0') {
            $error = (string) $response->json('messages.0.error-text', 'respuesta inesperada');

            Log::channel('security')->warning('Vonage no aceptó un SMS.', [
                'to' => PhoneNumber::mask($to),
                'http_status' => $response->status(),
                'vonage_status' => $status,
                'error' => $error,
            ]);

            throw new SmsDeliveryFailed("Vonage no aceptó el SMS (estado {$status}): {$error}");
        }
    }
}
