<?php

namespace App\Discovery;

use App\Models\User;
use App\Support\Location\Geohash;

/**
 * Dónde aparece un estudiante en el mapa de "Cerca" y a qué distancia aproximada está (T-35, RF-19, RNF-11).
 *
 * - Posición: el centro **público** de su zona (campus o colonia) más un desplazamiento fijo de 150 a 500 m
 *   que sale de su id y de APP_KEY. Es siempre el mismo para la misma persona, así que el punto no "brinca"
 *   entre consultas ni se puede promediar. Sin zona declarada, se usa el centro de su celda de ~5 km.
 * - Distancia: entre los centros de las dos celdas de ~1 km, redondeada a kilómetros enteros.
 *
 * Nunca sale de aquí la celda del estudiante ni una coordenada suya.
 */
final readonly class NearbyPlacement
{
    /** Desplazamiento mínimo y máximo, en metros, desde el centro de la zona. */
    private const OFFSET_MIN_M = 150.0;

    private const OFFSET_MAX_M = 500.0;

    private const METERS_PER_DEGREE = 111_320.0;

    /**
     * @param  array{lat: float, lng: float}  $position
     */
    private function __construct(public array $position, public float $distanceKm) {}

    /**
     * @param  array{lat: float, lng: float}  $origin  Centro de la celda de quien consulta.
     */
    public static function for(User $user, string $cell, array $origin): self
    {
        $cellCenter = Geohash::center($cell);

        return new self(
            self::mapPosition($user, $cell),
            Geohash::distanceKm($origin['lat'], $origin['lng'], $cellCenter['lat'], $cellCenter['lng']),
        );
    }

    /**
     * @return array{lat: float, lng: float}
     */
    public static function mapPosition(User $user, string $cell): array
    {
        $zone = $user->profile?->zone;
        $base = $zone === null
            ? Geohash::center(substr($cell, 0, 5))
            : ['lat' => $zone->center_lat, 'lng' => $zone->center_lng];

        $seed = hash_hmac('sha256', 'nearby:'.$user->getKey(), (string) config('app.key'), true);
        $angle = self::fraction($seed, 0) * 2 * M_PI;
        $meters = self::OFFSET_MIN_M + self::fraction($seed, 4) * (self::OFFSET_MAX_M - self::OFFSET_MIN_M);

        return [
            'lat' => round($base['lat'] + $meters * cos($angle) / self::METERS_PER_DEGREE, 5),
            'lng' => round($base['lng'] + $meters * sin($angle) / (self::METERS_PER_DEGREE * cos(deg2rad($base['lat']))), 5),
        ];
    }

    /**
     * Kilómetros enteros; 0 significa "menos de 1 km".
     */
    public function roundedKm(): int
    {
        return $this->distanceKm < 1 ? 0 : (int) round($this->distanceKm);
    }

    public function label(): string
    {
        return $this->distanceKm < 1 ? 'Menos de 1 km' : '≈ '.$this->roundedKm().' km';
    }

    /**
     * Número entre 0 y 1 a partir de 4 bytes del hash.
     */
    private static function fraction(string $bytes, int $offset): float
    {
        return unpack('N', substr($bytes, $offset, 4))[1] / 0xFFFFFFFF;
    }
}
