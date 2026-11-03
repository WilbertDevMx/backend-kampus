<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Desactivar o reactivar una cuenta de staff (T-41, RF-22). Siempre con motivo, para la bitácora.
 */
class UpdateStaffRequest extends FormRequest
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
            'active' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
