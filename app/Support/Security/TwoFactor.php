<?php

namespace App\Support\Security;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Verificación en dos pasos con una app de autenticación (T-47): códigos TOTP de 6 dígitos que cambian cada
 * 30 segundos (Google Authenticator, Microsoft Authenticator, Authy…) y códigos de respaldo de un solo uso.
 */
final class TwoFactor
{
    public const RECOVERY_CODES = 8;

    /** Cuántos periodos de 30 s se aceptan antes y después del actual, por si el reloj del celular va desfasado. */
    private const WINDOW = 1;

    public function __construct(private readonly Google2FA $google2fa) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    /**
     * URI `otpauth://` que la app convierte en código QR para escanearlo.
     */
    public function otpauthUrl(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl((string) config('app.name', 'Kampus'), $user->email, $secret);
    }

    /**
     * Revisa un código TOTP. Un código aceptado no se puede volver a usar: así, quien lo vea en la pantalla de
     * alguien no puede reutilizarlo mientras siga vigente.
     */
    public function verify(User $user, string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        // Con un punto de partida que no sea nulo, la librería devuelve el periodo del código aceptado (y no
        // solo `true`), y rechaza cualquier código de ese periodo o de uno anterior.
        $key = "two-factor:last-timestep:{$user->getKey()}";
        $timestep = $this->google2fa->verifyKeyNewer($secret, $code, (int) Cache::get($key, 0), self::WINDOW, $this->currentTimestep());

        if (! is_int($timestep)) {
            return false;
        }

        Cache::put($key, $timestep, now()->addMinutes(5));

        return true;
    }

    /**
     * Periodo de 30 s actual según el reloj de Laravel (`now()`), no el de PHP: en producción es lo mismo, y en
     * las pruebas se puede mover con `travel()`.
     */
    public function currentTimestep(): int
    {
        return intdiv(now()->getTimestamp(), $this->google2fa->getKeyRegeneration());
    }

    /**
     * Genera códigos de respaldo nuevos y guarda solo su hash. Los códigos en claro se muestran una sola vez.
     *
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = collect(range(1, self::RECOVERY_CODES))
            ->map(fn (): string => Str::lower(Str::random(5).'-'.Str::random(5)))
            ->all();

        $user->forceFill(['two_factor_recovery_codes' => array_map(self::hash(...), $codes)])->save();

        return $codes;
    }

    /**
     * Usa un código de respaldo: si es válido, se borra y ya no sirve otra vez.
     */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $hashes = $user->two_factor_recovery_codes ?? [];
        $hash = self::hash($code);
        $index = array_search($hash, $hashes, true);

        if ($index === false) {
            return false;
        }

        unset($hashes[$index]);
        $user->forceFill(['two_factor_recovery_codes' => array_values($hashes)])->save();

        return true;
    }

    public function recoveryCodesLeft(User $user): int
    {
        return count($user->two_factor_recovery_codes ?? []);
    }

    /**
     * Los códigos de respaldo son aleatorios y largos, así que basta un SHA-256 (no hace falta bcrypt).
     */
    private static function hash(string $code): string
    {
        return hash('sha256', Str::lower(trim($code)));
    }
}
