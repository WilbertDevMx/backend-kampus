<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->string('status', 20)->default('pending');
            $table->boolean('is_official')->default(false);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason', 300)->nullable();

            $table->index(['university_id', 'status']);
        });

        Schema::table('reports', function (Blueprint $table) {
            $table->foreignId('university_id')->constrained('universities')->restrictOnDelete();

            $table->index(['university_id', 'status']);
        });

        // timestamps() solo donde falten: blocks puede traer ya created_at.
        foreach (['group_members', 'blocks'] as $tableName) {
            $missing = array_values(array_filter(
                ['created_at', 'updated_at'],
                fn (string $column) => ! Schema::hasColumn($tableName, $column),
            ));

            Schema::table($tableName, function (Blueprint $table) use ($missing) {
                foreach ($missing as $column) {
                    $table->timestamp($column)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        // created_at de blocks puede ser anterior a esta migración: solo se quita updated_at.
        Schema::table('blocks', function (Blueprint $table) {
            $table->dropColumn('updated_at');
        });

        Schema::table('group_members', function (Blueprint $table) {
            $table->dropColumn(['created_at', 'updated_at']);
        });

        Schema::table('reports', function (Blueprint $table) {
            $table->dropIndex(['university_id', 'status']);
            $table->dropConstrainedForeignId('university_id');
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->dropIndex(['university_id', 'status']);
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['status', 'is_official', 'reviewed_at', 'rejection_reason']);
        });
    }
};