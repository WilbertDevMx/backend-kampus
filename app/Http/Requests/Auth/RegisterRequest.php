<?php

namespace App\Http\Requests\Auth;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    private ?int $universityId = null;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Mismo formato que el login (LoginRequest): sin espacios y en minúsculas. Así `unique` y la
     * búsqueda del dominio comparan contra lo que se guarda.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower(trim($this->input('email')))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['bail', 'required', 'string', 'email', 'max:255', 'unique:users,email', $this->allowedDomain(...)],
            // Política del proyecto (AppServiceProvider): mínimo 8, letras, mayúsculas, minúsculas y números.
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * Universidad activa a la que pertenece el dominio del correo (RF-01), o null si ninguna lo acepta.
     */
    public function universityId(): ?int
    {
        $domain = Str::afterLast($this->string('email')->toString(), '@');

        return $this->universityId ??= DB::table('universities')
            ->where('is_active', true)
            ->whereJsonContains('email_domains', $domain)
            ->value('id');
    }

    private function allowedDomain(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->universityId() === null) {
            $fail('El dominio del correo no está permitido.');
        }
    }
}
