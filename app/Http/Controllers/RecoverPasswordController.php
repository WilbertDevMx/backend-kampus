<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Support\Security\MasksEmail;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Recuperación de contraseña por correo (T-45, RF-02, RNF-02).
 *
 * El enlace es de un solo uso y vence a los 60 minutos (`config/auth.php`). La respuesta es la misma
 * exista o no el correo, para no revelar qué cuentas están registradas.
 */
class RecoverPasswordController extends Controller
{
    public function sendResetLink(ForgotPasswordRequest $request): JsonResponse
    {
        $email = $request->string('email')->toString();

        // El broker guarda el token con hash y no manda otro al mismo correo antes de 60 segundos.
        $status = PasswordBroker::sendResetLink(['email' => $email]);

        Log::channel('security')->info('Solicitud para restablecer la contraseña.', [
            'email' => MasksEmail::mask($email),
            'status' => $status,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Si el correo está registrado, te enviamos un enlace para restablecer tu contraseña.',
        ]);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = PasswordBroker::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                // Cierra la sesión en todos los dispositivos: si alguien más tenía acceso, lo pierde.
                $user->tokens()->delete();

                event(new PasswordReset($user));
            },
        );

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            // Mismo mensaje para token inválido, vencido o ya usado y para un correo que no existe.
            throw ValidationException::withMessages([
                'email' => 'El enlace para restablecer la contraseña no es válido o ya venció.',
            ]);
        }

        Log::channel('security')->notice('Contraseña restablecida.', [
            'email' => MasksEmail::mask($request->string('email')->toString()),
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Tu contraseña se restableció. Inicia sesión con la nueva.',
        ]);
    }
}
