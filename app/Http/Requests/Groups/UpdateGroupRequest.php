<?php

namespace App\Http\Requests\Groups;

use App\Enums\GroupCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Editar un grupo (T-17). Por ahora, editar un grupo aprobado no lo regresa a revisión (RF-23).
 */
class UpdateGroupRequest extends FormRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'category' => ['sometimes', 'required', Rule::enum(GroupCategory::class)],
        ];
    }
}
