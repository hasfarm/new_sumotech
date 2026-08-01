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
        Schema::table('audiobook_video_shots', function (Blueprint $table) {
            // Narration TTS for a non-avatar shot, using the pipeline's MAIN voice (tts_provider/
            // tts_voice_gender/tts_voice_name on audiobook_video_pipelines) — mirrors
            // avatar_audio_path but deliberately a separate column/voice, never shared with the
            // avatar/lipsync narration.
            $table->string('narration_audio_path')->nullable()->after('avatar_audio_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audiobook_video_shots', function (Blueprint $table) {
            $table->dropColumn('narration_audio_path');
        });
    }
};
