<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidencia de cuándo aceptó cada estudiante el aviso de privacidad y las normas de comunidad (T-23, RF-04).
 * Quedan nulas para el staff y para las cuentas creadas antes de que la app pidiera aceptarlas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('privacy_accepted_at')->nullable();
            $table->timestamp('rules_accepted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['privacy_accepted_at', 'rules_accepted_at']);
        });
    }
};
