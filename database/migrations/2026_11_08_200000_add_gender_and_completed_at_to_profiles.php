<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perfil obligatorio (T-49): el género y la fecha en que el perfil quedó completo. Mientras
 * `completed_at` sea nulo, el estudiante no aparece en el descubrimiento ni puede usarlo.
 * Los perfiles que ya existían quedan incompletos y la app pide terminarlos al entrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->string('gender', 20)->nullable()->after('birthdate');
            $table->timestamp('completed_at')->nullable()->after('discovery_filters')->index();
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropIndex(['completed_at']);
            $table->dropColumn(['gender', 'completed_at']);
        });
    }
};
