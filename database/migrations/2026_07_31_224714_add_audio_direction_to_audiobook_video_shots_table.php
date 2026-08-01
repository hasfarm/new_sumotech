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
            // SFX is always shot-scoped (a distinct, notable sound event named in THIS
            // shot's narration) — never inherited, unlike ambience/music below.
            $table->boolean('needs_sfx')->default(false);
            $table->json('sfx_keywords')->nullable();
            $table->text('sfx_prompt')->nullable();
            $table->foreignId('sfx_asset_id')->nullable()->constrained('audiobook_audio_assets')->nullOnDelete();
            $table->string('sfx_status')->nullable()->default('pending');

            // null/false = inherit the scene's ambience/music baseline (the common case —
            // see AudiobookVideoShot::resolvedAmbience()/resolvedMusic()). true = this shot's
            // own ambience_keywords/prompt/asset_id below are used instead, because its
            // content genuinely diverges from the scene (e.g. a shot's action briefly cuts to
            // a different sub-location within the same scene).
            $table->boolean('ambience_override')->nullable();
            $table->json('ambience_keywords')->nullable();
            $table->text('ambience_prompt')->nullable();
            $table->foreignId('ambience_asset_id')->nullable()->constrained('audiobook_audio_assets')->nullOnDelete();
            $table->string('ambience_status')->nullable();

            $table->boolean('music_override')->nullable();
            $table->json('music_keywords')->nullable();
            $table->text('music_prompt')->nullable();
            $table->foreignId('music_asset_id')->nullable()->constrained('audiobook_audio_assets')->nullOnDelete();
            $table->string('music_status')->nullable();

            // Final per-shot FFmpeg mixdown (narration + resolved ambience + resolved music +
            // sfx, auto-ducked) — set once RenderShotAudioMixJob runs (Phase 7).
            $table->string('audio_mix_path')->nullable();

            // Staleness marker for the audio-need analysis itself, same convention as
            // prompt_version (image/keyword enrichment) — bumped whenever the AI Director's
            // audio-direction prompt changes meaningfully.
            $table->string('audio_prompt_version')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audiobook_video_shots', function (Blueprint $table) {
            $table->dropForeign(['sfx_asset_id']);
            $table->dropForeign(['ambience_asset_id']);
            $table->dropForeign(['music_asset_id']);
            $table->dropColumn([
                'needs_sfx', 'sfx_keywords', 'sfx_prompt', 'sfx_asset_id', 'sfx_status',
                'ambience_override', 'ambience_keywords', 'ambience_prompt', 'ambience_asset_id', 'ambience_status',
                'music_override', 'music_keywords', 'music_prompt', 'music_asset_id', 'music_status',
                'audio_mix_path', 'audio_prompt_version',
            ]);
        });
    }
};
