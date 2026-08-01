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
        Schema::table('audiobook_video_pipelines', function (Blueprint $table) {
            // Main voice: used for the whole work (one voice per pipeline, same scope as
            // image_style/image_api_provider). Kept separate from avatar_tts_* below —
            // the avatar/lipsync voice is deliberately independent so it can be paired with
            // a specific speaker image without forcing the same voice everywhere else.
            $table->string('tts_provider', 16)->nullable()->after('image_api_model');
            $table->string('tts_voice_gender', 8)->nullable()->after('tts_provider');
            $table->string('tts_voice_name', 100)->nullable()->after('tts_voice_gender');

            $table->string('avatar_tts_provider', 16)->nullable()->after('tts_voice_name');
            $table->string('avatar_tts_voice_gender', 8)->nullable()->after('avatar_tts_provider');
            $table->string('avatar_tts_voice_name', 100)->nullable()->after('avatar_tts_voice_gender');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audiobook_video_pipelines', function (Blueprint $table) {
            $table->dropColumn([
                'tts_provider', 'tts_voice_gender', 'tts_voice_name',
                'avatar_tts_provider', 'avatar_tts_voice_gender', 'avatar_tts_voice_name',
            ]);
        });
    }
};
