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
        Schema::table('audiobook_video_scenes', function (Blueprint $table) {
            // Per-slot approval/lock trail — WHO picked/approved/locked this slot's asset and
            // WHEN, independent of the asset row itself (the same AudiobookAudioAsset can be
            // reused across many slots, each with its own independent approval state). Status
            // values: pending -> generated -> approved -> locked (terminal, protected from bulk
            // jobs/stale regeneration), or rejected (user explicitly threw out the current pick).
            $table->foreignId('ambience_selected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ambience_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ambience_approved_at')->nullable();
            $table->foreignId('ambience_locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('ambience_locked_at')->nullable();

            $table->foreignId('music_selected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('music_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('music_approved_at')->nullable();
            $table->foreignId('music_locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('music_locked_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audiobook_video_scenes', function (Blueprint $table) {
            $table->dropForeign(['ambience_selected_by']);
            $table->dropForeign(['ambience_approved_by']);
            $table->dropForeign(['ambience_locked_by']);
            $table->dropForeign(['music_selected_by']);
            $table->dropForeign(['music_approved_by']);
            $table->dropForeign(['music_locked_by']);
            $table->dropColumn([
                'ambience_selected_by', 'ambience_approved_by', 'ambience_approved_at', 'ambience_locked_by', 'ambience_locked_at',
                'music_selected_by', 'music_approved_by', 'music_approved_at', 'music_locked_by', 'music_locked_at',
            ]);
        });
    }
};
