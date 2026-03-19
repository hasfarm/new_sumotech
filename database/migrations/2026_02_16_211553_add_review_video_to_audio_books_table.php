<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audio_books', function (Blueprint $table) {
            $table->longText('review_script')->nullable()->after('description_scene_video_duration');
            $table->string('review_video')->nullable()->after('review_script');
            $table->float('review_video_duration')->nullable()->after('review_video');
        });
    }

    public function down(): void
    {
        Schema::table('audio_books', function (Blueprint $table) {
            $table->dropColumn(['review_script', 'review_video', 'review_video_duration']);
        });
    }
};
