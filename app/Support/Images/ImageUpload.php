<?php

namespace App\Support\Images;

/**
 * Reglas y mensajes de validación de las imágenes que suben los usuarios (perfil, grupos y chat).
 *
 * La API no tiene traducciones al español, así que sin estos mensajes Laravel respondería en inglés
 * ("The photo field must not be greater than 5120 kilobytes.").
 */
final class ImageUpload
{
    /** Peso máximo, en kilobytes (5 MB). En producción, PHP acepta hasta 6 MB y Nginx hasta 8 MB por petición. */
    public const MAX_KB = 5120;

    /**
     * @param  list<string>  $formats  extensiones aceptadas
     * @return list<string>
     */
    public static function rules(array $formats = ['jpg', 'jpeg', 'png', 'webp']): array
    {
        return ['file', 'image', 'mimes:'.implode(',', $formats), 'max:'.self::MAX_KB];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(string $field, string $formatsLabel = 'JPG, PNG o WebP'): array
    {
        return [
            "{$field}.required" => 'Elige una imagen.',
            // Si el archivo pasa del límite de PHP, llega sin contenido y falla esta regla, no la de `max`.
            "{$field}.uploaded" => 'La imagen no se pudo subir. Revisa que pese menos de 5 MB.',
            "{$field}.file" => 'La imagen no se pudo subir. Revisa que pese menos de 5 MB.',
            "{$field}.image" => "El archivo debe ser una imagen {$formatsLabel}.",
            "{$field}.mimes" => "El archivo debe ser una imagen {$formatsLabel}.",
            "{$field}.max" => 'La imagen no puede pesar más de 5 MB.',
        ];
    }
}
