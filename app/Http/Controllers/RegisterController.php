<?php

namespace App\Http\Controllers;

use App\Models\User;
use Closure;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class RegisterController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                'unique:users,email',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) || ! str_contains($value, '@')) {
                        return;
                    }

                    $domain = strtolower(substr($value, strrpos($value, '@') + 1));

                    if (! in_array($domain, config('auth.allowed_email_domains', []), true)) {
                        $fail('El dominio del correo no está permitido.');
                    }
                },
            ],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = User::create($validated);

        event(new Registered($user));

        return response()->json([
            'message' => 'Registro exitoso. Revisa tu correo para verificar tu cuenta.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at,
            ],
        ], 201);
    }
}
