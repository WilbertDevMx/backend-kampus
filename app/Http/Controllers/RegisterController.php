<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;

#[Group('Autenticación', weight: 1)]
class RegisterController extends Controller
{
    /**
     * Registrarse con el correo institucional.
     *
     * La universidad se asigna según el dominio del correo y se envía el correo de verificación (RF-01).
     *
     * @unauthenticated
     */
    public function store(RegisterRequest $request): JsonResponse
    {
        // Solo pasan los campos validados: `role` nunca viene del request, así que el registro público
        // siempre crea una cuenta `student` (RF-22).
        $user = User::create([
            ...$request->safe()->only(['name', 'email', 'password']),
            'university_id' => $request->universityId(),
        ]);

        event(new Registered($user));

        return response()->json([
            'message' => 'Registro exitoso. Revisa tu correo para verificar tu cuenta.',
            'user' => new UserResource($user->refresh()),
        ], 201);
    }
}
