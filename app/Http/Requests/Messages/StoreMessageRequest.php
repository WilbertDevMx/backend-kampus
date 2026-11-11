<?php

namespace App\Http\Requests\Messages;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => 'El mensaje no puede estar vacío.',
            'body.string' => 'El mensaje debe ser texto.',
            'body.max' => 'El mensaje no puede exceder 5000 caracteres.',
        ];
    }
}
