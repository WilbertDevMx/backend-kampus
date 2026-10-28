<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moderation_actions', function (Blueprint $table) {
            $table->id();
            // PostgreSQL no indexa las llaves foráneas por sí solo; la bitácora se consulta por moderador.
            $table->foreignId('actor_id')->index()->constrained('users')->restrictOnDelete();
            $table->string('action', 30)->index();

            // Objetivo polimórfico (usuario, grupo, reporte...): sin FK a propósito.
            $table->string('target_type', 20);
            $table->unsignedBigInteger('target_id');

            $table->foreignId('report_id')->nullable()->constrained('reports')->nullOnDelete();
            $table->foreignId('university_id')->nullable()->constrained('universities')->nullOnDelete();
            // Toda acción del staff lleva motivo (RF-15): obligatorio también en la base.
            $table->text('reason');

            // Bitácora: no se edita, por eso solo created_at (sin updated_at).
            $table->timestamp('created_at')->useCurrent();

            $table->index(['target_type', 'target_id']);
            $table->index(['university_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moderation_actions');
    }
};
