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
        Schema::create('audiobook_video_pipelines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audio_book_id')->unique()->constrained('audio_books')->cascadeOnDelete();
            $table->string('source_version_id')->nullable();
            $table->string('status')->default('idle');
            $table->unsignedInteger('total_batches')->nullable();
            $table->unsignedInteger('processed_batches')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audiobook_video_pipelines');
    }
};
