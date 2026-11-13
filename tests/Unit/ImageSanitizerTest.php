<?php

namespace Tests\Unit;

use App\Support\Images\ImageSanitizer;
use App\Support\Security\MasksEmail;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ImageSanitizerTest extends TestCase
{
    public function test_it_removes_exif_metadata_from_jpeg_images(): void
    {
        $original = $this->jpegWithExifSegment();
        $this->assertStringContainsString("Exif\0\0", $original, 'La imagen de prueba debe traer EXIF.');

        $clean = (new ImageSanitizer)->sanitize($original);

        $this->assertStringNotContainsString("Exif\0\0", $clean);
        $this->assertStringNotContainsString('GPSLatitude-de-prueba', $clean);
        $this->assertSame('image/jpeg', getimagesizefromstring($clean)['mime']);
    }

    public function test_it_keeps_png_and_webp_formats(): void
    {
        $sanitizer = new ImageSanitizer;

        foreach (['image/png' => 'imagepng', 'image/webp' => 'imagewebp'] as $mime => $encoder) {
            $clean = $sanitizer->sanitize($this->encode($encoder));

            $this->assertSame($mime, getimagesizefromstring($clean)['mime']);
        }
    }

    public function test_it_rejects_files_that_are_not_images(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ImageSanitizer)->sanitize('<?php echo "esto no es una imagen";');
    }

    public function test_it_rejects_huge_dimensions_before_opening_the_image(): void
    {
        // 9000 × 9000 declarados en un archivo de unos cuantos bytes. Abrirlo con GD pediría ~320 MB.
        $bomb = $this->pngHeader(9000, 9000);
        $this->assertLessThan(100, strlen($bomb));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('menos de 20 megapíxeles');

        (new ImageSanitizer)->sanitize($bomb);
    }

    public function test_it_rejects_formats_that_are_not_allowed(): void
    {
        $this->expectExceptionMessage('Formato de imagen no permitido.');

        (new ImageSanitizer)->sanitize($this->encode('imagegif'));
    }

    public function test_it_reduces_the_long_side_and_keeps_the_proportion(): void
    {
        $sanitizer = new ImageSanitizer;

        $wide = getimagesizefromstring($sanitizer->sanitize($this->encode('imagejpeg', 3000, 1500)));
        $this->assertSame([ImageSanitizer::MAX_SIDE, 1024], [$wide[0], $wide[1]]);

        $tall = getimagesizefromstring($sanitizer->sanitize($this->encode('imagepng', 1000, 4096)));
        $this->assertSame([500, ImageSanitizer::MAX_SIDE], [$tall[0], $tall[1]]);
        $this->assertSame('image/png', $tall['mime']);

        // Las chicas se quedan igual.
        $small = getimagesizefromstring($sanitizer->sanitize($this->encode('imagejpeg', 800, 600)));
        $this->assertSame([800, 600], [$small[0], $small[1]]);
    }

    public function test_it_straightens_photos_using_the_exif_orientation(): void
    {
        // El celular guarda la foto de lado (más ancha que alta) y anota en el EXIF que hay que girarla 90°.
        foreach (['II', 'MM'] as $byteOrder) {
            foreach ([6, 8] as $orientation) {
                $clean = (new ImageSanitizer)->sanitize($this->jpegWithOrientation(40, 20, $orientation, $byteOrder));
                $size = getimagesizefromstring($clean);

                $this->assertSame([20, 40], [$size[0], $size[1]], "Orientación {$orientation} ({$byteOrder})");
                $this->assertStringNotContainsString("Exif\0\0", $clean);
            }
        }

        // Orientación 6: lo que estaba en la esquina superior izquierda queda en la superior derecha.
        $marked = imagecreatetruecolor(40, 20);
        imagefilledrectangle($marked, 0, 0, 9, 9, imagecolorallocate($marked, 255, 0, 0));
        $clean = imagecreatefromstring((new ImageSanitizer)->sanitize($this->jpegWithOrientation(40, 20, 6, 'II', $marked)));
        $this->assertGreaterThan(200, (imagecolorat($clean, 15, 2) >> 16) & 0xFF);
        $this->assertLessThan(60, (imagecolorat($clean, 2, 2) >> 16) & 0xFF);
    }

    public function test_emails_are_masked_for_logs(): void
    {
        $this->assertSame('al****@uttcampus.edu.mx', MasksEmail::mask('  Alumno@UTTcampus.edu.mx '));
        $this->assertSame('****', MasksEmail::mask('sin-arroba'));
    }

    /**
     * JPEG válido con un segmento APP1 (EXIF) insertado justo después del marcador SOI.
     */
    private function jpegWithExifSegment(): string
    {
        $jpeg = $this->encode('imagejpeg');
        $payload = "Exif\0\0".'GPSLatitude-de-prueba';
        $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
    }

    /**
     * Solo la firma y el encabezado IHDR de un PNG: basta para que getimagesize() lea las dimensiones.
     */
    private function pngHeader(int $width, int $height): string
    {
        $ihdr = 'IHDR'.pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);

        return "\x89PNG\r\n\x1A\n".pack('N', 13).$ihdr.pack('N', crc32($ihdr));
    }

    /**
     * JPEG con un EXIF que solo trae la etiqueta Orientation (0x0112), en orden de bytes Intel (II) o Motorola (MM).
     */
    private function jpegWithOrientation(int $width, int $height, int $orientation, string $byteOrder, ?\GdImage $image = null): string
    {
        $image ??= imagecreatetruecolor($width, $height);
        ob_start();
        imagejpeg($image, null, 100);
        $jpeg = (string) ob_get_clean();

        [$short, $long] = $byteOrder === 'II' ? ['v', 'V'] : ['n', 'N'];
        $tiff = $byteOrder.pack($short, 42).pack($long, 8)
            .pack($short, 1)
            .pack($short, 0x0112).pack($short, 3).pack($long, 1).pack($short, $orientation).pack($short, 0)
            .pack($long, 0);
        $payload = "Exif\0\0".$tiff;
        $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
    }

    private function encode(string $encoder, int $width = 8, int $height = 8): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 245, 197, 24));

        ob_start();
        $encoder($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
