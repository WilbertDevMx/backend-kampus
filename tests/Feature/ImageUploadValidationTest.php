<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\University;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Qué responde la API cuando una imagen pesa de más, no se pudo subir o es enorme. Antes salía en inglés
 * ("The photo field must not be greater than 5120 kilobytes.") y una imagen de 9000 × 9000 daba error 500.
 */
class ImageUploadValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
        $this->student = User::factory()->student(University::factory()->create())->create();
        Sanctum::actingAs($this->student);
    }

    public function test_a_photo_heavier_than_five_megabytes_gets_a_spanish_message(): void
    {
        $this->postJson('/api/profile/photos', ['photo' => UploadedFile::fake()->image('foto.jpg')->size(5121)])
            ->assertInvalid(['photo' => 'La imagen no puede pesar más de 5 MB.']);
    }

    public function test_a_file_that_php_dropped_for_its_size_says_so(): void
    {
        // Entre 6 y 8 MB, PHP descarta el archivo (upload_max_filesize) y Laravel lo recibe con error.
        $path = tempnam(sys_get_temp_dir(), 'foto');
        file_put_contents($path, '');
        $dropped = new UploadedFile($path, 'foto.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE, true);

        $this->postJson('/api/profile/photos', ['photo' => $dropped])
            ->assertInvalid(['photo' => 'La imagen no se pudo subir. Revisa que pese menos de 5 MB.']);
    }

    public function test_a_huge_image_is_rejected_instead_of_crashing(): void
    {
        $ihdr = 'IHDR'.pack('NNCCCCC', 9000, 9000, 8, 2, 0, 0, 0);
        $bomb = "\x89PNG\r\n\x1A\n".pack('N', 13).$ihdr.pack('N', crc32($ihdr));

        $this->postJson('/api/profile/photos', ['photo' => UploadedFile::fake()->createWithContent('bomba.png', $bomb)])
            ->assertInvalid(['photo']);

        $this->assertSame([], Storage::disk('s3')->allFiles());
    }

    public function test_a_large_photo_is_stored_reduced(): void
    {
        $image = imagecreatetruecolor(4032, 3024);
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);
        unset($image);

        $this->postJson('/api/profile/photos', ['photo' => UploadedFile::fake()->createWithContent('celular.jpg', $jpeg)])
            ->assertCreated();

        $stored = getimagesizefromstring(Storage::disk('s3')->get(Storage::disk('s3')->allFiles()[0]));
        $this->assertSame([2048, 1536], [$stored[0], $stored[1]]);
    }

    public function test_group_images_use_the_same_messages(): void
    {
        $group = Group::factory()->createdBy($this->student)->create();

        $this->postJson("/api/groups/{$group->id}/image", ['image' => UploadedFile::fake()->image('logo.jpg')->size(5121)])
            ->assertInvalid(['image' => 'La imagen no puede pesar más de 5 MB.']);
        $this->postJson("/api/groups/{$group->id}/image", ['image' => UploadedFile::fake()->create('logo.gif', 10, 'image/gif')])
            ->assertInvalid(['image' => 'El archivo debe ser una imagen JPG, PNG o WebP.']);
    }
}
