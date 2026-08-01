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
            $table->json('bulk_ai_generate_status')->nullable()->after('shot_chunks');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audiobook_video_pipelines', function (Blueprint $table) {
            $table->dropColumn('bulk_ai_generate_status');
        });
    }
};
