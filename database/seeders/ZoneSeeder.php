<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo inicial de zonas de Torreón (T-28, RF-18): los tres campus y algunas colonias.
 *
 * Los centros son APROXIMADOS (unos cientos de metros): solo sirven para dibujar la zona en el mapa, que
 * además desplaza a cada persona al azar (RF-19). Hay que verificarlos en un mapa antes de producción.
 * Necesita las universidades de CatalogSeeder.
 */
class ZoneSeeder extends Seeder
{
    public function run(): void
    {
        $timestamp = now();
        $universities = DB::table('universities')->pluck('id', 'short_name');

        $campus = [
            ['name' => 'Campus UTT', 'university' => 'UTT', 'lat' => 25.561500, 'lng' => -103.291000],
            ['name' => 'UAdeC Unidad Torreón', 'university' => 'UAdeC', 'lat' => 25.559000, 'lng' => -103.388000],
            ['name' => 'Campus ITL', 'university' => 'ITL', 'lat' => 25.533000, 'lng' => -103.438000],
        ];

        $colonias = [
            ['name' => 'Centro', 'lat' => 25.539300, 'lng' => -103.457400],
            ['name' => 'Torreón Jardín', 'lat' => 25.555000, 'lng' => -103.410000],
            ['name' => 'Campestre La Rosita', 'lat' => 25.553000, 'lng' => -103.380000],
            ['name' => 'Los Ángeles', 'lat' => 25.536000, 'lng' => -103.413000],
            ['name' => 'Navarro', 'lat' => 25.530000, 'lng' => -103.440000],
            ['name' => 'San Isidro', 'lat' => 25.560000, 'lng' => -103.430000],
        ];

        $rows = [
            ...array_map(fn (array $zone): array => [
                'name' => $zone['name'],
                'type' => 'campus',
                'university_id' => $universities[$zone['university']] ?? null,
                'city' => 'Torreón',
                'center_lat' => $zone['lat'],
                'center_lng' => $zone['lng'],
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ], $campus),
            ...array_map(fn (array $zone): array => [
                'name' => $zone['name'],
                'type' => 'colonia',
                'university_id' => null,
                'city' => 'Torreón',
                'center_lat' => $zone['lat'],
                'center_lng' => $zone['lng'],
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ], $colonias),
        ];

        DB::table('zones')->upsert($rows, ['name', 'city'], ['type', 'university_id', 'center_lat', 'center_lng', 'updated_at']);
    }
}
