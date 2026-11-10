<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CatalogSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $timestamp = now();

        DB::table('universities')->upsert([
            [
                'name' => 'Universidad Tecnológica de Torreón',
                'short_name' => 'UTT',
                'email_domains' => json_encode(['utt.edu.mx'], JSON_THROW_ON_ERROR),
                'is_active' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            [
                'name' => 'Universidad Autónoma de Coahuila',
                'short_name' => 'UAdeC',
                'email_domains' => json_encode(['uadec.edu.mx'], JSON_THROW_ON_ERROR),
                'is_active' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            [
                'name' => 'Instituto Tecnológico de La Laguna',
                'short_name' => 'ITL',
                'email_domains' => json_encode(['correo.itlalaguna.edu.mx'], JSON_THROW_ON_ERROR),
                'is_active' => true,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
        ], ['short_name'], ['name', 'email_domains', 'is_active', 'updated_at']);

        DB::table('interests')->upsert([
            ['name' => 'Fútbol', 'category' => 'deportes', 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['name' => 'Baloncesto', 'category' => 'deportes', 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['name' => 'Fotografía', 'category' => 'cultura', 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['name' => 'Cine', 'category' => 'cultura', 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['name' => 'Música', 'category' => 'entretenimiento', 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['name' => 'Anime', 'category' => 'entretenimiento', 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['name' => 'Videojuegos', 'category' => 'entretenimiento', 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['name' => 'Programación', 'category' => 'academico', 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['name' => 'Lectura', 'category' => 'academico', 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['name' => 'Viajes', 'category' => 'social', 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['name' => 'Naturaleza', 'category' => 'social', 'created_at' => $timestamp, 'updated_at' => $timestamp],
        ], ['name'], ['category', 'updated_at']);
    }
}
