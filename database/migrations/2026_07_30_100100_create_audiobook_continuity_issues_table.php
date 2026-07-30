<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * issue_type is a plain string (App\Models\AudiobookContinuityIssue holds the constants/
     * allowed-value list in PHP), not a DB enum — new categories don't need a migration.
     * issue_fingerprint is the upsert key (see AudiobookContinuityIssue::fingerprint()) so
     * re-validating never creates a duplicate row for "the same slot" (scene/shot + type,
     * +binding_key for unresolved_binding since a scene can have both an unresolved timeline
     * AND an unresolved location at once).
     */
    public function up(): void
    {
        Schema::create('audiobook_continuity_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audio_book_id')->constrained('audio_books')->cascadeOnDelete();
            $table->foreignId('video_scene_id')->nullable()->constrained('audiobook_video_scenes')->cascadeOnDelete();
            $table->foreignId('video_shot_id')->nullable()->constrained('audiobook_video_shots')->cascadeOnDelete();

            $table->string('issue_type');
            $table->string('binding_key')->nullable(); // 'timeline'|'location' — only for unresolved_binding, disambiguates two possible open issues per scene
            $table->string('severity'); // error | warning | needs_review
            $table->text('message');
            $table->json('expected_value')->nullable();
            $table->json('actual_value')->nullable();
            $table->string('confidence'); // confirmed | inferred | inferred_low_confidence | unknown
            $table->string('source_type'); // explicit_text | inferred_from_text | director_choice | user_override | unknown
            $table->json('evidence')->nullable();
            $table->text('rationale')->nullable();
            $table->string('resolution_reason')->nullable(); // unresolved_binding only: entity_missing/ambiguous_match/no_evidence/alias_not_found/binding_stale
            $table->string('recommended_action'); // auto_regenerate | manual_review (system never assigns 'accept' — user-driven only)
            $table->string('status')->default('open'); // open | accepted | regenerating | resolved

            $table->string('issue_fingerprint');
            $table->string('continuity_validator_version');
            $table->foreignId('validator_run_id')->nullable()->constrained('audiobook_continuity_validation_runs')->nullOnDelete();
            $table->string('regeneration_batch_id')->nullable();

            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            $table->unique('issue_fingerprint');
            $table->index(['audio_book_id', 'status']);
            $table->index(['video_shot_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audiobook_continuity_issues');
    }
};
