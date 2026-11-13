<?php

namespace Tests\Feature\Database;

use App\Models\University;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El dominio de la UTT pasó de utt.edu.mx (equivocado) a uttcampus.edu.mx.
 */
class UttEmailDomainMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_11_11_180000_update_utt_email_domain.php';

    public function test_it_fixes_databases_seeded_with_the_old_domain(): void
    {
        $utt = University::factory()->create(['short_name' => 'UTT', 'email_domains' => ['utt.edu.mx']]);
        $other = University::factory()->create(['short_name' => 'UAdeC', 'email_domains' => ['uadec.edu.mx']]);

        $this->runMigration('up');

        $this->assertSame(['uttcampus.edu.mx'], $utt->fresh()->email_domains);
        $this->assertSame(['uadec.edu.mx'], $other->fresh()->email_domains);
        $this->assertTrue(University::forEmail('ana@uttcampus.edu.mx')?->is($utt));
        $this->assertNull(University::forEmail('ana@utt.edu.mx'));

        $this->runMigration('down');
        $this->assertSame(['utt.edu.mx'], $utt->fresh()->email_domains);
    }

    public function test_the_registration_accepts_the_real_utt_email(): void
    {
        $this->seed(CatalogSeeder::class);

        $this->postJson('/api/register', [
            'name' => 'Ana López',
            'email' => 'ana.lopez@uttcampus.edu.mx',
            'password' => 'ClaveSegura2026',
            'password_confirmation' => 'ClaveSegura2026',
        ])->assertCreated();

        $this->postJson('/api/register', [
            'name' => 'Ana López',
            'email' => 'ana.lopez@utt.edu.mx',
            'password' => 'ClaveSegura2026',
            'password_confirmation' => 'ClaveSegura2026',
        ])->assertInvalid(['email']);

        $this->assertSame(1, DB::table('users')->count());
    }

    private function runMigration(string $direction): void
    {
        $migration = require base_path(self::MIGRATION);
        $migration->{$direction}();
    }
}
