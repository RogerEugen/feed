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
        Schema::table('course_evaluations', function (Blueprint $table) {
            // Drop the old single-column unique constraint
            $table->dropUnique(['anonymous_token_hash']);

            // Add composite unique — one student per course per window
            // This allows the SAME student to evaluate DIFFERENT courses
            $table->unique(
                ['anonymous_token_hash', 'course_code', 'window_id'],
                'unique_student_course_window'
            );
        });
    }
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('course_evaluations', function (Blueprint $table) {
            $table->dropUnique('unique_student_course_window');
            $table->unique('anonymous_token_hash');
        });
    }
};
