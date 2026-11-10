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

        // El esquema pide timestamps en todas las tablas salvo interest_profile; estas dos no los traían.
        foreach (['group_members', 'blocks'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['blocks', 'group_members'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropTimestamps();
            });
        }

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
