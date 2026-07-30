<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * All nullable: a scene with no story bible (old pipeline run, or a book that never had
     * Phase 2 run) keeps working exactly as before — SplitVideoScenesJob only populates
     * these when an active AudiobookStoryBible exists for the book.
     */
    public function up(): void
    {
        Schema::table('audiobook_video_scenes', function (Blueprint $table) {
            $table->foreignId('story_bible_id')->nullable()->after('audiobook_summary_id')
                ->constrained('audiobook_story_bibles')->nullOnDelete();

            // Denormalized copy of bible_version at assignment time — story_bible_id alone
            // would go null via nullOnDelete once a superseded version is deleted on
            // regenerate, losing the signal needed to detect staleness after the fact.
            $table->unsignedInteger('story_bible_version_used')->nullable()->after('story_bible_id');

            // Version of the assignSceneContext prompt/logic itself — independent axis from
            // story_bible_version_used (the bible's CONTENT changing vs the ASSIGNMENT LOGIC
            // changing are two separate staleness triggers).
            $table->string('scene_direction_version')->nullable()->after('story_bible_version_used');

            $table->json('timeline_binding')->nullable()->after('scene_direction_version');
            $table->json('location_binding')->nullable()->after('timeline_binding');
            $table->string('story_phase')->nullable()->after('location_binding');
        });
    }

    public function down(): void
    {
        Schema::table('audiobook_video_scenes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('story_bible_id');
            $table->dropColumn(['story_bible_version_used', 'scene_direction_version', 'timeline_binding', 'location_binding', 'story_phase']);
        });
    }
};
