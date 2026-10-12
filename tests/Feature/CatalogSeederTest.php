<?php

namespace Tests\Feature;

use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_seeder_can_run_repeatedly_without_duplicates(): void
    {
        $this->seed(CatalogSeeder::class);
        $this->seed(CatalogSeeder::class);

        $this->assertDatabaseCount('universities', 3);
        $this->assertDatabaseCount('interests', 3);
        $this->assertDatabaseHas('universities', [
            'short_name' => 'UTT',
            'email_domains' => '["utt.edu.mx"]',
        ]);
    }
}
