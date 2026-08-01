<?php

namespace App\Jobs;

use App\Models\AudiobookVideoPipeline;
use App\Models\AudiobookVideoScene;
use App\Services\AudioAssetLibraryService;
use App\Services\VideoSceneAnalysisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * "✨ Tạo tất cả" for scene-level MUSIC baselines — mirrors BulkGenerateSceneAmbienceJob
 * exactly, just for needs_music/music_status instead of ambience.
 */
class BulkGenerateSceneMusicJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 10800;
    public int $tries = 1;
    public int $uniqueFor = 10800;

    public function __construct(public readonly int $audioBookId) {}

    public function uniqueId(): string
    {
        return (string) $this->audioBookId;
    }

    public function handle(AudioAssetLibraryService $audioLibrary): void
    {
        $pipeline = AudiobookVideoPipeline::firstOrCreate(['audio_book_id' => $this->audioBookId]);

        $current = $pipeline->bulk_scene_music_status;
        if ($current && ($current['status'] ?? null) === 'running') {
            return;
        }

        $contextHint = (string) $pipeline->context_hint;
        $total = $this->pendingScenesQuery()->count();

        $pipeline->update(['bulk_scene_music_status' => [
            'status' => 'running',
            'total' => $total,
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'started_at' => now()->toIso8601String(),
            'last_progress_at' => now()->toIso8601String(),
            'last_error' => null,
        ]]);

        $processed = 0;
        $succeeded = 0;
        $failed = 0;
        $failedThisRun = [];

        while (true) {
            $query = $this->pendingScenesQuery();
            if (!empty($failedThisRun)) {
                $query->whereNotIn('id', $failedThisRun);
            }
            $scene = $query->orderBy('scene_index')->first();

            if (!$scene) {
                break;
            }

            try {
                $match = $audioLibrary->findMatch('music', $scene->music_prompt, $scene->music_keywords ?? [], null, $contextHint);
                if ($match) {
                    $audioLibrary->recordReuse($match['asset']);
                    $asset = $match['asset'];
                } else {
                    $asset = $audioLibrary->generateAndArchive(
                        'music',
                        $scene->music_prompt,
                        $scene->music_keywords ?? [],
                        null,
                        $this->audioBookId,
                        VideoSceneAnalysisService::AUDIO_PROMPT_VERSION
                    );
                }

                $scene->update(['music_asset_id' => $asset->id, 'music_status' => 'generated']);
                $succeeded++;
            } catch (\Throwable $e) {
                $failedThisRun[] = $scene->id;
                $failed++;
                Log::warning('BulkGenerateSceneMusicJob: scene failed', [
                    'audio_book_id' => $this->audioBookId,
                    'scene_id' => $scene->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $processed++;
            $pipeline->refresh();
            $pipeline->update(['bulk_scene_music_status' => array_merge($pipeline->bulk_scene_music_status ?? [], [
                'processed' => $processed,
                'succeeded' => $succeeded,
                'failed' => $failed,
                'last_progress_at' => now()->toIso8601String(),
            ])]);
        }

        $pipeline->refresh();
        $pipeline->update(['bulk_scene_music_status' => array_merge($pipeline->bulk_scene_music_status ?? [], [
            'status' => 'done',
            'last_progress_at' => now()->toIso8601String(),
        ])]);
    }

    private function pendingScenesQuery()
    {
        return AudiobookVideoScene::where('audio_book_id', $this->audioBookId)
            ->where('needs_music', true)
            ->where(function ($q) {
                $q->whereNull('music_status')->orWhereIn('music_status', ['pending', 'rejected']);
            });
    }
}
