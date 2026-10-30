<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;

class RegisterController extends Controller
{
    public function store(RegisterRequest $request): JsonResponse
    {
        // Solo pasan los campos validados: `role` nunca viene del request, así que el registro público
        // siempre crea una cuenta `student` (RF-22).
        $user = User::create([
            ...$request->safe()->only(['name', 'email', 'password']),
            'university_id' => $request->universityId(),
        ]);

        event(new Registered($user));

        // La app decide con esto si muestra "Revisa tu correo" o manda directo al login.
        $verificationRequired = ! $user->hasVerifiedEmail();

        return response()->json([
            'message' => $verificationRequired
                ? 'Registro exitoso. Revisa tu correo para verificar tu cuenta.'
                : 'Registro exitoso. Ya puedes iniciar sesión.',
            'email_verification_required' => $verificationRequired,
            'user' => new UserResource($user->refresh()),
        ], 201);
    }
}
