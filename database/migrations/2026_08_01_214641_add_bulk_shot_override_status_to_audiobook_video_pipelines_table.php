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
            // "✨ Tạo tất cả" extended to also bulk-fill shot-level ambience/music OVERRIDES
            // the AI Director already flagged (ambience_override/music_override = true) but
            // nobody has generated yet — same resumable-progress shape as the other bulk_*
            // columns.
            $table->json('bulk_shot_ambience_override_status')->nullable();
            $table->json('bulk_shot_music_override_status')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audiobook_video_pipelines', function (Blueprint $table) {
            $table->dropColumn(['bulk_shot_ambience_override_status', 'bulk_shot_music_override_status']);
        });
    }
};
