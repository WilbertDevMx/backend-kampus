<?php

namespace App\Support\Images;

use GdImage;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * Prepara una imagen que sube un usuario antes de guardarla (perfil, grupos y chat).
 *
 * 1. Revisa el formato y las dimensiones **antes** de abrirla: GD ocupa unos 4 bytes por píxel, y un PNG de
 *    200 KB puede declarar 9000 × 9000 píxeles y agotar la memoria de PHP (error 500).
 * 2. La endereza según la orientación del EXIF: el celular guarda la foto "de lado" y solo anota cómo girarla.
 * 3. La reduce a {@see self::MAX_SIDE} px por el lado largo, para que no se guarden ni se descarguen fotos de
 *    varios megas.
 * 4. La re-codifica, lo que quita todos los metadatos (EXIF, GPS, XMP, IPTC). Las fotos del celular traen la
 *    coordenada GPS exacta, y guardarlas tal cual rompería la privacidad de ubicación (RNF-11).
 *
 * Los mensajes de las excepciones se le muestran al usuario tal cual.
 */
final class ImageSanitizer
{
    /** Megapíxeles máximos que se aceptan: una foto normal de celular tiene 12. */
    public const MAX_PIXELS = 20_000_000;

    /** Lado largo máximo de la imagen guardada, en píxeles. */
    public const MAX_SIDE = 2048;

    private const QUALITY = 82;

    /** Memoria que necesita PHP para abrir una imagen del tamaño máximo (256 MB). */
    private const MEMORY_BYTES = 256 * 1024 * 1024;

    private const FORMATS = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    /**
     * Devuelve los bytes de la imagen lista para guardar, en el mismo formato que la original.
     *
     * @throws InvalidArgumentException si no es una imagen JPEG, PNG o WebP válida, o si es demasiado grande.
     */
    public function sanitize(UploadedFile|string $image): string
    {
        $bytes = $image instanceof UploadedFile ? (string) file_get_contents($image->getRealPath()) : $image;
        $info = @getimagesizefromstring($bytes);

        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            throw new InvalidArgumentException('El archivo no es una imagen válida.');
        }

        if (! in_array($info[2], self::FORMATS, true)) {
            throw new InvalidArgumentException('Formato de imagen no permitido.');
        }

        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            throw new InvalidArgumentException('La imagen es demasiado grande. Usa una de menos de 20 megapíxeles.');
        }

        $this->ensureMemory();
        $gd = @imagecreatefromstring($bytes);

        if ($gd === false) {
            throw new InvalidArgumentException('El archivo no es una imagen válida.');
        }

        if ($info[2] === IMAGETYPE_JPEG) {
            $gd = $this->applyOrientation($gd, $this->exifOrientation($bytes));
        }

        $gd = $this->downscale($gd);

        ob_start();

        try {
            match ($info[2]) {
                IMAGETYPE_JPEG => imagejpeg($gd, null, self::QUALITY),
                IMAGETYPE_PNG => $this->encodePng($gd),
                IMAGETYPE_WEBP => imagewebp($gd, null, self::QUALITY),
            };

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($gd);
        }
    }

    /**
     * Abrir una foto de 20 MP ocupa unos 80 MB, más la copia reducida. Producción ya corre con 256 MB
     * (`docker/php/production.ini`), pero el PHP de una instalación local suele traer 128 MB y no alcanza.
     */
    private function ensureMemory(): void
    {
        $limit = ini_get('memory_limit');

        if ($limit === false || $limit === '-1') {
            return;
        }

        $bytes = (int) $limit * match (strtoupper(substr($limit, -1))) {
            'G' => 1024 ** 3,
            'M' => 1024 ** 2,
            'K' => 1024,
            default => 1,
        };

        if ($bytes < self::MEMORY_BYTES) {
            ini_set('memory_limit', (string) self::MEMORY_BYTES);
        }
    }

    private function downscale(GdImage $gd): GdImage
    {
        $width = imagesx($gd);
        $height = imagesy($gd);

        if (max($width, $height) <= self::MAX_SIDE) {
            return $gd;
        }

        $ratio = self::MAX_SIDE / max($width, $height);
        $resized = imagecreatetruecolor(max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio)));
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $gd, 0, 0, 0, 0, imagesx($resized), imagesy($resized), $width, $height);
        imagedestroy($gd);

        return $resized;
    }

    /**
     * Valor de la etiqueta Orientation del EXIF (1 a 8), o 1 si no la tiene. Se lee sin la extensión `exif`,
     * que no viene en todas las instalaciones de PHP: basta recorrer el segmento APP1 del JPEG.
     */
    private function exifOrientation(string $jpeg): int
    {
        $offset = 2;
        $length = strlen($jpeg);

        while ($offset + 4 <= $length && $jpeg[$offset] === "\xFF") {
            $marker = ord($jpeg[$offset + 1]);
            $size = unpack('n', substr($jpeg, $offset + 2, 2))[1];

            if ($marker === 0xE1 && substr($jpeg, $offset + 4, 6) === "Exif\0\0") {
                return $this->orientationFromTiff(substr($jpeg, $offset + 10, $size - 8));
            }

            // SOS: ya empiezan los datos de la imagen y no hubo EXIF.
            if ($marker === 0xDA) {
                break;
            }

            $offset += 2 + $size;
        }

        return 1;
    }

    private function orientationFromTiff(string $tiff): int
    {
        if (strlen($tiff) < 8) {
            return 1;
        }

        $format = match (substr($tiff, 0, 2)) {
            'II' => ['short' => 'v', 'long' => 'V'],
            'MM' => ['short' => 'n', 'long' => 'N'],
            default => null,
        };

        if ($format === null) {
            return 1;
        }

        $ifd = unpack($format['long'], substr($tiff, 4, 4))[1];

        if ($ifd + 2 > strlen($tiff)) {
            return 1;
        }

        $entries = unpack($format['short'], substr($tiff, $ifd, 2))[1];

        for ($i = 0; $i < $entries; $i++) {
            $entry = $ifd + 2 + $i * 12;

            if ($entry + 12 > strlen($tiff)) {
                break;
            }

            if (unpack($format['short'], substr($tiff, $entry, 2))[1] === 0x0112) {
                $value = unpack($format['short'], substr($tiff, $entry + 8, 2))[1];

                return $value >= 1 && $value <= 8 ? $value : 1;
            }
        }

        return 1;
    }

    /**
     * Gira (y en 2, 4, 5 y 7 también refleja) la imagen para que quede como la vio quien tomó la foto.
     * `imagerotate()` gira en sentido contrario a las manecillas: 270 equivale a 90° en el sentido de ellas.
     */
    private function applyOrientation(GdImage $gd, int $orientation): GdImage
    {
        $angle = match ($orientation) {
            3, 4 => 180,
            5, 6 => 270,
            7, 8 => 90,
            default => 0,
        };

        if ($angle !== 0) {
            $rotated = imagerotate($gd, $angle, 0);

            if ($rotated !== false) {
                imagedestroy($gd);
                $gd = $rotated;
            }
        }

        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($gd, IMG_FLIP_HORIZONTAL);
        }

        return $gd;
    }

    private function encodePng(GdImage $gd): bool
    {
        imagesavealpha($gd, true);

        return imagepng($gd);
    }
}
