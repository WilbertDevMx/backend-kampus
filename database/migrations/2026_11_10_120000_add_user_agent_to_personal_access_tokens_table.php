<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gestión de sesiones (T-46): desde qué navegador o app se inició cada sesión, para mostrarla en la lista.
 * No se guarda la IP: el criterio de la tarea no la pide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('user_agent', 255)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn('user_agent');
        });
    }
};
