<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\SmsCodeNotification;
use App\Support\PhoneNumber;
use App\Support\Security\OneTimeCode;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Celular para recuperar la contraseña por SMS (T-45, RF-02). Para estudiantes y staff.
 *
 * El número solo se guarda después de comprobarlo con un código de 6 dígitos que llega por SMS. Agregarlo,
 * cambiarlo o quitarlo pide la contraseña actual: así, quien tome un teléfono con la sesión abierta no puede
 * poner su propio número para quedarse con la cuenta.
 */
#[Group('Autenticación', weight: 1)]
class PhoneController extends Controller
{
    /**
     * Celular registrado y si está verificado.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->state($request->user())]);
    }

    /**
     * Pide un código para agregar o cambiar el celular. El número no se guarda hasta confirmarlo.
     */
    public function store(Request $request, OneTimeCode $codes): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:25'],
            'password' => ['required', 'string', 'current_password:sanctum'],
        ], [
            'password.current_password' => 'La contraseña no es correcta.',
        ]);

        $user = $request->user();
        $phone = PhoneNumber::normalize($validated['phone']);

        if ($phone === null) {
            throw ValidationException::withMessages([
                'phone' => 'Escribe un número de celular de México de 10 dígitos.',
            ]);
        }

        if ($user->hasVerifiedPhone() && $user->phone === $phone) {
            throw ValidationException::withMessages([
                'phone' => 'Ese ya es tu celular verificado.',
            ]);
        }

        $this->ensureNotTaken($user, $phone);

        $code = $codes->issue(SmsCodeNotification::PURPOSE_PHONE, $user->getKey(), ['phone' => $phone]);

        if ($code === null) {
            return response()->json([
                'message' => 'Ya te enviamos un código hace poco. Espera un momento antes de pedir otro.',
            ], 429);
        }

        Notification::route('sms', $phone)->notify(new SmsCodeNotification(SmsCodeNotification::PURPOSE_PHONE, $code));

        return response()->json([
            'message' => 'Te enviamos un código por SMS al '.PhoneNumber::mask($phone).'.',
            'expires_in' => OneTimeCode::TTL_SECONDS,
        ], 202);
    }

    /**
     * Confirma el celular con el código del SMS y lo guarda como verificado.
     */
    public function verify(Request $request, OneTimeCode $codes): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'digits:6'],
        ]);

        $user = $request->user();
        $data = $codes->consume(SmsCodeNotification::PURPOSE_PHONE, $user->getKey(), $validated['code']);

        if ($data === null) {
            throw ValidationException::withMessages([
                'code' => 'El código no es correcto o ya venció.',
            ]);
        }

        // Otra cuenta pudo verificar el mismo número mientras tanto.
        $this->ensureNotTaken($user, $data['phone']);

        $user->forceFill([
            'phone' => $data['phone'],
            'phone_verified_at' => now(),
        ])->save();

        Log::channel('security')->notice('Celular verificado para recuperar la contraseña.', [
            'user_id' => $user->getKey(),
            'phone' => PhoneNumber::mask($data['phone']),
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Tu celular quedó verificado. Ya puedes recuperar tu contraseña por SMS.',
            'data' => $this->state($user),
        ]);
    }

    /**
     * Quita el celular. La contraseña ya no se podrá recuperar por SMS.
     */
    public function destroy(Request $request, OneTimeCode $codes): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string', 'current_password:sanctum'],
        ], [
            'password.current_password' => 'La contraseña no es correcta.',
        ]);

        $user = $request->user();
        $user->forceFill(['phone' => null, 'phone_verified_at' => null])->save();
        $codes->forget(SmsCodeNotification::PURPOSE_PHONE, $user->getKey());
        $codes->forget(SmsCodeNotification::PURPOSE_PASSWORD, $user->getKey());

        Log::channel('security')->notice('Celular eliminado.', ['user_id' => $user->getKey(), 'ip' => $request->ip()]);

        return response()->json([
            'message' => 'Quitamos tu celular.',
            'data' => $this->state($user),
        ]);
    }

    private function ensureNotTaken(User $user, string $phone): void
    {
        $taken = User::query()
            ->whereKeyNot($user->getKey())
            ->where('phone', $phone)
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'phone' => 'Ese número ya está registrado en otra cuenta.',
            ]);
        }
    }

    /**
     * @return array{phone: string|null, verified: bool}
     */
    private function state(User $user): array
    {
        return [
            'phone' => $user->phone,
            'verified' => $user->hasVerifiedPhone(),
        ];
    }
}
