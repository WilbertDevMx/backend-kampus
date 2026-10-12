<?php

namespace App\Http\Controllers;

use App\Models\User;
use Closure;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

class RegisterController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $universityId = null;

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
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = User::create([
            ...$validated,
            'university_id' => $universityId,
        ]);

        event(new Registered($user));

        return response()->json([
            'message' => 'Registro exitoso. Revisa tu correo para verificar tu cuenta.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'university_id' => $user->university_id,
                'email_verified_at' => $user->email_verified_at,
            ],
        ], 201);
    }
}
