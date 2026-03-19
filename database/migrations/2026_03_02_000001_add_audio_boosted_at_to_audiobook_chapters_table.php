<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audiobook_chapters', function (Blueprint $table) {
            $table->timestamp('audio_boosted_at')->nullable()->after('audio_file');
        });
    }

    public function down(): void
    {
        Schema::table('audiobook_chapters', function (Blueprint $table) {
            $table->dropColumn('audio_boosted_at');
        });
    }
};
