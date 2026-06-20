<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_read_states', function (Blueprint $table) {
            $table->id();
            $table->string('room', 100);
            $table->string('actor_role', 20);
            $table->unsignedBigInteger('actor_id');
            $table->unsignedBigInteger('last_read_message_id')->default(0);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['room', 'actor_role', 'actor_id'], 'communication_reader_unique');
            $table->index(['actor_role', 'actor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_read_states');
    }
};
