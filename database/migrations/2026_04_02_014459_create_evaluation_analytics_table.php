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
        Schema::create('evaluation_analytics', function (Blueprint $table) {
            $table->id();

            $table->foreignId('window_id')
                  ->constrained('evaluation_windows')
                  ->onDelete('cascade');
            $table->string('course_code', 20);
            $table->unsignedBigInteger('department_id');
            $table->unsignedBigInteger('faculty_id');

            // minimum 5 responses before results are shown
            $table->integer('total_responses')->default(0);

            // aggregated averages — shown to lecturer
            $table->decimal('avg_teaching_quality', 3, 2)->default(0);
            $table->decimal('avg_course_content', 3, 2)->default(0);
            $table->decimal('avg_assessment_fairness', 3, 2)->default(0);
            $table->decimal('avg_resources', 3, 2)->default(0);
            $table->decimal('avg_accessibility', 3, 2)->default(0);
            $table->decimal('avg_overall', 3, 2)->default(0);

            // comparative averages — shown to HOD and Dean
            $table->decimal('dept_avg_overall', 3, 2)->default(0);
            $table->decimal('faculty_avg_overall', 3, 2)->default(0);

            // hidden until minimum response threshold met
            $table->boolean('results_visible')->default(false);
            $table->timestamp('computed_at')->nullable();

            $table->unique(['window_id', 'course_code', 'department_id']);
            $table->index('department_id');
            $table->index('faculty_id');
        });
    }
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('evaluation_analytics');
    }
};
