<?php

namespace App\Http\Requests\Messages;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Un mensaje es texto (`body`, T-14) o una imagen (`image`, T-15). La imagen puede llevar un texto opcional.
 */
class StoreMessageRequest extends FormRequest
{
    /** Tamaño máximo de una imagen del chat, en kilobytes (5 MB). */
    public const MAX_IMAGE_KB = 5120;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'body' => ['required_without:image', 'nullable', 'string', 'max:5000'],
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:'.self::MAX_IMAGE_KB],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required_without' => 'El mensaje no puede estar vacío.',
            'body.string' => 'El mensaje debe ser texto.',
            'body.max' => 'El mensaje no puede exceder 5000 caracteres.',
            // Si el archivo pasa del límite de PHP (6 MB en producción), llega con error y falla `uploaded`.
            'image.uploaded' => 'La imagen no se pudo subir. Revisa que pese menos de 5 MB.',
            'image.file' => 'La imagen no se pudo subir. Revisa que pese menos de 5 MB.',
            'image.image' => 'El archivo debe ser una imagen JPG o PNG.',
            'image.mimes' => 'El archivo debe ser una imagen JPG o PNG.',
            'image.max' => 'La imagen no puede pesar más de 5 MB.',
        ];
    }
}
