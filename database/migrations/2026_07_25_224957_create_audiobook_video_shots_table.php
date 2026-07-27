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
        Schema::create('audiobook_video_shots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_scene_id')->constrained('audiobook_video_scenes')->cascadeOnDelete();
            $table->unsignedInteger('shot_index');
            $table->longText('sentence_text');
            $table->unsignedInteger('estimated_duration_seconds')->default(0);
            $table->json('keywords')->nullable();
            $table->boolean('is_avatar_segment')->default(false);
            $table->string('status')->default('pending');
            $table->string('resolved_source')->nullable();
            $table->string('resolved_asset_path')->nullable();
            $table->float('resolved_score')->nullable();
            $table->string('avatar_video_path')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['video_scene_id', 'shot_index'], 'video_shots_scene_index_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audiobook_video_shots');
    }
};
