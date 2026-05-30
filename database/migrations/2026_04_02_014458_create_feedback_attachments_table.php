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
        Schema::create('feedback_attachments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('feedback_id')
                  ->constrained('feedbacks')
                  ->onDelete('cascade');
            // UUID-renamed — strips original filename for anonymity
            $table->string('stored_filename', 255);
            $table->string('mime_type', 100);
            $table->integer('file_size_kb');
            $table->boolean('is_encrypted')->default(true);
            $table->timestamp('uploaded_at')->useCurrent();
        });
    }
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feedback_attachments');
    }
};
