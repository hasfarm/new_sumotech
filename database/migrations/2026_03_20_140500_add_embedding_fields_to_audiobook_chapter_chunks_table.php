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
        Schema::table('audiobook_chapter_chunks', function (Blueprint $table) {
            if (!Schema::hasColumn('audiobook_chapter_chunks', 'embedding_status')) {
                $table->string('embedding_status')->default('pending')->after('status');
            }

            if (!Schema::hasColumn('audiobook_chapter_chunks', 'embedded_at')) {
                $table->timestamp('embedded_at')->nullable()->after('embedding_status');
            }

            if (!Schema::hasColumn('audiobook_chapter_chunks', 'qdrant_point_id')) {
                $table->string('qdrant_point_id')->nullable()->after('embedded_at');
            }

            if (!Schema::hasColumn('audiobook_chapter_chunks', 'content_hash')) {
                $table->string('content_hash', 64)->nullable()->after('qdrant_point_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audiobook_chapter_chunks', function (Blueprint $table) {
            $columns = [];

            if (Schema::hasColumn('audiobook_chapter_chunks', 'content_hash')) {
                $columns[] = 'content_hash';
            }

            if (Schema::hasColumn('audiobook_chapter_chunks', 'qdrant_point_id')) {
                $columns[] = 'qdrant_point_id';
            }

            if (Schema::hasColumn('audiobook_chapter_chunks', 'embedded_at')) {
                $columns[] = 'embedded_at';
            }

            if (Schema::hasColumn('audiobook_chapter_chunks', 'embedding_status')) {
                $columns[] = 'embedding_status';
            }

            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
