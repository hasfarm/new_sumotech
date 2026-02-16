<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audio_books', function (Blueprint $table) {
            $table->integer('wave_width')->nullable()->default(100)->after('wave_height');
        });
    }

    public function down(): void
    {
        Schema::table('audio_books', function (Blueprint $table) {
            $table->dropColumn('wave_width');
        });
    }
};
