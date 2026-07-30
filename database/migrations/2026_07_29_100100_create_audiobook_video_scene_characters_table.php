<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pivot supporting MULTIPLE characters per scene, each independently possibly at a
     * different (or no) character_phase — character_phase_id is nullable: no phase exists,
     * or the AI couldn't confidently resolve one, both fall back to that character's
     * baseline_traits/identity_anchor at the point of consumption (enrichShotsChunk /
     * buildImagePrompt), which is why resolution_status doesn't need to be branched on
     * there — it's for continuity review (Phase 4), not for changing prompt behavior.
     */
    public function up(): void
    {
        Schema::create('audiobook_video_scene_characters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_scene_id')->constrained('audiobook_video_scenes')->cascadeOnDelete();
            $table->foreignId('character_id')->constrained('audiobook_characters')->cascadeOnDelete();
            $table->foreignId('character_phase_id')->nullable()->constrained('audiobook_character_phases')->nullOnDelete();

            $table->string('confidence')->default('unknown');
            $table->string('source_type')->default('unknown');
            $table->json('evidence')->nullable();

            // resolved | baseline_fallback | unresolved_phase — a character the AI named but
            // that doesn't match anything in the roster is dropped entirely (logged), never
            // persisted as "unresolved_character" here.
            $table->string('resolution_status')->default('baseline_fallback');

            $table->timestamps();

            $table->unique(['video_scene_id', 'character_id'], 'scene_characters_scene_char_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audiobook_video_scene_characters');
    }
};
