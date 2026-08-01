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
            // — one column per bulk audio job (scene ambience baseline, scene music baseline,
            // shot sfx), so the "✨ Tạo tất cả" button can show combined progress across all 3.
            $table->json('bulk_scene_ambience_status')->nullable();
            $table->json('bulk_scene_music_status')->nullable();
            $table->json('bulk_shot_sfx_status')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audiobook_video_pipelines', function (Blueprint $table) {
            $table->dropColumn(['bulk_scene_ambience_status', 'bulk_scene_music_status', 'bulk_shot_sfx_status']);
        });
    }
};
