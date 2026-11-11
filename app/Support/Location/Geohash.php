<?php

namespace App\Support\Location;

/**
 * Cálculos con geohash para la vista "Cerca" (T-35, RF-19).
 *
 * El servidor solo conoce la celda de ~1 km que mandó el celular (T-28), nunca una coordenada exacta:
 * aquí se obtiene el centro de una celda, sus celdas vecinas y la distancia entre dos puntos.
 */
final class Geohash
{
    private const BASE32 = '0123456789bcdefghjkmnpqrstuvwxyz';

    private const EARTH_RADIUS_KM = 6371.0;

    public static function encode(float $lat, float $lng, int $precision): string
    {
        $latRange = [-90.0, 90.0];
        $lngRange = [-180.0, 180.0];
        $hash = '';
        $bits = 0;
        $bitCount = 0;
        $evenBit = true;

        while (strlen($hash) < $precision) {
            if ($evenBit) {
                $mid = ($lngRange[0] + $lngRange[1]) / 2;
                $bits <<= 1;

                if ($lng >= $mid) {
                    $bits |= 1;
                    $lngRange[0] = $mid;
                } else {
                    $lngRange[1] = $mid;
                }
            } else {
                $mid = ($latRange[0] + $latRange[1]) / 2;
                $bits <<= 1;

                if ($lat >= $mid) {
                    $bits |= 1;
                    $latRange[0] = $mid;
                } else {
                    $latRange[1] = $mid;
                }
            }

            $evenBit = ! $evenBit;

            if (++$bitCount === 5) {
                $hash .= self::BASE32[$bits];
                $bits = 0;
                $bitCount = 0;
            }
        }

        return $hash;
    }

    /**
     * Límites de la celda.
     *
     * @return array{min_lat: float, max_lat: float, min_lng: float, max_lng: float}
     */
    public static function bounds(string $hash): array
    {
        $latRange = [-90.0, 90.0];
        $lngRange = [-180.0, 180.0];
        $evenBit = true;

        foreach (str_split(strtolower($hash)) as $char) {
            $value = strpos(self::BASE32, $char);

            if ($value === false) {
                throw new \InvalidArgumentException("Geohash inválido: {$hash}");
            }

            for ($bit = 4; $bit >= 0; $bit--) {
                $isSet = (($value >> $bit) & 1) === 1;

                if ($evenBit) {
                    $mid = ($lngRange[0] + $lngRange[1]) / 2;
                    $isSet ? $lngRange[0] = $mid : $lngRange[1] = $mid;
                } else {
                    $mid = ($latRange[0] + $latRange[1]) / 2;
                    $isSet ? $latRange[0] = $mid : $latRange[1] = $mid;
                }

                $evenBit = ! $evenBit;
            }
        }

        return ['min_lat' => $latRange[0], 'max_lat' => $latRange[1], 'min_lng' => $lngRange[0], 'max_lng' => $lngRange[1]];
    }

    /**
     * Centro de la celda.
     *
     * @return array{lat: float, lng: float}
     */
    public static function center(string $hash): array
    {
        $bounds = self::bounds($hash);

        return [
            'lat' => ($bounds['min_lat'] + $bounds['max_lat']) / 2,
            'lng' => ($bounds['min_lng'] + $bounds['max_lng']) / 2,
        ];
    }

    /**
     * La celda y sus 8 vecinas, con la misma precisión. Sirve para buscar a la redonda sin que un
     * usuario en el borde de una celda quede fuera.
     *
     * @return list<string>
     */
    public static function withNeighbors(string $hash): array
    {
        $bounds = self::bounds($hash);
        $center = self::center($hash);
        $height = $bounds['max_lat'] - $bounds['min_lat'];
        $width = $bounds['max_lng'] - $bounds['min_lng'];
        $cells = [];

        foreach ([-1, 0, 1] as $dLat) {
            foreach ([-1, 0, 1] as $dLng) {
                $lat = max(-89.999999, min(89.999999, $center['lat'] + $dLat * $height));
                $lng = fmod($center['lng'] + $dLng * $width + 540.0, 360.0) - 180.0;
                $cells[] = self::encode($lat, $lng, strlen($hash));
            }
        }

        return array_values(array_unique($cells));
    }

    /**
     * Distancia en línea recta entre dos puntos (fórmula del haversine), en kilómetros.
     */
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_KM * asin(min(1.0, sqrt($a)));
    }
}
