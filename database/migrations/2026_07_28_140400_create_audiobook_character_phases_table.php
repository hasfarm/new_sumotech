<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audiobook_character_phases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained('audiobook_characters')->cascadeOnDelete();
            $table->foreignId('timeline_id')->nullable()->constrained('audiobook_timelines')->nullOnDelete();
            // Real FK column (not a name buried in JSON) so Phase 3/4 can join on it directly.
            $table->foreignId('current_location_id')->nullable()->constrained('audiobook_locations')->nullOnDelete();

            $table->string('label');
            $table->integer('chronological_order');

            // {physique, hairstyle, wardrobe, injuries, emotional_state, social_status,
            // occupation, identity_overrides} — identity_overrides is nullable/normally absent;
            // it exists only for the rare case where even identity_anchor facts change with a
            // real story trigger.
            $table->json('mutable_traits')->nullable();

            $table->json('profile')->nullable(); // claim: {value:{story_time_marker,trigger_reason},confidence,source_type,evidence,rationale}
            $table->timestamps();

            $table->unique(['character_id', 'chronological_order'], 'char_phases_char_order_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audiobook_character_phases');
    }
};
