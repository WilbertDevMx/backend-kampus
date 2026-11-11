<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Datos de demostración (T-34)
    |--------------------------------------------------------------------------
    |
    | `DemoSeeder` crea estudiantes ficticios para demostrar el descubrimiento, la vista Cerca y los
    | matches. Nunca corre con APP_ENV=production, salvo en el entorno de pruebas, que lo habilita
    | con DEMO_DATA_ENABLED=true. Si no se define DEMO_PASSWORD, cada corrida genera una contraseña
    | aleatoria y la muestra en la consola.
    |
    */

    'demo' => [
        'enabled' => (bool) env('DEMO_DATA_ENABLED', false),
        'password' => env('DEMO_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Documentación interactiva de la API (T-33)
    |--------------------------------------------------------------------------
    |
    | Scramble publica el contrato OpenAPI en /docs/api y /docs/api.json. Fuera de producción está abierto;
    | en producción solo se ve con API_DOCS_ENABLED=true (por ejemplo, en el entorno de pruebas).
    |
    */

    'api_docs_enabled' => (bool) env('API_DOCS_ENABLED', env('APP_ENV', 'production') !== 'production'),

];
