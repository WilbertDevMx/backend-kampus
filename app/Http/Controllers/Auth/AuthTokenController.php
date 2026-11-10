<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

class AuthTokenController extends Controller
{
    /**
     * Inicia sesión y emite un token de Sanctum con fecha de expiración (RF-02).
     */
    public function store(LoginRequest $request): JsonResponse
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

        $expiresAt = now()->addMinutes(config('sanctum.expiration'));
        $token = $user->createToken($request->input('device_name', 'api'), ['*'], $expiresAt);

        // Para la lista de sesiones (T-46): desde qué navegador o app se inició sesión.
        $token->accessToken->forceFill(['user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null])->save();

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601String(),
            'user' => new UserResource($user),
        ], 201);
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
