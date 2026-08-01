<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('audiobook_audio_assets', function (Blueprint $table) {
            $table->id();
            // 'sfx'|'ambience'|'music' — one shared table for all 3 categories (mirrors how
            // video_asset_library shares 'image'|'video' under media_type) rather than 3
            // separate tables.
            $table->string('audio_category', 16);
            // 'elevenlabs'|'storyblocks' — where the clip actually came from.
            $table->string('provider', 32);
            // Finer-grained than provider, e.g. 'elevenlabs_sfx' vs 'elevenlabs_music' —
            // mirrors video_asset_library's origin_source convention.
            $table->string('origin_source', 32);
            $table->string('external_id')->nullable();
            $table->string('r2_path');
            $table->float('duration_seconds')->nullable();
            $table->boolean('is_loopable')->default(false);
            $table->string('license_label')->nullable();
            // Structured attribution — video_asset_library never had this field at all
            // (only the free-text license_label), added here from the start.
            $table->string('attribution')->nullable();
            $table->text('prompt');
            $table->json('keywords')->nullable();
            $table->string('audio_prompt_version')->nullable();
            // Normalized hash of (category + cleaned prompt + duration bucket) — the fast
            // dedup pre-check before a Qdrant semantic search is even attempted.
            $table->string('fingerprint');
            $table->float('score_final')->nullable();
            $table->foreignId('source_book_id')->nullable()->constrained('audio_books')->nullOnDelete();
            $table->unsignedInteger('usage_count')->default(1);
            $table->timestamps();

            $table->index(['audio_category', 'provider']);
            $table->index('fingerprint');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audiobook_audio_assets');
    }
};
