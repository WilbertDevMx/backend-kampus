<?php

namespace App\Http\Requests\Admin;

use App\Enums\GroupCategory;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Grupo oficial creado por el staff (T-42, RF-23): se publica sin revisión y lleva la insignia "Oficial".
 */
class StoreOfficialGroupRequest extends FormRequest
{
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
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'category' => ['required', Rule::enum(GroupCategory::class)],
            // Solo un administrador elige la universidad (o ninguna, para un grupo interinstitucional);
            // el grupo de un moderador siempre es de su universidad.
            'university_id' => [
                'bail',
                'nullable',
                Rule::prohibitedIf(fn (): bool => $this->user()->role === UserRole::Moderator),
                'integer',
                Rule::exists('universities', 'id')->where('is_active', true),
            ],
            // Estudiante que administrará el grupo: el staff no usa las funciones sociales.
            'admin_user_id' => ['bail', 'nullable', 'integer'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || $this->input('admin_user_id') === null) {
                    return;
                }

                $student = $this->groupAdmin();
                $universityId = $this->universityId();

                if ($student === null || ($universityId !== null && (int) $student->university_id !== $universityId)) {
                    $validator->errors()->add(
                        'admin_user_id',
                        'El administrador del grupo debe ser un estudiante activo de la universidad del grupo.',
                    );
                }
            },
        ];
    }

    /**
     * Universidad del grupo: la del moderador, o la que eligió el administrador (`null` = interinstitucional).
     */
    public function universityId(): ?int
    {
        $staff = $this->user();

        if ($staff->role === UserRole::Moderator) {
            return (int) $staff->university_id;
        }

        return $this->input('university_id') === null ? null : (int) $this->input('university_id');
    }

    public function groupAdmin(): ?User
    {
        if ($this->input('admin_user_id') === null) {
            return null;
        }

        return User::query()
            ->where('role', UserRole::Student)
            ->whereNotNull('email_verified_at')
            ->whereNull('suspended_at')
            ->find((int) $this->input('admin_user_id'));
    }
}
