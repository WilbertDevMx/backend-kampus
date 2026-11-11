<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Security\TwoFactor;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Configuración de la verificación en dos pasos (T-47, RF-02, RNF-02). Activar, desactivar y regenerar los
 * códigos de respaldo piden la contraseña actual: un token robado no basta para cambiar la seguridad de la cuenta.
 *
 * 1. POST /two-factor: genera el secreto y devuelve la URI para el QR. Todavía no está activa.
 * 2. POST /two-factor/confirm: con el primer código de la app, queda activa y se entregan los códigos de respaldo.
 */
#[Group('Verificación en dos pasos', 'Activar, confirmar y desactivar el código de la app de autenticación, y los códigos de respaldo (T-47).', weight: 3)]
class TwoFactorController extends Controller
{
    public function show(Request $request, TwoFactor $twoFactor): JsonResponse
    {
        $user = $request->user();

        return response()->json(['data' => [
            'enabled' => $user->hasTwoFactorEnabled(),
            'pending_confirmation' => $user->two_factor_secret !== null && ! $user->hasTwoFactorEnabled(),
            'confirmed_at' => $user->two_factor_confirmed_at?->toIso8601String(),
            'recovery_codes_left' => $user->hasTwoFactorEnabled() ? $twoFactor->recoveryCodesLeft($user) : 0,
        ]]);
    }

    /**
     * Empieza la configuración. Si ya había una sin confirmar, la reemplaza con un secreto nuevo.
     */
    public function store(Request $request, TwoFactor $twoFactor): JsonResponse
    {
        $this->confirmPassword($request);
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'La verificación en dos pasos ya está activa.'], 409);
        }

        $secret = $twoFactor->generateSecret();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return response()->json(['data' => [
            // Para escribirlo a mano si la app no puede escanear el QR.
            'secret' => $secret,
            'otpauth_url' => $twoFactor->otpauthUrl($user, $secret),
        ]], 201);
    }

    /**
     * Confirma con el primer código de la app: desde aquí el login lo pide. Los códigos de respaldo se
     * muestran solo esta vez.
     */
    public function confirm(Request $request, TwoFactor $twoFactor): JsonResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $user = $request->user();

        if ($user->two_factor_secret === null || $user->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'No hay una configuración pendiente de confirmar.'], 409);
        }

        if (! $twoFactor->verify($user, $user->two_factor_secret, $validated['code'])) {
            throw ValidationException::withMessages(['code' => 'El código no es correcto. Revisa que la hora de tu celular esté bien.']);
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $codes = $twoFactor->regenerateRecoveryCodes($user);

        $this->log($user, 'Verificación en dos pasos activada.');

        return response()->json(['data' => ['recovery_codes' => $codes]]);
    }

    public function destroy(Request $request): Response
    {
        $this->confirmPassword($request);
        $user = $request->user();

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        $this->log($user, 'Verificación en dos pasos desactivada.');

        return response()->noContent();
    }

    /**
     * Códigos de respaldo nuevos; los anteriores dejan de servir.
     */
    public function regenerateRecoveryCodes(Request $request, TwoFactor $twoFactor): JsonResponse
    {
        $this->confirmPassword($request);
        $user = $request->user();

        if (! $user->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'La verificación en dos pasos no está activa.'], 409);
        }

        $codes = $twoFactor->regenerateRecoveryCodes($user);
        $this->log($user, 'Códigos de respaldo regenerados.');

        return response()->json(['data' => ['recovery_codes' => $codes]]);
    }

    private function confirmPassword(Request $request): void
    {
        $request->validate(
            ['password' => ['required', 'string', 'current_password:sanctum']],
            ['password.current_password' => 'La contraseña no es correcta.'],
        );
    }

    private function log(User $user, string $message): void
    {
        Log::channel('security')->notice($message, ['user_id' => $user->getKey()]);
    }
}
