<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A scene can legitimately span many different times/places (e.g. a 79-shot scene
     * covering an entire 17-year journey) — one location/timeline/story_phase per SCENE is
     * too coarse and causes shots depicting an earlier/different moment to be enriched
     * against the wrong setting. These columns let enrichment resolve context per shot
     * (computed once per enrichment chunk of ~15 shots, not per single shot, but stored
     * per-shot so different chunks within the same scene can genuinely differ) — nullable,
     * so shots without this data fall back to the scene-level binding unchanged.
     */
    public function up(): void
    {
        Schema::table('audiobook_video_shots', function (Blueprint $table) {
            $table->json('timeline_binding')->nullable()->after('story_bible_version_used');
            $table->json('location_binding')->nullable()->after('timeline_binding');
            $table->string('shot_story_phase')->nullable()->after('location_binding');
            // 'story' (depicts the book's narrative world) vs 'host_narration' (channel host
            // talking to the viewer, e.g. intro/outro) — host shots must not be compared
            // against the story's historical/cultural context at all.
            $table->string('narrative_mode')->nullable()->after('shot_story_phase');
        });
    }

    public function down(): void
    {
        Schema::table('audiobook_video_shots', function (Blueprint $table) {
            $table->dropColumn(['timeline_binding', 'location_binding', 'shot_story_phase', 'narrative_mode']);
        });
    }
};
