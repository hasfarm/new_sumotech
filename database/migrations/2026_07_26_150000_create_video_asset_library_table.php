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
        Schema::create('video_asset_library', function (Blueprint $table) {
            $table->id();
            $table->string('media_type', 16); // image|video
            $table->string('origin_source', 32); // pexels|pixabay|wikimedia|smithsonian|ai_image|ai_video
            $table->string('external_id')->nullable();
            $table->string('r2_path');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('license_label')->nullable();
            $table->text('description');
            $table->json('keywords')->nullable();
            $table->text('culture_context')->nullable();
            $table->float('score_final')->nullable();
            $table->foreignId('source_book_id')->nullable()->constrained('audio_books')->nullOnDelete();
            $table->unsignedInteger('usage_count')->default(1);
            $table->timestamps();

            $table->index(['media_type', 'origin_source']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_asset_library');
    }
};
