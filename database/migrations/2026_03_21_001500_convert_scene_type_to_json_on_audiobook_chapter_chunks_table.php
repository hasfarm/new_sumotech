<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('audiobook_chapter_chunks', 'scene_type')) {
            return;
        }

        // Normalize old scalar scene_type strings into JSON array strings before type change.
        DB::table('audiobook_chapter_chunks')
            ->whereNotNull('scene_type')
            ->where('scene_type', '!=', '')
            ->orderBy('id')
            ->chunkById(1000, function ($rows) {
                foreach ($rows as $row) {
                    $value = trim((string) $row->scene_type);
                    if ($value === '') {
                        continue;
                    }

                    $decoded = json_decode($value, true);
                    if (is_array($decoded)) {
                        continue;
                    }

                    DB::table('audiobook_chapter_chunks')
                        ->where('id', $row->id)
                        ->update(['scene_type' => json_encode([$value], JSON_UNESCAPED_UNICODE)]);
                }
            });

        try {
            DB::statement('ALTER TABLE audiobook_chapter_chunks DROP INDEX audiobook_chapter_chunks_scene_type_index');
        } catch (\Throwable $e) {
            // Ignore if index does not exist.
        }

        DB::statement('ALTER TABLE audiobook_chapter_chunks MODIFY scene_type JSON NULL');
    }

    public function down(): void
    {
        if (!Schema::hasColumn('audiobook_chapter_chunks', 'scene_type')) {
            return;
        }

        DB::statement('ALTER TABLE audiobook_chapter_chunks MODIFY scene_type VARCHAR(50) NULL');

        DB::table('audiobook_chapter_chunks')
            ->whereNotNull('scene_type')
            ->orderBy('id')
            ->chunkById(1000, function ($rows) {
                foreach ($rows as $row) {
                    $value = trim((string) $row->scene_type);
                    if ($value === '') {
                        continue;
                    }

                    $decoded = json_decode($value, true);
                    if (is_array($decoded)) {
                        $first = '';
                        foreach ($decoded as $item) {
                            if (is_string($item) && trim($item) !== '') {
                                $first = trim($item);
                                break;
                            }
                        }

                        DB::table('audiobook_chapter_chunks')
                            ->where('id', $row->id)
                            ->update(['scene_type' => $first !== '' ? $first : null]);
                    }
                }
            });

        DB::statement('ALTER TABLE audiobook_chapter_chunks ADD INDEX audiobook_chapter_chunks_scene_type_index (scene_type)');
    }
};
