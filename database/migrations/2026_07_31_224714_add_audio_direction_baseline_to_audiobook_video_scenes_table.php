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
        Schema::table('audiobook_video_scenes', function (Blueprint $table) {
            // Ambience/music BASELINE for the whole scene — shots inherit this by default
            // (see AudiobookVideoShot::resolvedAmbience()/resolvedMusic()), only overriding
            // when a shot's own content genuinely diverges from the scene.
            $table->boolean('needs_ambience')->default(false);
            $table->json('ambience_keywords')->nullable();
            $table->text('ambience_prompt')->nullable();
            $table->foreignId('ambience_asset_id')->nullable()->constrained('audiobook_audio_assets')->nullOnDelete();
            $table->string('ambience_status')->nullable()->default('pending');

            $table->boolean('needs_music')->default(false);
            $table->json('music_keywords')->nullable();
            $table->text('music_prompt')->nullable();
            $table->foreignId('music_asset_id')->nullable()->constrained('audiobook_audio_assets')->nullOnDelete();
            $table->string('music_status')->nullable()->default('pending');

            // Staleness marker, same convention as scene_direction_version.
            $table->string('audio_direction_version')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audiobook_video_scenes', function (Blueprint $table) {
            $table->dropForeign(['ambience_asset_id']);
            $table->dropForeign(['music_asset_id']);
            $table->dropColumn([
                'needs_ambience', 'ambience_keywords', 'ambience_prompt', 'ambience_asset_id', 'ambience_status',
                'needs_music', 'music_keywords', 'music_prompt', 'music_asset_id', 'music_status',
                'audio_direction_version',
            ]);
        });
    }
};
