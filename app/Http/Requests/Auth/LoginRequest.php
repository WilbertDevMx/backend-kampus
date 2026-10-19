<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Intentos fallidos permitidos antes del bloqueo temporal (RF-02).
     */
    public const MAX_ATTEMPTS = 5;

    /**
     * Duración del bloqueo temporal, en segundos.
     */
    public const LOCKOUT_SECONDS = 900;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:100'],
        ];
    }

    /**
     * Valida las credenciales y devuelve al usuario.
     *
     * Cada intento fallido cuenta para el bloqueo por correo + IP; un inicio
     * de sesión correcto reinicia el contador.
     *
     * @throws ValidationException
     */
    public function authenticate(): User
    {
        $this->ensureIsNotRateLimited();

        $user = User::query()->where('email', $this->normalizedEmail())->first();

        if ($user === null || ! Hash::check($this->string('password')->toString(), $user->password)) {
            RateLimiter::hit($this->throttleKey(), self::LOCKOUT_SECONDS);

            throw ValidationException::withMessages([
                'email' => 'Las credenciales no son correctas.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        return $user;
    }

    public function normalizedEmail(): string
    {
        return Str::lower(trim($this->string('email')->toString()));
    }

    /**
     * @throws ValidationException
     */
    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $minutes = (int) ceil(RateLimiter::availableIn($this->throttleKey()) / 60);

        throw ValidationException::withMessages([
            'email' => "Demasiados intentos fallidos. Intenta de nuevo en {$minutes} minutos.",
        ])->status(429);
    }

    private function throttleKey(): string
    {
        return Str::transliterate($this->normalizedEmail().'|'.$this->ip());
    }
}
