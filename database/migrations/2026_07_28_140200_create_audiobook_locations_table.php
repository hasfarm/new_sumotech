<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audiobook_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_bible_id')->constrained('audiobook_story_bibles')->cascadeOnDelete();
            $table->string('canonical_name');
            $table->json('aliases')->nullable(); // same place referred to differently across chapters -> one row

            // {region, historical_polity, cultural_groups_present:[{name,presence:local|visiting|ambiguous,
            // confidence,source_type,evidence,rationale}], architecture, clothing, transportation,
            // religion, material_culture, environment, anachronism_constraints} — each a claim.
            // Prompt-level rule: prefer a specific named polity/culture over generic
            // "Eastern"/"Western" labels whenever the text supports more specific identification.
            $table->json('cultural_context')->nullable();

            $table->json('visual_notes')->nullable(); // claim, location-scoped director treatment
            $table->timestamps();

            $table->unique(['story_bible_id', 'canonical_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audiobook_locations');
    }
};
