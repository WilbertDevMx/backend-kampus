<?php

namespace App\Support\Security;

use Illuminate\Support\Str;

/**
 * Oculta la mayor parte de un correo para poder registrarlo en los logs sin exponerlo completo.
 */
final class MasksEmail
{
    /**
     * Ejemplo: "alumno@utt.edu.mx" → "al****@utt.edu.mx".
     */
    public static function mask(string $email): string
    {
        $email = Str::lower(trim($email));

        if (! str_contains($email, '@')) {
            return '****';
        }

        [$local, $domain] = explode('@', $email, 2);

        return Str::substr($local, 0, 2).'****@'.$domain;
    }
}
