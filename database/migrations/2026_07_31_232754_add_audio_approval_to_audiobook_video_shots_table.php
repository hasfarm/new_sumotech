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
        Schema::table('audiobook_video_shots', function (Blueprint $table) {
            // Same per-slot approval/lock trail as audiobook_video_scenes' ambience/music
            // columns (see that migration's comment) — one set per shot-level audio slot: sfx
            // (always shot-scoped) plus ambience_override/music_override (only meaningful when
            // the shot's own ambience_override/music_override flag is set).
            $table->foreignId('sfx_selected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('sfx_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sfx_approved_at')->nullable();
            $table->foreignId('sfx_locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sfx_locked_at')->nullable();

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
        Schema::table('audiobook_video_shots', function (Blueprint $table) {
            $table->dropForeign(['sfx_selected_by']);
            $table->dropForeign(['sfx_approved_by']);
            $table->dropForeign(['sfx_locked_by']);
            $table->dropForeign(['ambience_selected_by']);
            $table->dropForeign(['ambience_approved_by']);
            $table->dropForeign(['ambience_locked_by']);
            $table->dropForeign(['music_selected_by']);
            $table->dropForeign(['music_approved_by']);
            $table->dropForeign(['music_locked_by']);
            $table->dropColumn([
                'sfx_selected_by', 'sfx_approved_by', 'sfx_approved_at', 'sfx_locked_by', 'sfx_locked_at',
                'ambience_selected_by', 'ambience_approved_by', 'ambience_approved_at', 'ambience_locked_by', 'ambience_locked_at',
                'music_selected_by', 'music_approved_by', 'music_approved_at', 'music_locked_by', 'music_locked_at',
            ]);
        });
    }
};
