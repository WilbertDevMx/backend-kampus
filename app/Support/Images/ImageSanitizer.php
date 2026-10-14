<?php

namespace App\Support\Images;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * Re-codifica una imagen para quitarle todos sus metadatos (EXIF, GPS, XMP, IPTC).
 *
 * Las fotos tomadas con el celular suelen traer la coordenada GPS exacta en el EXIF.
 * Guardarlas tal cual rompería la privacidad de ubicación (RNF-11), así que toda foto
 * que suban los usuarios (perfil, chat, grupos) debe pasar por aquí antes de guardarse.
 */
final class ImageSanitizer
{
    private const QUALITY = 90;

    /**
     * Devuelve los bytes de la imagen limpia, en el mismo formato que la original.
     *
     * @throws InvalidArgumentException si el archivo no es una imagen JPEG, PNG o WebP válida.
     */
    public function sanitize(UploadedFile|string $image): string
    {
        $bytes = $image instanceof UploadedFile ? (string) file_get_contents($image->getRealPath()) : $image;
        $info = @getimagesizefromstring($bytes);
        $gd = $info === false ? false : @imagecreatefromstring($bytes);

        if ($info === false || $gd === false) {
            throw new InvalidArgumentException('El archivo no es una imagen válida.');
        }

        ob_start();

        try {
            match ($info['mime']) {
                'image/jpeg' => imagejpeg($gd, null, self::QUALITY),
                'image/png' => $this->encodePng($gd),
                'image/webp' => imagewebp($gd, null, self::QUALITY),
                default => throw new InvalidArgumentException('Formato de imagen no permitido.'),
            };

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($gd);
        }
    }

    private function encodePng(\GdImage $gd): bool
    {
        imagesavealpha($gd, true);

        return imagepng($gd);
    }
}
