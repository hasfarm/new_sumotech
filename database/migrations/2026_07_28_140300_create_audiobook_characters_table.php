<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audiobook_characters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_bible_id')->constrained('audiobook_story_bibles')->cascadeOnDelete();
            $table->string('canonical_name');
            $table->json('aliases')->nullable();

            $table->json('role')->nullable(); // claim — narrative role (protagonist/supporting/...)

            // {region, culture, notes} — where the character is FROM. Permanent regardless of
            // where a scene later places them (see current_location_id on character_phases).
            $table->json('cultural_origin')->nullable();

            // {gender, ethnicity_notes, base_face, defining_marks} — the "must stay
            // recognizable" anchor. Not treated as absolutely unchangeable: a phase's
            // mutable_traits.identity_overrides can override it when the story gives a real
            // trigger (e.g. a permanent scar), but by default it holds across every phase.
            $table->json('identity_anchor')->nullable();

            // {physique, hairstyle, wardrobe, emotional_state, social_status, occupation} —
            // default state, used directly whenever a scene has no character_phase_id for
            // this character (a character with zero phase rows is valid and expected).
            $table->json('baseline_traits')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['story_bible_id', 'canonical_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audiobook_characters');
    }
};
