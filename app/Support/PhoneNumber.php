<?php

namespace App\Support;

/**
 * Números de celular de México (T-45). Se guardan en formato E.164: `+52` y 10 dígitos.
 */
final class PhoneNumber
{
    /**
     * Acepta "871 123 4567", "(871) 123-4567", "+52 871 123 4567" o "+52 1 871 123 4567" (el "1" que antes
     * llevaban los celulares). Devuelve `null` si no es un número de México de 10 dígitos.
     */
    public static function normalize(string $input): ?string
    {
        $digits = preg_replace('/[\s\-().]/', '', trim($input)) ?? '';

        if (preg_match('/^(?:\+?52)?1?(\d{10})$/', $digits, $matches) !== 1) {
            return null;
        }

        return '+52'.$matches[1];
    }

    /**
     * Para logs y mensajes: "+528711234567" → "******4567".
     */
    public static function mask(string $phone): string
    {
        return str_repeat('*', 6).substr($phone, -4);
    }
}
