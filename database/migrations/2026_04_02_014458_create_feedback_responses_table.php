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
        Schema::create('feedback_responses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('feedback_id')
                  ->constrained('feedbacks')
                  ->onDelete('cascade');
            // NO user_id — only role stored for anonymity
            $table->enum('responder_role', ['hod', 'dean', 'rector', 'admin']);
            // for routing context only — not linked to users table
            $table->unsignedBigInteger('responder_department_id')->nullable();
            // AES-256 encrypted response content
            $table->longText('encrypted_response');
            $table->string('encryption_iv', 255);
            // marks this record as an escalation note vs normal response
            $table->boolean('is_escalation_note')->default(false);
            $table->timestamp('responded_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feedback_responses');
    }
};
