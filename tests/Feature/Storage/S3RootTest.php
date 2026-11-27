<?php

namespace Tests\Feature\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AWS_ROOT pone los archivos de un entorno en su propia carpeta del bucket. Las URLs firmadas se calculan
 * sin red, así que la prueba no necesita llaves reales ni conexión a S3.
 */
class S3RootTest extends TestCase
{
    private function s3Disk(string $root): Filesystem
    {
        return Storage::build([
            ...config('filesystems.disks.s3'),
            'key' => 'AKIAPRUEBA',
            'secret' => 'secreto-de-prueba',
            'region' => 'us-east-2',
            'bucket' => 'kampus-prueba',
            'root' => $root,
        ]);
    }

    public function test_config_reads_aws_root(): void
    {
        $this->assertArrayHasKey('root', config('filesystems.disks.s3'));
    }

    public function test_signed_url_includes_the_root_folder(): void
    {
        $url = $this->s3Disk('produccion')->temporaryUrl('profiles/1/foto.jpg', now()->addMinutes(15));

        $this->assertStringContainsString('/produccion/profiles/1/foto.jpg?', $url);
    }

    public function test_empty_root_keeps_files_at_the_bucket_root(): void
    {
        $url = $this->s3Disk('')->temporaryUrl('profiles/1/foto.jpg', now()->addMinutes(15));

        $this->assertStringContainsString('.amazonaws.com/profiles/1/foto.jpg?', $url);
        $this->assertStringNotContainsString('produccion', $url);
    }
}
