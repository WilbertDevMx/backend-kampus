<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use Closure;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class RegisterController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $universityId = null;

        // Mismo formato que el login (LoginRequest): sin espacios y en minúsculas. Así `unique` y la
        // búsqueda del dominio comparan contra lo que se guarda.
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower(trim($request->input('email')))]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'bail',
                'required',
                'string',
                'email',
                'max:255',
                'unique:users,email',
                function (string $attribute, mixed $value, Closure $fail) use (&$universityId): void {
                    if (! is_string($value) || ! str_contains($value, '@')) {
                        return;
                    }

                    $domain = strtolower(substr($value, strrpos($value, '@') + 1));
                    $universityId = DB::table('universities')
                        ->where('is_active', true)
                        ->whereJsonContains('email_domains', $domain)
                        ->value('id');

                    if ($universityId === null) {
                        $fail('El dominio del correo no está permitido.');
                    }
                },
            ],
            // Política del proyecto (AppServiceProvider): mínimo 8, letras, mayúsculas, minúsculas y números.
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        // Solo pasan los campos validados: `role` nunca viene del request, así que el registro público
        // siempre crea una cuenta `student` (RF-22).
        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'university_id' => $universityId,
        ]);

        event(new Registered($user));

        return response()->json([
            'message' => 'Registro exitoso. Revisa tu correo para verificar tu cuenta.',
            'user' => new UserResource($user->refresh()),
        ], 201);
    }
}
