<?php

namespace App\Http\Controllers;

use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;

#[Group('Autenticación', weight: 1)]
class EmailVerificationController extends Controller
{
    /**
     * Verificar el correo.
     *
     * La app llama aquí con `expires` y `signature`, los datos del enlace firmado que llegó por correo (T-04).
     *
     * @unauthenticated
     */
    public function __invoke(string $id, string $hash): JsonResponse
    {
        $user = User::query()->findOrFail($id);

        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return response()->json([
                'message' => 'El enlace de verificación no es válido.',
            ], 403);
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        return response()->json([
            'message' => 'Correo verificado correctamente.',
        ]);
    }
}
