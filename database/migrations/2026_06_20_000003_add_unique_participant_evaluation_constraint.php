<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_evaluations', function (Blueprint $table) {
            $table->unique(
                ['participant_hash', 'course_code', 'lecturer_id', 'window_id'],
                'course_evaluations_participant_course_lecturer_window_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('course_evaluations', function (Blueprint $table) {
            $table->dropUnique('course_evaluations_participant_course_lecturer_window_unique');
        });
    }
};
