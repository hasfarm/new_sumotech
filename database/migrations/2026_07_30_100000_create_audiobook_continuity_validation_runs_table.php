<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ledger-tracked (same shape as EnrichVideoShotsJob::shot_chunks / AnalyzeStoryDirectionJob
     * batches) so a validation pass is resumable, retryable per-scene, and auditable — one
     * row per ValidateStoryContinuityJob invocation, whether a full-book run, a scene-scoped
     * run, or a shot-targeted post-regeneration confirmation.
     */
    public function up(): void
    {
        Schema::create('audiobook_continuity_validation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audio_book_id')->constrained('audio_books')->cascadeOnDelete();
            $table->string('status')->default('running'); // running/completed/failed
            $table->string('scope')->default('full'); // full/scenes/shots
            $table->json('target_scene_ids')->nullable();
            $table->json('target_shot_ids')->nullable();
            $table->string('continuity_validator_version');
            $table->json('batches')->nullable(); // [{scene_id, status, attempts, error}]
            $table->unsignedInteger('total_scenes')->nullable();
            $table->unsignedInteger('processed_scenes')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['audio_book_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audiobook_continuity_validation_runs');
    }
};
