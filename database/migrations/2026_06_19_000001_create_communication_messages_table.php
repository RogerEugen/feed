<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_messages', function (Blueprint $table) {
            $table->id();
            $table->string('room', 100)->index();
            $table->enum('sender_role', ['hod', 'dean', 'rector', 'lecturer']);
            $table->longText('encrypted_message');
            $table->string('encryption_iv', 255);
            $table->timestamp('sent_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_messages');
    }
};
