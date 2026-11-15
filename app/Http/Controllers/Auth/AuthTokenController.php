<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Support\Security\AccessTokenIssuer;
use App\Support\Security\TwoFactorChallenge;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

#[Group('Autenticación', 'Registro, verificación de correo, inicio y cierre de sesión y recuperar la contraseña (T-04, T-05, T-45).', weight: 1)]
class AuthTokenController extends Controller
{
    /**
     * Iniciar sesión.
     *
     * Emite un token de Sanctum con fecha de expiración (RF-02). Si la cuenta tiene la verificación en dos pasos
     * activa, responde `200` con `two_factor_required` y un `challenge_token` en lugar del token.
     *
     * @unauthenticated
     */
    public function store(LoginRequest $request, AccessTokenIssuer $issuer, TwoFactorChallenge $challenges): JsonResponse
    {
        $user = $request->authenticate();

        // RF-01: la cuenta permanece inactiva hasta verificar el correo institucional.
        if (! $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Verifica tu correo institucional antes de iniciar sesión.',
            ], 403);
        }

        // RF-15 y RF-22: una cuenta suspendida por moderación o un staff desactivado no recibe token.
        if ($user->suspended_at !== null) {
            return response()->json([
                'message' => 'Tu cuenta está suspendida.',
            ], 403);
        }

        $deviceName = $request->input('device_name', 'api');

        // T-47: con la verificación en dos pasos activa, la contraseña sola no basta. En lugar del token se
        // responde un reto que se completa con el código de la app en POST /api/login/two-factor.
        if ($user->hasTwoFactorEnabled()) {
            return response()->json([
                'message' => 'Escribe el código de tu app de autenticación.',
                'two_factor_required' => true,
                'challenge_token' => $challenges->create($user, $deviceName),
                'expires_in' => TwoFactorChallenge::TTL_SECONDS,
            ]);
        }

        return $issuer->issue($user, $request, $deviceName);
    }

    /**
     * Cierra la sesión revocando solo el token con el que se hizo la petición.
     */
    public function destroy(Request $request): Response
    {
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->noContent();
    }
}
