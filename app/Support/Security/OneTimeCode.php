<?php

namespace App\Support\Security;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Códigos de 6 dígitos que se mandan por SMS (T-45): verificar el celular y recuperar la contraseña.
 *
 * - Se guardan en la caché solo como HMAC con la llave de la app, nunca en claro.
 * - Vencen a los 10 minutos y son de un solo uso.
 * - Tras 5 intentos fallidos el código se invalida y hay que pedir otro.
 * - No se manda otro código al mismo destino antes de 60 segundos, ni más de 5 por hora: así nadie gasta el
 *   crédito de SMS ni prueba códigos sin límite.
 */
class OneTimeCode
{
    public const TTL_SECONDS = 600;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_SECONDS = 60;

    public const MAX_PER_HOUR = 5;

    /**
     * Genera un código para `$purpose` y `$subject` (por ejemplo, el id del usuario) y reemplaza al anterior.
     * `$data` se guarda junto al código y lo devuelve `consume()`. Devuelve `null` si el anterior se pidió hace
     * menos de 60 segundos o ya se pidieron 5 en la última hora.
     *
     * @param  array<string, mixed>  $data
     */
    public function issue(string $purpose, string|int $subject, array $data = []): ?string
    {
        $key = $this->key($purpose, $subject);
        $current = Cache::get($key);
        $now = now()->getTimestamp();

        if (is_array($current) && $now - $current['issued_at'] < self::RESEND_SECONDS) {
            return null;
        }

        $hourly = "{$key}:per-hour";
        if (RateLimiter::tooManyAttempts($hourly, self::MAX_PER_HOUR)) {
            return null;
        }
        RateLimiter::hit($hourly, 3600);

        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

        Cache::put($key, [
            'hash' => $this->hash($purpose, $subject, $code),
            'attempts' => 0,
            'issued_at' => $now,
            'expires_at' => $now + self::TTL_SECONDS,
            'data' => $data,
        ], self::TTL_SECONDS);

        return $code;
    }

    /**
     * Comprueba el código. Si es correcto lo borra (un solo uso) y devuelve su `$data`; si no, cuenta el
     * intento y devuelve `null`. También devuelve `null` si no hay código o ya venció.
     *
     * @return array<string, mixed>|null
     */
    public function consume(string $purpose, string|int $subject, string $code): ?array
    {
        $key = $this->key($purpose, $subject);
        $entry = Cache::get($key);
        $now = now()->getTimestamp();

        if (! is_array($entry) || $entry['expires_at'] <= $now) {
            return null;
        }

        if (hash_equals($entry['hash'], $this->hash($purpose, $subject, trim($code)))) {
            Cache::forget($key);

            return $entry['data'];
        }

        $entry['attempts']++;

        if ($entry['attempts'] >= self::MAX_ATTEMPTS) {
            Cache::forget($key);
        } else {
            Cache::put($key, $entry, $entry['expires_at'] - $now);
        }

        return null;
    }

    public function forget(string $purpose, string|int $subject): void
    {
        Cache::forget($this->key($purpose, $subject));
    }

    private function key(string $purpose, string|int $subject): string
    {
        return "one-time-code:{$purpose}:{$subject}";
    }

    private function hash(string $purpose, string|int $subject, string $code): string
    {
        return hash_hmac('sha256', "{$purpose}|{$subject}|{$code}", (string) config('app.key'));
    }
}
