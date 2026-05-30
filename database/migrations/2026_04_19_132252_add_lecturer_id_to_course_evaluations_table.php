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
            $table->unsignedBigInteger('lecturer_id')->nullable()->after('faculty_id');
            $table->string('lecturer_name', 150)->nullable()->after('lecturer_id');
            $table->string('subject_name', 150)->nullable()->after('course_code');
        });
    }

    /**
     * Reverse the migrations.
     */


    public function down(): void
    {
        Schema::table('course_evaluations', function (Blueprint $table) {
            $table->dropColumn(['lecturer_id', 'lecturer_name', 'subject_name']);
        });
    }
};
