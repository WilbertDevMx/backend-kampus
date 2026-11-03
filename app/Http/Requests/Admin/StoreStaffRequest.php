<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\University;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * Alta de una cuenta de staff por un administrador (T-41, RF-22).
 */
class StoreStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Mismo formato que el login y el registro: sin espacios y en minúsculas.
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
            'email' => ['bail', 'required', 'string', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in([UserRole::Moderator->value, UserRole::Admin->value])],
            // El moderador tiene una sola universidad; el administrador ninguna, porque las ve todas.
            'university_id' => [
                'bail',
                'nullable',
                'required_if:role,'.UserRole::Moderator->value,
                'prohibited_if:role,'.UserRole::Admin->value,
                'integer',
                Rule::exists('universities', 'id')->where('is_active', true),
            ],
            'password' => ['required', 'confirmed', Password::defaults()],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }

    /**
     * El correo tiene que ser institucional y, en un moderador, del dominio de la universidad que se le asigna.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['email', 'role', 'university_id'])) {
                    return;
                }

                $university = University::forEmail($this->string('email')->toString());

                if ($university === null) {
                    $validator->errors()->add('email', 'El correo debe ser de una institución participante.');

                    return;
                }

                if ($this->input('role') === UserRole::Moderator->value
                    && (int) $university->getKey() !== (int) $this->input('university_id')) {
                    $validator->errors()->add('email', 'El correo debe ser del dominio de la universidad asignada.');
                }
            },
        ];
    }

    public function role(): UserRole
    {
        return UserRole::from($this->string('role')->toString());
    }

    public function universityId(): ?int
    {
        return $this->role() === UserRole::Moderator ? (int) $this->input('university_id') : null;
    }
}
