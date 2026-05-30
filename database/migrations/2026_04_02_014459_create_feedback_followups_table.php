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
        Schema::create('feedback_followups', function (Blueprint $table) {
            $table->id();

            $table->foreignId('feedback_id')
                  ->constrained('feedbacks')
                  ->onDelete('cascade');
            // tracking code used by sender to follow up anonymously
            $table->string('tracking_code', 20)->index();
            $table->longText('encrypted_message');
            $table->string('encryption_iv', 255);
            $table->enum('direction', [
                'sender_to_recipient',
                'recipient_to_sender',
            ]);
            $table->timestamp('sent_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feedback_followups');
    }
};
