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
        Schema::create('course_evaluations', function (Blueprint $table) {
            $table->id();

            // ── ANONYMITY — NO student identity ──────────────────
            // unique per student per course — prevents double submit
            $table->string('anonymous_token_hash', 64)->unique();
            $table->foreignId('window_id')
                  ->constrained('evaluation_windows')
                  ->onDelete('restrict');

            // ── CROSS-SERVICE REFERENCES (from auth service) ──────
            // program code from auth service programs table
            $table->string('course_code', 20);
            // dept id from auth service departments table
            $table->unsignedBigInteger('department_id');
            // faculty id from auth service faculties table
            $table->unsignedBigInteger('faculty_id');
            $table->string('academic_year', 9);
            $table->tinyInteger('semester');

            // ── RATINGS 1-5 ───────────────────────────────────────
            $table->tinyInteger('teaching_quality');       // 1-5
            $table->tinyInteger('course_content');         // 1-5
            $table->tinyInteger('assessment_fairness');    // 1-5
            $table->tinyInteger('resources_available');    // 1-5
            $table->tinyInteger('lecturer_accessibility'); // 1-5
            $table->tinyInteger('overall_rating');         // 1-5

            // encrypted optional comments
            $table->longText('encrypted_comments')->nullable();
            $table->string('encryption_iv', 255)->nullable();
            $table->timestamp('submitted_at')->useCurrent();

            $table->index(['course_code', 'department_id']);
            $table->index('window_id');
        });
    }


    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('course_evaluations');
    }
};
