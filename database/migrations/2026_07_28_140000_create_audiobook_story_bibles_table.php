<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Versioned, not 1:1 with audio_book_id: regenerating a Story Bible builds a new
     * `draft` row (and new child timelines/locations/characters/phases scoped to THIS
     * row's id) alongside the currently `active` one, and only swaps `is_active` in a
     * transaction after the new version validates — the active version is never deleted
     * before a successful replacement exists. Only one active row per audio_book_id is an
     * application-level invariant (enforced in AnalyzeStoryDirectionJob), not a DB
     * constraint, since MySQL has no partial/filtered unique index for `is_active=true`.
     */
    public function up(): void
    {
        Schema::create('audiobook_story_bibles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audio_book_id')->constrained('audio_books')->cascadeOnDelete();
            $table->unsignedInteger('bible_version');
            $table->string('schema_version');
            $table->string('status')->default('draft'); // draft/extracting/consolidating/validating/active/superseded/failed
            $table->boolean('is_active')->default(false);

            // Facts ABOUT the work (genre/tone/timeline_structure/overall_time_span/
            // historical_context/geography/culture/world_rules/forbidden_elements), each
            // wrapped as a claim {value,confidence,source_type,evidence,rationale}.
            $table->json('source_facts')->nullable();

            // Creative decisions an AI Director may make (visual_style/palette/lighting/
            // camera_language/pacing) — deliberately kept structurally separate from
            // source_facts since these are not claims about the text itself.
            $table->json('director_treatment')->nullable();

            $table->json('raw_facts')->nullable(); // map-step output, kept for provenance/re-reduce
            $table->json('batches')->nullable(); // ledger: [{index, chapter_ids, status, attempts, error}]
            $table->unsignedInteger('total_batches')->nullable();
            $table->unsignedInteger('processed_batches')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();

            $table->unique(['audio_book_id', 'bible_version']);
            $table->index(['audio_book_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audiobook_story_bibles');
    }
};
