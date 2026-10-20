<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('university_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('suspended_at')->nullable();
            $table->string('role', 20)->default('student')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('university_id');
            $table->dropIndex(['role']);
            $table->dropColumn(['suspended_at', 'role']);
        });
    }
};
