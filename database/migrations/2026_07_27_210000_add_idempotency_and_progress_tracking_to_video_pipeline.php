<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Defensive dedup before adding unique constraints — keeps the earliest row per
        // natural key and deletes the rest. No-op if there are no duplicates (verified none
        // exist as of writing this migration, but this makes the migration safe to run
        // again later on a database that does have some).
        $this->dedupe('audiobook_video_scenes', ['audio_book_id', 'scene_index']);
        $this->dedupe('audiobook_video_shots', ['video_scene_id', 'shot_index']);

        // Add the new unique index BEFORE dropping the old one — MySQL refuses to drop an
        // index still backing a foreign key constraint (audio_book_id / video_scene_id),
        // so the old (non-unique) index can only go once the new unique one exists to take
        // over that role.
        Schema::table('audiobook_video_scenes', function (Blueprint $table) {
            $table->unique(['audio_book_id', 'scene_index'], 'video_scenes_book_index_unique');
        });
        Schema::table('audiobook_video_scenes', function (Blueprint $table) {
            $table->dropIndex(['audio_book_id', 'scene_index']);
        });

        Schema::table('audiobook_video_shots', function (Blueprint $table) {
            $table->unique(['video_scene_id', 'shot_index'], 'video_shots_scene_index_unique');
        });
        Schema::table('audiobook_video_shots', function (Blueprint $table) {
            $table->dropIndex('video_shots_scene_index_idx');
        });

        Schema::table('audiobook_video_pipelines', function (Blueprint $table) {
            $table->string('current_stage')->nullable()->after('status');
            $table->timestamp('last_heartbeat_at')->nullable()->after('current_stage');
            $table->json('shot_chunks')->nullable()->after('last_heartbeat_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audiobook_video_pipelines', function (Blueprint $table) {
            $table->dropColumn(['current_stage', 'last_heartbeat_at', 'shot_chunks']);
        });

        Schema::table('audiobook_video_shots', function (Blueprint $table) {
            $table->index(['video_scene_id', 'shot_index'], 'video_shots_scene_index_idx');
        });
        Schema::table('audiobook_video_shots', function (Blueprint $table) {
            $table->dropUnique('video_shots_scene_index_unique');
        });

        Schema::table('audiobook_video_scenes', function (Blueprint $table) {
            $table->index(['audio_book_id', 'scene_index']);
        });
        Schema::table('audiobook_video_scenes', function (Blueprint $table) {
            $table->dropUnique('video_scenes_book_index_unique');
        });
    }

    /**
     * @param array<int,string> $keyColumns
     */
    private function dedupe(string $table, array $keyColumns): void
    {
        $groupBy = implode(', ', $keyColumns);
        $duplicateKeys = DB::table($table)
            ->select($keyColumns)
            ->selectRaw('COUNT(*) as cnt, MIN(id) as keep_id')
            ->groupBy($keyColumns)
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateKeys as $row) {
            $query = DB::table($table);
            foreach ($keyColumns as $col) {
                $query->where($col, $row->$col);
            }
            $query->where('id', '!=', $row->keep_id)->delete();
        }
    }
};
