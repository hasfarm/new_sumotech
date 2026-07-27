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
        Schema::create('audiobook_video_scenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audio_book_id')->constrained('audio_books')->cascadeOnDelete();
            $table->foreignId('audiobook_summary_id')->nullable()->constrained('audiobook_summaries')->nullOnDelete();
            $table->string('source_version_id')->nullable();
            $table->unsignedInteger('scene_index');
            $table->unsignedInteger('cluster_index')->nullable();
            $table->string('title');
            $table->longText('script_text');
            $table->unsignedInteger('estimated_duration_seconds')->default(0);
            $table->string('scene_type')->default('city');
            $table->json('keywords')->nullable();
            $table->boolean('is_avatar_segment')->default(false);
            $table->boolean('is_emotional_climax')->default(false);
            $table->string('status')->default('pending');
            $table->string('resolved_source')->nullable();
            $table->string('resolved_asset_path')->nullable();
            $table->float('resolved_score')->nullable();
            $table->string('avatar_video_path')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['audio_book_id', 'scene_index']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audiobook_video_scenes');
    }
};
