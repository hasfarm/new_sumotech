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
        Schema::table('audiobook_video_pipelines', function (Blueprint $table) {
            // Same resumable-progress shape as bulk_ai_generate_status/bulk_narration_tts_status
            // (status/total/processed/started_at/last_progress_at) — RegenerateStaleSceneDirectionJob
            // previously reported no live progress at all, so "Regenerate stale scenes" looked
            // like it finished instantly even though it was still running in the background.
            $table->json('story_bible_regenerate_stale_status')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audiobook_video_pipelines', function (Blueprint $table) {
            $table->dropColumn('story_bible_regenerate_stale_status');
        });
    }
};
