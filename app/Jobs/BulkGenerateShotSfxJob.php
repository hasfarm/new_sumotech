<?php

namespace App\Jobs;

use App\Models\AudiobookVideoPipeline;
use App\Models\AudiobookVideoShot;
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
 * "✨ Tạo tất cả" for shot-level SFX — bulk-fills every shot flagged needs_sfx=true whose
 * sfx_status is still pending/rejected. Same resumable/progress-tracked shape and search-first
 * discipline as BulkGenerateSceneAmbienceJob/BulkGenerateSceneMusicJob.
 */
class BulkGenerateShotSfxJob implements ShouldQueue, ShouldBeUnique
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

        $current = $pipeline->bulk_shot_sfx_status;
        if ($current && ($current['status'] ?? null) === 'running') {
            return;
        }

        $contextHint = (string) $pipeline->context_hint;
        $total = $this->pendingShotsQuery()->count();

        $pipeline->update(['bulk_shot_sfx_status' => [
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
            $query = $this->pendingShotsQuery();
            if (!empty($failedThisRun)) {
                $query->whereNotIn('id', $failedThisRun);
            }
            $shot = $query->orderBy('id')->first();

            if (!$shot) {
                break;
            }

            try {
                $match = $audioLibrary->findMatch('sfx', $shot->sfx_prompt, $shot->sfx_keywords ?? [], $shot->estimated_duration_seconds, $contextHint);
                if ($match) {
                    $audioLibrary->recordReuse($match['asset']);
                    $asset = $match['asset'];
                } else {
                    $asset = $audioLibrary->generateAndArchive(
                        'sfx',
                        $shot->sfx_prompt,
                        $shot->sfx_keywords ?? [],
                        $shot->estimated_duration_seconds,
                        $this->audioBookId,
                        VideoSceneAnalysisService::AUDIO_PROMPT_VERSION
                    );
                }

                $shot->update(['sfx_asset_id' => $asset->id, 'sfx_status' => 'generated']);
                $succeeded++;
            } catch (\Throwable $e) {
                $failedThisRun[] = $shot->id;
                $failed++;
                Log::warning('BulkGenerateShotSfxJob: shot failed', [
                    'audio_book_id' => $this->audioBookId,
                    'shot_id' => $shot->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $processed++;
            $pipeline->refresh();
            $pipeline->update(['bulk_shot_sfx_status' => array_merge($pipeline->bulk_shot_sfx_status ?? [], [
                'processed' => $processed,
                'succeeded' => $succeeded,
                'failed' => $failed,
                'last_progress_at' => now()->toIso8601String(),
            ])]);
        }

        $pipeline->refresh();
        $pipeline->update(['bulk_shot_sfx_status' => array_merge($pipeline->bulk_shot_sfx_status ?? [], [
            'status' => 'done',
            'last_progress_at' => now()->toIso8601String(),
        ])]);
    }

    private function pendingShotsQuery()
    {
        return AudiobookVideoShot::whereHas('scene', fn ($q) => $q->where('audio_book_id', $this->audioBookId))
            ->where('needs_sfx', true)
            ->where(function ($q) {
                $q->whereNull('sfx_status')->orWhereIn('sfx_status', ['pending', 'rejected']);
            });
    }
}
