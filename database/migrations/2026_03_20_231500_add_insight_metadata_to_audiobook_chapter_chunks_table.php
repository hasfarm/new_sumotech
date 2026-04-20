<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audiobook_chapter_chunks', function (Blueprint $table) {
            if (!Schema::hasColumn('audiobook_chapter_chunks', 'book_id')) {
                $table->unsignedBigInteger('book_id')->nullable()->after('chunk_number');
                $table->index('book_id');
            }

            if (!Schema::hasColumn('audiobook_chapter_chunks', 'chapter_id')) {
                $table->unsignedBigInteger('chapter_id')->nullable()->after('book_id');
                $table->index('chapter_id');
            }

            if (!Schema::hasColumn('audiobook_chapter_chunks', 'chunk_index')) {
                $table->unsignedInteger('chunk_index')->nullable()->after('chapter_id');
                $table->index('chunk_index');
            }

            if (!Schema::hasColumn('audiobook_chapter_chunks', 'character_tags')) {
                $table->json('character_tags')->nullable()->after('content_hash');
            }

            if (!Schema::hasColumn('audiobook_chapter_chunks', 'scene_type')) {
                $table->string('scene_type', 50)->nullable()->after('character_tags');
                $table->index('scene_type');
            }

            if (!Schema::hasColumn('audiobook_chapter_chunks', 'topic_tags')) {
                $table->json('topic_tags')->nullable()->after('scene_type');
            }

            if (!Schema::hasColumn('audiobook_chapter_chunks', 'importance_score')) {
                $table->decimal('importance_score', 5, 4)->nullable()->after('topic_tags');
                $table->index('importance_score');
            }
        });
    }

    public function down(): void
    {
        Schema::table('audiobook_chapter_chunks', function (Blueprint $table) {
            if (Schema::hasColumn('audiobook_chapter_chunks', 'importance_score')) {
                $table->dropIndex(['importance_score']);
                $table->dropColumn('importance_score');
            }

            if (Schema::hasColumn('audiobook_chapter_chunks', 'topic_tags')) {
                $table->dropColumn('topic_tags');
            }

            if (Schema::hasColumn('audiobook_chapter_chunks', 'scene_type')) {
                $table->dropIndex(['scene_type']);
                $table->dropColumn('scene_type');
            }

            if (Schema::hasColumn('audiobook_chapter_chunks', 'character_tags')) {
                $table->dropColumn('character_tags');
            }

            if (Schema::hasColumn('audiobook_chapter_chunks', 'chunk_index')) {
                $table->dropIndex(['chunk_index']);
                $table->dropColumn('chunk_index');
            }

            if (Schema::hasColumn('audiobook_chapter_chunks', 'chapter_id')) {
                $table->dropIndex(['chapter_id']);
                $table->dropColumn('chapter_id');
            }

            if (Schema::hasColumn('audiobook_chapter_chunks', 'book_id')) {
                $table->dropIndex(['book_id']);
                $table->dropColumn('book_id');
            }
        });
    }
};
