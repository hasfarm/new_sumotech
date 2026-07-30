<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shot-scoped character presence — additive alongside audiobook_video_scene_characters
     * (which stays the scene-wide "who ever appears in this scene" roster, unchanged). This
     * table exists because a character named only once (or referred to by pronoun) can be
     * missed by whole-scene character-presence detection on a long scene, but IS reliably
     * caught when resolved against the much smaller ~15-shot enrichment-chunk window that
     * contains both the naming and the pronoun reference. When present, buildStableContextBlock()
     * prefers this shot-level row over the scene-level pivot for that character; when absent,
     * behavior is unchanged (falls back to the scene-level pivot exactly as before).
     */
    public function up(): void
    {
        Schema::create('audiobook_video_shot_characters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_shot_id')->constrained('audiobook_video_shots')->cascadeOnDelete();
            $table->foreignId('character_id')->constrained('audiobook_characters')->cascadeOnDelete();
            $table->foreignId('character_phase_id')->nullable()->constrained('audiobook_character_phases')->nullOnDelete();

            $table->string('confidence')->default('unknown');
            $table->string('source_type')->default('unknown');
            $table->json('evidence')->nullable();

            // resolved | baseline_fallback | unresolved_phase | pronoun_inferred — the last
            // one means this character wasn't named IN this shot's own sentence, only in a
            // nearby shot within the same enrichment chunk (e.g. "Thạch Bàn Đà" named in shot
            // N, "gã này" in shot N+1) — kept distinct from a direct name match so continuity
            // review can tell the two apart.
            $table->string('resolution_status')->default('baseline_fallback');

            $table->timestamps();

            $table->unique(['video_shot_id', 'character_id'], 'shot_characters_shot_char_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audiobook_video_shot_characters');
    }
};
