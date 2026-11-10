<?php

namespace App\Support\Security;

/**
 * Nombre legible del dispositivo de una sesión a partir de su User-Agent (T-46), por ejemplo
 * "Chrome en Windows" o "Safari en iOS". Solo sirve para que el alumno reconozca sus sesiones: no se usa
 * para ninguna decisión de seguridad, porque el User-Agent lo manda el cliente.
 */
final class DeviceLabel
{
    /** Navegador o cliente, en orden: Edge y Opera también dicen "Chrome", y Chrome también dice "Safari". */
    private const CLIENTS = [
        'Edg/' => 'Edge',
        'OPR/' => 'Opera',
        'Firefox/' => 'Firefox',
        'SamsungBrowser/' => 'Samsung Internet',
        'Chrome/' => 'Chrome',
        'CriOS/' => 'Chrome',
        'Version/' => 'Safari',
        'Expo/' => 'App de Kampus',
        'okhttp/' => 'App de Kampus',
        'CFNetwork/' => 'App de Kampus',
    ];

    /** Sistema operativo, en orden: Android también dice "Linux" y iOS también dice "Mac OS X". */
    private const SYSTEMS = [
        'Android' => 'Android',
        'iPhone' => 'iOS',
        'iPad' => 'iPadOS',
        'Windows' => 'Windows',
        'Mac OS X' => 'macOS',
        'Darwin' => 'iOS',
        'CrOS' => 'ChromeOS',
        'Linux' => 'Linux',
    ];

    public static function fromUserAgent(?string $userAgent): string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return 'Dispositivo desconocido';
        }

        $client = self::firstMatch(self::CLIENTS, $userAgent);
        $system = self::firstMatch(self::SYSTEMS, $userAgent);

        return match (true) {
            $client !== null && $system !== null => "{$client} en {$system}",
            $client !== null => $client,
            $system !== null => "Navegador en {$system}",
            default => 'Dispositivo desconocido',
        };
    }

    /**
     * @param  array<string, string>  $patterns
     */
    private static function firstMatch(array $patterns, string $userAgent): ?string
    {
        foreach ($patterns as $needle => $label) {
            if (str_contains($userAgent, $needle)) {
                return $label;
            }
        }

        return null;
    }
}
