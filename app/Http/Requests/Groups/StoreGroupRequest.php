<?php

namespace App\Http\Requests\Groups;

use App\Enums\GroupCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Crear un grupo (T-17, RF-12). La imagen se sube aparte, en `POST /api/groups/{group}/image`.
 */
class StoreGroupRequest extends FormRequest
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
        ];
    }
}
