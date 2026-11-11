<?php

namespace App\Support\Security;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Emite el token de Sanctum de un inicio de sesión (RF-02). Lo usan el login normal y el segundo paso de la
 * verificación en dos pasos (T-47), para que las dos respuestas sean iguales.
 */
final class AccessTokenIssuer
{
    public function issue(User $user, Request $request, string $deviceName): JsonResponse
    {
        $expiresAt = now()->addMinutes(config('sanctum.expiration'));
        $token = $user->createToken($deviceName, ['*'], $expiresAt);

        // Para la lista de sesiones (T-46): desde qué navegador o app se inició sesión.
        $token->accessToken->forceFill(['user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null])->save();

        return response()->json([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601String(),
            'user' => new UserResource($user),
        ], 201);
    }
}
