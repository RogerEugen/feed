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
        Schema::create('feedback_categories', function (Blueprint $table) {
            $table->id();

            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            // who this category routes to
            $table->enum('routes_to', ['hod', 'dean', 'rector', 'admin']);
            // which role can use this category
            $table->enum('sender_role', ['student', 'lecturer', 'any'])
                  ->default('any');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feedback_categories');
    }
};
