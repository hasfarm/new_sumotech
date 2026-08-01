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
            // Progress ledgers for the "Create All" bulk-TTS buttons next to each voice
            // picker — same shape/purpose as bulk_ai_generate_status (background job, survives
            // tab close/reload, page just polls status()).
            $table->json('bulk_narration_tts_status')->nullable()->after('bulk_ai_generate_status');
            $table->json('bulk_avatar_tts_status')->nullable()->after('bulk_narration_tts_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audiobook_video_pipelines', function (Blueprint $table) {
            $table->dropColumn(['bulk_narration_tts_status', 'bulk_avatar_tts_status']);
        });
    }
};
