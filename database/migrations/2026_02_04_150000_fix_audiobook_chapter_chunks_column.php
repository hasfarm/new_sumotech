<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Just rename column if it has old name
        if (Schema::hasColumn('audiobook_chapter_chunks', 'audiobook_chapter_id')) {
            Schema::table('audiobook_chapter_chunks', function (Blueprint $table) {
                try {
                    DB::statement('ALTER TABLE audiobook_chapter_chunks DROP FOREIGN KEY audiobook_chapter_chunks_audiobook_chapter_id_foreign');
                } catch (\Throwable $e) {
                    // Ignore if foreign key does not exist
                }
                DB::statement('ALTER TABLE audiobook_chapter_chunks CHANGE audiobook_chapter_id audiobook_chapter_id BIGINT UNSIGNED');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audiobook_chapter_chunks', function (Blueprint $table) {
            try {
                $table->dropForeign(['audiobook_chapter_id']);
            } catch (\Exception $e) {
                // Ignore
            }

            if (Schema::hasColumn('audiobook_chapter_chunks', 'audiobook_chapter_id')) {
                $table->renameColumn('audiobook_chapter_id', 'audiobook_chapter_id');
            }
        });
    }
};