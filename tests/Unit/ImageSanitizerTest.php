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

    public function test_emails_are_masked_for_logs(): void
    {
        $this->assertSame('al****@utt.edu.mx', MasksEmail::mask('  Alumno@UTT.edu.mx '));
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

    private function encode(string $encoder): string
    {
        $image = imagecreatetruecolor(8, 8);
        imagefill($image, 0, 0, imagecolorallocate($image, 245, 197, 24));

        ob_start();
        $encoder($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
