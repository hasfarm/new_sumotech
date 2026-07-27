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
            $table->text('image_request')->nullable()->after('keywords');
            $table->foreignId('resolved_library_asset_id')->nullable()
                ->after('resolved_score')
                ->constrained('video_asset_library')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audiobook_video_shots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resolved_library_asset_id');
            $table->dropColumn('image_request');
        });
    }
};
