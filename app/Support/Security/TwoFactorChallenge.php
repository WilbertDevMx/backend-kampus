<?php

namespace App\Support\Security;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Reto del segundo paso del login (T-47). Después de la contraseña correcta, la API entrega un
 * `challenge_token` de un solo uso que vence en 5 minutos y que se invalida tras 5 códigos equivocados.
 * En la caché solo se guarda su hash, nunca el token.
 */
final class TwoFactorChallenge
{
    public const TTL_SECONDS = 300;

    public const MAX_ATTEMPTS = 5;

    public function create(User $user, string $deviceName): string
    {
        $token = Str::random(64);
        $expiresAt = now()->addSeconds(self::TTL_SECONDS);

        Cache::put($this->key($token), [
            'user_id' => $user->getKey(),
            'device_name' => $deviceName,
            'attempts' => 0,
            'expires_at' => $expiresAt->getTimestamp(),
        ], $expiresAt);

        return $token;
    }

    /**
     * @return array{user_id: int, device_name: string, attempts: int, expires_at: int}|null
     */
    public function find(string $token): ?array
    {
        return Cache::get($this->key($token));
    }

    /**
     * Cuenta un código equivocado. Devuelve `false` si el reto ya se agotó y se borró.
     */
    public function recordFailure(string $token): bool
    {
        $challenge = $this->find($token);

        if ($challenge === null || ++$challenge['attempts'] >= self::MAX_ATTEMPTS) {
            $this->forget($token);

            return false;
        }

        // Un intento fallido no alarga la vida del reto: conserva su vencimiento original.
        Cache::put($this->key($token), $challenge, Carbon::createFromTimestamp($challenge['expires_at']));

        return true;
    }

    public function forget(string $token): void
    {
        Cache::forget($this->key($token));
    }

    private function key(string $token): string
    {
        return 'two-factor:challenge:'.hash('sha256', $token);
    }
}
