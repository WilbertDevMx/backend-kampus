<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Models\User;
use App\Notifications\SmsCodeNotification;
use App\Support\PhoneNumber;
use App\Support\Security\MasksEmail;
use App\Support\Security\OneTimeCode;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password as PasswordBroker;
use Illuminate\Validation\ValidationException;

/**
 * Recuperación de contraseña por SMS (T-45, RF-02), la segunda vía junto al correo.
 *
 * 1. `POST /forgot-password/sms` manda un código de 6 dígitos al celular verificado de la cuenta.
 * 2. `POST /forgot-password/sms/verify` cambia el código por el mismo token que lleva el enlace del correo.
 * 3. `POST /reset-password` cambia la contraseña con ese token, igual que por correo (con la verificación
 *    en dos pasos, si está activa, y cerrando todas las sesiones).
 *
 * Las respuestas no revelan si el correo existe ni si la cuenta tiene celular.
 */
#[Group('Autenticación', weight: 1)]
class SmsPasswordRecoveryController extends Controller
{
    /**
     * Pedir un código por SMS para restablecer la contraseña.
     *
     * Responde lo mismo exista o no la cuenta y tenga o no celular verificado.
     *
     * @unauthenticated
     */
    public function send(ForgotPasswordRequest $request, OneTimeCode $codes): JsonResponse
    {
        $email = $request->string('email')->toString();
        $user = User::query()->where('email', $email)->first();
        $sent = false;

        if ($user !== null && $user->hasVerifiedPhone()) {
            $code = $codes->issue(SmsCodeNotification::PURPOSE_PASSWORD, $user->getKey());

            if ($code !== null) {
                Notification::route('sms', $user->phone)
                    ->notify(new SmsCodeNotification(SmsCodeNotification::PURPOSE_PASSWORD, $code));
                $sent = true;
            }
        }

        Log::channel('security')->info('Solicitud de código por SMS para restablecer la contraseña.', [
            'email' => MasksEmail::mask($email),
            'sent' => $sent,
            'phone' => $sent ? PhoneNumber::mask($user->phone) : null,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Si la cuenta tiene un celular verificado, te enviamos un código por SMS. Vence en 10 minutos.',
        ]);
    }

    /**
     * Cambiar el código del SMS por el token para restablecer la contraseña.
     *
     * Con el token se llama a `POST /api/reset-password`, como con el enlace del correo. El código es de un
     * solo uso y se invalida tras 5 intentos fallidos.
     *
     * @unauthenticated
     */
    public function verify(ForgotPasswordRequest $request, OneTimeCode $codes): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'digits:6'],
        ]);

        $email = $request->string('email')->toString();
        $user = User::query()->where('email', $email)->first();

        $valid = $user !== null
            && $user->hasVerifiedPhone()
            && $codes->consume(SmsCodeNotification::PURPOSE_PASSWORD, $user->getKey(), $request->string('code')->toString()) !== null;

        if (! $valid) {
            Log::channel('security')->notice('Código de SMS incorrecto al restablecer la contraseña.', [
                'email' => MasksEmail::mask($email),
                'ip' => $request->ip(),
            ]);

            // Mismo mensaje para un código incorrecto o vencido y para un correo que no existe.
            throw ValidationException::withMessages([
                'code' => 'El código no es correcto o ya venció.',
            ]);
        }

        // El mismo token del enlace del correo: de un solo uso y válido por 60 minutos.
        $token = PasswordBroker::broker()->createToken($user);

        return response()->json([
            'message' => 'Código correcto. Ahora elige tu nueva contraseña.',
            'token' => $token,
            'email' => $user->email,
            // Si es `true`, `POST /reset-password` también pide `code` o `recovery_code` de la app (T-47).
            'two_factor_required' => $user->hasTwoFactorEnabled(),
        ]);
    }
}
