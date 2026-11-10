<?php

namespace Database\Seeders\Demo;

/**
 * Fotos de perfil de los usuarios de demostración (T-34): se dibujan aquí con GD, así que no hay fotos de
 * personas reales ni licencias de terceros. Cada una es una silueta sobre un fondo de color.
 */
final class DemoAvatar
{
    private const SIZE = 600;

    /** Fondos: [color de arriba, color de abajo]. */
    private const BACKGROUNDS = [
        [[46, 64, 120], [99, 132, 214]],
        [[120, 46, 86], [214, 99, 150]],
        [[30, 100, 90], [80, 190, 160]],
        [[140, 80, 30], [235, 160, 80]],
        [[70, 50, 130], [150, 120, 220]],
        [[40, 90, 130], [110, 180, 220]],
        [[110, 40, 40], [210, 100, 90]],
        [[60, 100, 50], [140, 190, 100]],
    ];

    /**
     * PNG de 600 × 600. Con la misma `$seed` siempre sale la misma imagen.
     */
    public static function png(int $seed): string
    {
        $image = imagecreatetruecolor(self::SIZE, self::SIZE);
        [$top, $bottom] = self::BACKGROUNDS[$seed % count(self::BACKGROUNDS)];

        // Degradado vertical del fondo.
        for ($y = 0; $y < self::SIZE; $y++) {
            $t = $y / (self::SIZE - 1);
            $color = imagecolorallocate(
                $image,
                (int) round($top[0] + ($bottom[0] - $top[0]) * $t),
                (int) round($top[1] + ($bottom[1] - $top[1]) * $t),
                (int) round($top[2] + ($bottom[2] - $top[2]) * $t),
            );
            imageline($image, 0, $y, self::SIZE - 1, $y, $color);
        }

        // Un círculo decorativo que cambia de lugar según la semilla.
        $accent = imagecolorallocatealpha($image, 255, 255, 255, 105);
        $cx = 80 + ($seed * 97) % 440;
        $cy = 60 + ($seed * 53) % 200;
        imagefilledellipse($image, $cx, $cy, 180, 180, $accent);

        // Silueta: cabeza y hombros.
        $shade = 225 + ($seed % 4) * 8;
        $person = imagecolorallocate($image, $shade, $shade, $shade);
        imagefilledellipse($image, 300, 250, 220, 240, $person);
        imagefilledellipse($image, 300, 640, 460, 420, $person);

        ob_start();
        imagepng($image, null, 6);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return $png;
    }
}
