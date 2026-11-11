<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Support\Security\MasksEmail;
use App\Support\Security\TwoFactor;
use Dedoc\Scramble\Attributes\Group;
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
#[Group('Autenticación', weight: 1)]
class RecoverPasswordController extends Controller
{
    /**
     * T-47: con la verificación en dos pasos activa, el enlace del correo no basta para cambiar la contraseña;
     * también hace falta un código de la app o un código de respaldo. Así, quien entre al correo del alumno no
     * se queda con la cuenta.
     *
     * Solo se revisa cuando el enlace es válido: sin un enlace válido, la respuesta es la misma de siempre y no
     * revela qué cuentas tienen la verificación en dos pasos.
     */
    private function ensureSecondFactor(ResetPasswordRequest $request, TwoFactor $twoFactor): void
    {
        $broker = PasswordBroker::broker();
        $user = $broker->getUser(['email' => $request->string('email')->toString()]);

        if (! $user instanceof User
            || ! $user->hasTwoFactorEnabled()
            || ! $broker->tokenExists($user, $request->string('token')->toString())) {
            return;
        }

        $code = $request->string('code')->toString();
        $recoveryCode = $request->string('recovery_code')->toString();

        if ($code === '' && $recoveryCode === '') {
            throw ValidationException::withMessages([
                'code' => 'Tu cuenta tiene la verificación en dos pasos activa: escribe el código de tu app o un código de respaldo.',
            ]);
        }

        $valid = $recoveryCode !== ''
            ? $twoFactor->useRecoveryCode($user, $recoveryCode)
            : $twoFactor->verify($user, $user->two_factor_secret, $code);

        if (! $valid) {
            Log::channel('security')->notice('Código de verificación en dos pasos incorrecto al restablecer la contraseña.', [
                'user_id' => $user->getKey(),
                'ip' => $request->ip(),
            ]);

            throw ValidationException::withMessages([$recoveryCode !== '' ? 'recovery_code' : 'code' => 'El código no es correcto.']);
        }
    }

    /**
     * Pedir el enlace para restablecer la contraseña.
     *
     * Responde lo mismo exista o no la cuenta, para no revelar qué correos están registrados (T-45).
     *
     * @unauthenticated
     */
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

    /**
     * Restablecer la contraseña.
     *
     * Usa el token del enlace del correo. Si la cuenta tiene la verificación en dos pasos, también pide `code`
     * o `recovery_code` (T-47).
     *
     * @unauthenticated
     */
    public function reset(ResetPasswordRequest $request, TwoFactor $twoFactor): JsonResponse
    {
        $this->ensureSecondFactor($request, $twoFactor);

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
