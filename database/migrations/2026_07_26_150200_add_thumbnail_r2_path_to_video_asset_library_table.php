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
        Schema::table('video_asset_library', function (Blueprint $table) {
            // Vision scoring needs a still frame, not raw video bytes — extracted via ffmpeg
            // at archive time for video-type assets. Null for image-type assets (the image
            // itself is the thumbnail).
            $table->string('thumbnail_r2_path')->nullable()->after('r2_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('video_asset_library', function (Blueprint $table) {
            $table->dropColumn('thumbnail_r2_path');
        });
    }
};
