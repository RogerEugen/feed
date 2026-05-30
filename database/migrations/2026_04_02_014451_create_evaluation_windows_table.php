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
        Schema::create('evaluation_windows', function (Blueprint $table) {
            $table->id();

            $table->string('title', 150);
            $table->string('academic_year', 9);  // e.g. 2024/2025
            $table->tinyInteger('semester'); 
            $table->dateTime('opens_at');
            $table->dateTime('closes_at');     // 1 or 2
            // admin opens manually even if time has come
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('evaluation_windows');
    }
};
