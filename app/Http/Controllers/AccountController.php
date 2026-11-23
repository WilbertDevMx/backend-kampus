<?php

namespace App\Http\Controllers;

use App\Accounts\AccountDeletion;
use App\Http\Resources\UserResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

#[Group('Cuenta', 'Aceptar el aviso de privacidad y las normas de comunidad, y eliminar la cuenta (T-23).', weight: 2)]
class AccountController extends Controller
{
    /**
     * Aceptar los documentos legales.
     *
     * Guarda cuándo aceptó la cuenta el aviso de privacidad (`privacy`) y las normas de comunidad (`rules`)
     * (RF-04). Sirve para las cuentas que se registraron antes de que la app los pidiera, o para volver a
     * aceptarlos si cambian. Se manda al menos uno.
     */
    public function acceptLegal(Request $request): UserResource
    {
        $validated = $request->validate([
            // `accepted` también falla si el campo no viene; solo se aplica al documento que se manda.
            'privacy' => ['required_without:rules', Rule::when($request->has('privacy'), 'accepted')],
            'rules' => ['required_without:privacy', Rule::when($request->has('rules'), 'accepted')],
        ], [
            'privacy.required_without' => 'Acepta el aviso de privacidad o las normas de comunidad.',
            'rules.required_without' => 'Acepta el aviso de privacidad o las normas de comunidad.',
            'privacy.accepted' => 'Debes aceptar el aviso de privacidad.',
            'rules.accepted' => 'Debes aceptar las normas de comunidad.',
        ]);

        $user = $request->user();
        $user->forceFill(array_filter([
            'privacy_accepted_at' => isset($validated['privacy']) ? now() : null,
            'rules_accepted_at' => isset($validated['rules']) ? now() : null,
        ]))->save();

        return new UserResource($user);
    }

    /**
     * Eliminar mi cuenta.
     *
     * Borra la cuenta y todos sus datos de inmediato: perfil, fotos, matches con sus mensajes, bloqueos,
     * membresías y sesiones (RF-16). Las fotos e imágenes del bucket se borran por la cola, en minutos. No
     * se puede deshacer. Pide la contraseña. Las cuentas de staff se dan de baja en la gestión de staff.
     */
    public function destroy(Request $request, AccountDeletion $deletion): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string', 'current_password:sanctum'],
        ], [
            'password.current_password' => 'La contraseña no es correcta.',
        ]);

        $user = $request->user();

        if ($user->isStaff()) {
            return response()->json([
                'message' => 'Las cuentas de staff se dan de baja desde la gestión de staff.',
            ], 403);
        }

        $deletion->delete($user);

        return response()->json([
            'message' => 'Eliminamos tu cuenta y tus datos.',
        ]);
    }
}
