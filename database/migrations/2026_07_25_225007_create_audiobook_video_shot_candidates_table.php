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
        Schema::create('audiobook_video_shot_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_shot_id')->constrained('audiobook_video_shots')->cascadeOnDelete();
            $table->string('source');
            $table->string('external_id');
            $table->string('thumbnail_url', 2048)->nullable();
            $table->string('download_url', 2048)->nullable();
            $table->string('media_type')->default('video');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->float('duration_seconds')->nullable();
            $table->string('license_label')->nullable();
            $table->float('score_semantic')->nullable();
            $table->float('score_historical')->nullable();
            $table->float('score_resolution')->nullable();
            $table->float('score_mood')->nullable();
            $table->float('score_license')->nullable();
            $table->float('score_final')->nullable();
            $table->boolean('is_selected')->default(false);
            $table->json('raw_metadata')->nullable();
            $table->timestamps();

            $table->index(['video_shot_id', 'score_final'], 'video_shot_candidates_shot_score_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audiobook_video_shot_candidates');
    }
};
