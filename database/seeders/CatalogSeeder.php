<?php

namespace Database\Seeders;

use App\Models\Interest;
use Illuminate\Database\Seeder;

class CatalogSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach ([
            'Arte',
            'Cine',
            'Deportes',
            'Fotografía',
            'Gastronomía',
            'Lectura',
            'Música',
            'Naturaleza',
            'Tecnología',
            'Videojuegos',
            'Viajes',
        ] as $name) {
            Interest::query()->firstOrCreate(['name' => $name]);
        }
    }
}
