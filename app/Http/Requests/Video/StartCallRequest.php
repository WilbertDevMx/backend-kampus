<?php

namespace App\Http\Requests\Video;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Empezar una llamada (T-19, T-20): `type` es `video` (videollamada) o `audio` (llamada de voz).
 * Sin `type` es videollamada, como antes. La sala y el token son los mismos; el tipo solo le dice a la otra
 * persona qué tipo de llamada le entra.
 */
class StartCallRequest extends FormRequest
{
    public const TYPES = ['video', 'audio'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'type' => ['nullable', 'string', Rule::in(self::TYPES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.in' => 'El tipo de llamada debe ser video o audio.',
        ];
    }

    /** Tipo de llamada; `video` si no se manda. */
    public function callType(): string
    {
        return $this->validated('type') ?? 'video';
    }
}
