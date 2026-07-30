<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Replaces the implicit "keywords/image_request non-empty means enriched" inference
     * with explicit state, so a prompt-version bump or a continuity-validation failure can
     * mark a shot as needing re-enrichment without depending on column-emptiness checks.
     */
    public function up(): void
    {
        Schema::table('audiobook_video_shots', function (Blueprint $table) {
            $table->string('enrichment_status')->default('pending')->after('is_real_world');
            $table->string('prompt_version')->nullable()->after('enrichment_status');
            $table->string('validation_status')->default('unvalidated')->after('prompt_version');
            $table->json('continuity_error')->nullable()->after('validation_status');
            $table->timestamp('enriched_at')->nullable()->after('continuity_error');
        });
    }

    public function down(): void
    {
        Schema::table('audiobook_video_shots', function (Blueprint $table) {
            $table->dropColumn(['enrichment_status', 'prompt_version', 'validation_status', 'continuity_error', 'enriched_at']);
        });
    }
};
