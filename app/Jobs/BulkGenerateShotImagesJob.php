<?php

namespace App\Jobs;

use App\Exceptions\ContentModerationException;
use App\Models\AudiobookVideoPipeline;
use App\Models\AudiobookVideoShot;
use App\Services\SceneAssetResolverService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * "Tự động tạo bằng AI": force-generates an AI still image for every non-avatar shot not
 * yet 'ready'/'image_ready', one at a time. This used to be a purely client-side JS while
 * loop (vpAutoRunAI in video-pipeline.blade.php) — it had no server-side memory of being
 * "in progress" at all, so closing the tab, navigating away, or the machine sleeping mid-run
 * silently killed it with zero trace: the page just showed the same unprocessed backlog on
 * reload, indistinguishable from the feature never having worked. Moving the loop into a
 * queued job (same shape as EnrichVideoShotsJob/SplitVideoScenesJob) makes it survive all of
 * that; progress lives in the pipeline row so the page can just poll status() as usual.
 */
class BulkGenerateShotImagesJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 10800;
    public int $tries = 1;
    // Must be >= $timeout — Laravel's unique-job cache lock is otherwise released (allowing
    // a genuine duplicate to start) before this job could possibly still be legitimately
    // running its full course.
    public int $uniqueFor = 10800;

    public function __construct(public readonly int $audioBookId) {}

    /**
     * Laravel's own unique-job lock (via the cache driver's atomic Cache::lock(), which
     * works across separate OS processes with the 'file' cache driver too) — this is the
     * real fix for a race the manual "check bulk_*_status === 'running'" guard below can't
     * close on its own: two near-simultaneous dispatches (e.g. the queue's retry_after
     * re-releasing a still-running long job back onto the queue for a second worker to pick
     * up) could both read the status field as "not running" before either had written
     * 'running'. With this, a second instance is refused outright while one is active, for
     * the same audiobook.
     */
    public function uniqueId(): string
    {
        return (string) $this->audioBookId;
    }

    public function handle(SceneAssetResolverService $resolverService): void
    {
        $pipeline = AudiobookVideoPipeline::firstOrCreate(['audio_book_id' => $this->audioBookId]);

        $current = $pipeline->bulk_ai_generate_status;
        if ($current && ($current['status'] ?? null) === 'running') {
            return; // another dispatch of this job is already working through the backlog
        }

        $total = $this->pendingShotsQuery()->count();

        $pipeline->update(['bulk_ai_generate_status' => [
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
        // Shots that failed THIS run are excluded from re-selection so one permanently-
        // broken shot can't spin the loop forever — everything else is re-queried fresh
        // each iteration (not a fixed pre-built list), so a shot resolved manually by the
        // user mid-run is simply not picked up again.
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
                $resolverService->resolveShotOrThrow($shot, true);
                $succeeded++;
            } catch (\Throwable $e) {
                $status = $e instanceof ContentModerationException ? 'content_blocked' : 'failed';
                $shot->update(['status' => $status, 'error_message' => $e->getMessage()]);
                $failedThisRun[] = $shot->id;
                $failed++;
                Log::warning('BulkGenerateShotImagesJob: shot failed', [
                    'audio_book_id' => $this->audioBookId,
                    'shot_id' => $shot->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $processed++;
            $pipeline->refresh();
            $pipeline->update(['bulk_ai_generate_status' => array_merge($pipeline->bulk_ai_generate_status ?? [], [
                'processed' => $processed,
                'succeeded' => $succeeded,
                'failed' => $failed,
                'last_progress_at' => now()->toIso8601String(),
            ])]);
        }

        $pipeline->refresh();
        $pipeline->update(['bulk_ai_generate_status' => array_merge($pipeline->bulk_ai_generate_status ?? [], [
            'status' => 'done',
            'last_progress_at' => now()->toIso8601String(),
        ])]);
    }

    private function pendingShotsQuery()
    {
        return AudiobookVideoShot::whereHas('scene', fn($q) => $q->where('audio_book_id', $this->audioBookId))
            ->where('is_avatar_segment', false)
            ->whereNotIn('status', ['ready', 'image_ready']);
    }
}
