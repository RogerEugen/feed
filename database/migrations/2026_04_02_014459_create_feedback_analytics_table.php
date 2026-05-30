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
        Schema::create('feedback_analytics', function (Blueprint $table) {
            $table->id();

            // cross-service references from auth service
            $table->unsignedBigInteger('department_id');
            $table->unsignedBigInteger('faculty_id');
            $table->foreignId('category_id')
                  ->constrained('feedback_categories')
                  ->onDelete('cascade');
            // first day of month e.g. 2024-10-01
            $table->date('period_month');

            // aggregated counts — never individual data
            $table->integer('total_submitted')->default(0);
            $table->integer('total_resolved')->default(0);
            $table->integer('total_escalated')->default(0);
            $table->decimal('avg_resolution_days', 5, 2)->default(0);
            $table->integer('high_priority_count')->default(0);
            $table->timestamps();

            $table->unique(['department_id', 'category_id', 'period_month']);
            $table->index('department_id');
            $table->index('faculty_id');
        });
    }
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feedback_analytics');
    }
};
