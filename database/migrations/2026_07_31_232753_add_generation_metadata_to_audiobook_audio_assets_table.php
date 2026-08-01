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
        Schema::table('audiobook_audio_assets', function (Blueprint $table) {
            // Echoes the caller's requested duration_seconds/music_length_ms input — compared
            // against the existing `duration_seconds` column (the ACTUAL probed duration of the
            // returned file) to surface any drift between what was asked for and what the
            // provider actually returned.
            $table->float('requested_duration_seconds')->nullable();
            $table->unsignedInteger('generation_latency_ms')->nullable();
            // ElevenLabs Sound Effects exposes a real per-call cost via the `character-cost`
            // response header; the Music endpoint exposes no equivalent (see
            // ElevenLabsMusicService) — null here honestly means "not exposed by the provider
            // for this call", not "free".
            $table->unsignedInteger('credits_used')->nullable();
            // Deliberately NOT auto-computed — no reliable credits-to-USD rate is exposed via
            // the ElevenLabs API for a payg account; left for manual/billing-dashboard entry.
            $table->float('cost_usd')->nullable();
            // x-trace-id (Sound Effects) or song-id (Music) — minted per HTTP call, NOT
            // retrievable after the fact for calls made before this column existed.
            $table->string('provider_request_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audiobook_audio_assets', function (Blueprint $table) {
            $table->dropColumn([
                'requested_duration_seconds', 'generation_latency_ms', 'credits_used', 'cost_usd', 'provider_request_id',
            ]);
        });
    }
};
