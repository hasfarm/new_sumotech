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
            // Per-shot avatar image override — null means "use the book's speaker's default
            // avatar" (AvatarSegmentService::resolveAvatarImageUrl()'s fallback), so most
            // shots never need this set explicitly.
            $table->string('avatar_image_path')->nullable()->after('avatar_video_path');
            // Persisted TTS narration for this avatar shot, so a lipsync (re)generation or a
            // failed-attempt retry never silently re-spends a TTS call for text already
            // synthesized — previously this was fully transient (generated fresh every time,
            // never saved).
            $table->string('avatar_audio_path')->nullable()->after('avatar_image_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audiobook_video_shots', function (Blueprint $table) {
            $table->dropColumn(['avatar_image_path', 'avatar_audio_path']);
        });
    }
};
