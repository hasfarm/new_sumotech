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
            // AI-selected Ken Burns motion (zoompan) applied to this shot's still image, if it
            // has one — see MotionDirectionService::MOTION_PRESETS for the fixed whitelist the
            // AI must choose from; PHP (MotionRenderService) is what actually builds the ffmpeg
            // filter graph, never the AI. Same status+approval-trail shape as the SFX slot on
            // this exact table (pending -> generated -> approved, locked terminal).
            $table->string('motion_preset')->nullable();
            $table->float('motion_focus_x')->nullable();
            $table->float('motion_focus_y')->nullable();
            $table->float('motion_intensity')->nullable();
            $table->float('motion_duration_seconds')->nullable();
            $table->text('motion_reason')->nullable();
            $table->string('motion_asset_path')->nullable();
            $table->string('motion_prompt_version')->nullable();
            $table->string('motion_status')->nullable()->default('pending');
            $table->foreignId('motion_selected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('motion_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('motion_approved_at')->nullable();
            $table->foreignId('motion_locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('motion_locked_at')->nullable();

            // AI-selected transition INTO this shot from the previous one — see
            // MotionDirectionService::TRANSITION_PRESETS. Applies regardless of whether either
            // shot's visual is a still-with-motion or an existing video (avatar/animated) —
            // a transition is about the cut point between two clips, not their contents.
            $table->string('transition_preset')->nullable();
            $table->float('transition_intensity')->nullable();
            $table->float('transition_duration_seconds')->nullable();
            $table->text('transition_reason')->nullable();
            $table->string('transition_asset_path')->nullable();
            $table->string('transition_prompt_version')->nullable();
            $table->string('transition_status')->nullable()->default('pending');
            $table->foreignId('transition_selected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('transition_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('transition_approved_at')->nullable();
            $table->foreignId('transition_locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('transition_locked_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audiobook_video_shots', function (Blueprint $table) {
            $table->dropForeign(['motion_selected_by']);
            $table->dropForeign(['motion_approved_by']);
            $table->dropForeign(['motion_locked_by']);
            $table->dropForeign(['transition_selected_by']);
            $table->dropForeign(['transition_approved_by']);
            $table->dropForeign(['transition_locked_by']);
            $table->dropColumn([
                'motion_preset', 'motion_focus_x', 'motion_focus_y', 'motion_intensity',
                'motion_duration_seconds', 'motion_reason', 'motion_asset_path', 'motion_prompt_version',
                'motion_status', 'motion_selected_by', 'motion_approved_by', 'motion_approved_at',
                'motion_locked_by', 'motion_locked_at',
                'transition_preset', 'transition_intensity', 'transition_duration_seconds',
                'transition_reason', 'transition_asset_path', 'transition_prompt_version',
                'transition_status', 'transition_selected_by', 'transition_approved_by',
                'transition_approved_at', 'transition_locked_by', 'transition_locked_at',
            ]);
        });
    }
};
