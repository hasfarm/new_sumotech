<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audiobook_video_shots', function (Blueprint $table) {
            $table->timestamp('validated_at')->nullable()->after('enriched_at');
            $table->string('continuity_validator_version')->nullable()->after('validated_at');
        });

        Schema::table('audiobook_video_scenes', function (Blueprint $table) {
            // Rollup of this scene's open issues (its own scene-level issues + all its
            // shots' issues) — computed after each validation pass, not derived live on
            // every read, to keep the report page cheap.
            $table->string('continuity_status')->nullable()->after('story_phase');
            $table->string('continuity_validator_version')->nullable()->after('continuity_status');
        });
    }

    public function down(): void
    {
        Schema::table('audiobook_video_shots', function (Blueprint $table) {
            $table->dropColumn(['validated_at', 'continuity_validator_version']);
        });

        Schema::table('audiobook_video_scenes', function (Blueprint $table) {
            $table->dropColumn(['continuity_status', 'continuity_validator_version']);
        });
    }
};
