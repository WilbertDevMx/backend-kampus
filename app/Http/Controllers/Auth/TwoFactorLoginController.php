<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Security\AccessTokenIssuer;
use App\Support\Security\TwoFactor;
use App\Support\Security\TwoFactorChallenge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Segundo paso del login con la verificación en dos pasos activa (T-47): recibe el `challenge_token` que
 * devolvió POST /api/login y un código de la app (o un código de respaldo) y entonces emite el token.
 */
class TwoFactorLoginController extends Controller
{
    public function __invoke(Request $request, TwoFactor $twoFactor, TwoFactorChallenge $challenges, AccessTokenIssuer $issuer): JsonResponse
    {
        $validated = $request->validate([
            'challenge_token' => ['required', 'string', 'size:64'],
            'code' => ['required_without:recovery_code', 'nullable', 'string', 'max:10'],
            'recovery_code' => ['required_without:code', 'nullable', 'string', 'max:20'],
        ], [
            'code.required_without' => 'Escribe el código de tu app o un código de respaldo.',
            'recovery_code.required_without' => 'Escribe el código de tu app o un código de respaldo.',
        ]);

        $challenge = $challenges->find($validated['challenge_token']);
        $user = $challenge === null ? null : User::query()->find($challenge['user_id']);

        // Reto vencido, agotado o ya usado; o la cuenta dejó de cumplir algo desde la contraseña.
        if ($user === null || ! $user->hasTwoFactorEnabled() || $user->suspended_at !== null) {
            throw ValidationException::withMessages([
                'challenge_token' => 'El inicio de sesión venció. Vuelve a escribir tu correo y contraseña.',
            ]);
        }

        $usedRecoveryCode = filled($validated['recovery_code'] ?? null);
        $valid = $usedRecoveryCode
            ? $twoFactor->useRecoveryCode($user, $validated['recovery_code'])
            : $twoFactor->verify($user, $user->two_factor_secret, $validated['code']);

        if (! $valid) {
            $stillOpen = $challenges->recordFailure($validated['challenge_token']);

            Log::channel('security')->notice('Código de verificación en dos pasos incorrecto.', [
                'user_id' => $user->getKey(),
                'ip' => $request->ip(),
            ]);

            throw ValidationException::withMessages($stillOpen
                ? [$usedRecoveryCode ? 'recovery_code' : 'code' => 'El código no es correcto.']
                : ['challenge_token' => 'Demasiados códigos incorrectos. Vuelve a escribir tu correo y contraseña.']);
        }

        $challenges->forget($validated['challenge_token']);

        if ($usedRecoveryCode) {
            Log::channel('security')->warning('Inicio de sesión con un código de respaldo.', [
                'user_id' => $user->getKey(),
                'left' => $twoFactor->recoveryCodesLeft($user),
            ]);
        }

        return $issuer->issue($user, $request, $challenge['device_name']);
    }
}
