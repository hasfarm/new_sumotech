<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audiobook_timelines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('story_bible_id')->constrained('audiobook_story_bibles')->cascadeOnDelete();
            $table->string('canonical_key'); // stable slug, e.g. "main", "flashback_1" — NOT the free-text label
            $table->string('label');
            $table->json('aliases')->nullable();
            $table->string('timeline_type')->default('main'); // main/flashback/parallel/frame_story/other
            $table->integer('chronological_order'); // story-time order
            $table->integer('narrative_introduction_order')->nullable(); // narrative/book-position order — distinct axis
            $table->json('profile')->nullable(); // claim: {value:{story_time_marker,description},confidence,source_type,evidence,rationale}
            $table->timestamps();

            $table->unique(['story_bible_id', 'canonical_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audiobook_timelines');
    }
};
