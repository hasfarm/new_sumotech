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
        Schema::table('audiobook_summaries', function (Blueprint $table) {
            $table->string('target_level')->nullable()->after('processed_batches');
            $table->json('versions')->nullable()->after('outro');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audiobook_summaries', function (Blueprint $table) {
            $table->dropColumn(['target_level', 'versions']);
        });
    }
};
