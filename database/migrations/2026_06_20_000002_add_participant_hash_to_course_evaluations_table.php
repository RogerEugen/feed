<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_evaluations', function (Blueprint $table) {
            $table->string('participant_hash', 64)
                ->nullable()
                ->after('anonymous_token_hash')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('course_evaluations', function (Blueprint $table) {
            $table->dropIndex(['participant_hash']);
            $table->dropColumn('participant_hash');
        });
    }
};
