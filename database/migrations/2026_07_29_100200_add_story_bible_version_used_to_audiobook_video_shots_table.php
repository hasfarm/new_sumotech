<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stamped alongside prompt_version/enriched_at at successful enrichment, copied from
     * the scene's story_bible_version_used at that moment — lets buildFreshChunkPlan()
     * detect a shot as stale when the Story Bible active version moves on even if
     * prompt_version itself hasn't changed.
     */
    public function up(): void
    {
        Schema::table('audiobook_video_shots', function (Blueprint $table) {
            $table->unsignedInteger('story_bible_version_used')->nullable()->after('prompt_version');
        });
    }

    public function down(): void
    {
        Schema::table('audiobook_video_shots', function (Blueprint $table) {
            $table->dropColumn('story_bible_version_used');
        });
    }
};
