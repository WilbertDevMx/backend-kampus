<?php

namespace App\Http\Requests\Groups;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Publicar o editar una actividad de grupo (T-17, RF-12). Al editar, cada campo es opcional.
 */
class StoreGroupActivityRequest extends FormRequest
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
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'max:150'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'starts_at' => [$required, 'date', 'after:now'],
        ];
    }
}
