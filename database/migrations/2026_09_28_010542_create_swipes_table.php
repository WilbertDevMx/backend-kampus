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
        Schema::create('swipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('swiper_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('swiped_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('liked');
            $table->unique(['swiper_id', 'swiped_id']);
            $table->index(['swiped_id', 'liked']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('swipes');
    }
};
