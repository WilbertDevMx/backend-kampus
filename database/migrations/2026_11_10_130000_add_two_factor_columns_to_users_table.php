<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Autenticación en dos pasos con una app de autenticación (T-47).
 *
 * - `two_factor_secret`: el secreto TOTP, cifrado con APP_KEY.
 * - `two_factor_recovery_codes`: los hash de los códigos de respaldo que quedan, también cifrados.
 * - `two_factor_confirmed_at`: cuándo se confirmó con el primer código. Mientras sea nulo, la verificación en
 *   dos pasos no está activa aunque haya un secreto (el usuario todavía no termina de configurarla).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
    }
};
