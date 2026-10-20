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
        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 20)->index();
            $table->foreignId('university_id')->nullable()->constrained()->nullOnDelete();
            $table->string('city', 60);
            $table->decimal('center_lat', 9, 6);
            $table->decimal('center_lng', 9, 6);
            $table->unique(['name', 'city']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zones');
    }
};
