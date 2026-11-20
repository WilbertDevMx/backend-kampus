<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El correo institucional de la UTT es @uttcampus.edu.mx, no @utt.edu.mx. `CatalogSeeder` ya trae el dominio
 * correcto; esta migración corrige las bases que se sembraron antes, para que el registro acepte los correos
 * reales de la UTT sin tener que volver a correr el seeder.
 */
return new class extends Migration
{
    private const OLD = 'utt.edu.mx';

    private const NEW = 'uttcampus.edu.mx';

    public function up(): void
    {
        $this->replaceDomain(self::OLD, self::NEW);
    }

    public function down(): void
    {
        $this->replaceDomain(self::NEW, self::OLD);
    }

    private function replaceDomain(string $from, string $to): void
    {
        DB::table('universities')
            ->where('short_name', 'UTT')
            ->get(['id', 'email_domains'])
            ->each(function (object $university) use ($from, $to): void {
                $domains = json_decode((string) $university->email_domains, true, flags: JSON_THROW_ON_ERROR);

                if (! is_array($domains) || ! in_array($from, $domains, true)) {
                    return;
                }

                $domains = array_values(array_unique(array_map(fn (string $domain): string => $domain === $from ? $to : $domain, $domains)));

                DB::table('universities')->where('id', $university->id)->update([
                    'email_domains' => json_encode($domains, JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);
            });
    }
};
