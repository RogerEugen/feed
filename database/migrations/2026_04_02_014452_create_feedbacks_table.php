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
        Schema::create('feedbacks', function (Blueprint $table) {
            $table->id();

            // ── ANONYMITY — NO user_id ever stored here ──────────
            // unique tracking code given to sender e.g. FB-2024-XKQT
            $table->string('tracking_code', 20)->unique();
            // sha256 of the plain anonymous token from auth service
            // consumed once then token is marked used
            $table->string('anonymous_token_hash', 64)->unique();
            // role copied from token — student | lecturer
            $table->string('sender_role', 20);

            // ── ROUTING — from token context ──────────────────────
            // dept of the sender — from token only, no user link
            $table->unsignedBigInteger('sender_department_id')->nullable();
            $table->foreignId('category_id')
                  ->constrained('feedback_categories')
                  ->onDelete('restrict');
            // who receives this feedback
            $table->enum('routed_to', ['hod', 'dean', 'rector', 'admin']);
            // which dept receives it
            $table->unsignedBigInteger('recipient_department_id')->nullable();
            // which faculty receives it
            $table->unsignedBigInteger('recipient_faculty_id')->nullable();

            // ── CONTENT — encrypted ───────────────────────────────
            $table->longText('encrypted_content');
            $table->string('encryption_iv', 255);
            $table->boolean('has_attachment')->default(false);

            // ── STATUS & PRIORITY ─────────────────────────────────
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])
                  ->default('medium');
            $table->enum('status', [
                'submitted',
                'under_review',
                'escalated',
                'resolved',
                'closed',
            ])->default('submitted');

            // ── ESCALATION ────────────────────────────────────────
            $table->boolean('is_escalated')->default(false);
            $table->enum('escalated_to', ['dean', 'rector', 'admin'])->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('submitted_at')->useCurrent();

            $table->index('tracking_code');
            $table->index('status');
            $table->index('routed_to');
            $table->index('sender_department_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feedbacks');
    }
};
