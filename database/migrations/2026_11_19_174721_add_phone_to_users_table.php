<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Celular para recuperar la contraseña por SMS (T-45, RF-02). Solo se guarda después de verificarlo con
     * un código, así que `phone` y `phone_verified_at` siempre van juntos.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Formato E.164 de México: +52 y 10 dígitos. Único: un número recupera una sola cuenta.
            $table->string('phone', 16)->nullable()->unique();
            $table->timestamp('phone_verified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->dropColumn(['phone', 'phone_verified_at']);
        });
    }
};
