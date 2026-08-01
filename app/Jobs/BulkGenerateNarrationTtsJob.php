<?php

namespace App\Jobs;

use App\Models\AudiobookVideoPipeline;
use App\Models\AudiobookVideoShot;
use App\Services\ShotNarrationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * "Create All" next to the main narration voice picker — bulk-generates TTS for every
 * non-avatar shot that doesn't have narration_audio_path yet, using the pipeline's main
 * voice settings. Same resumable/progress-tracked shape as BulkGenerateShotImagesJob (runs
 * in the background, survives tab close/reload — the page just polls status() as usual).
 */
class BulkGenerateNarrationTtsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 10800;
    public int $tries = 1;
    // Must be >= $timeout — see BulkGenerateShotImagesJob::$uniqueFor for why.
    public int $uniqueFor = 10800;

    public function __construct(public readonly int $audioBookId) {}

    /** See BulkGenerateShotImagesJob::uniqueId() — same rationale, prevents a second
     * concurrent instance of this exact job (per audiobook) from ever starting. */
    public function uniqueId(): string
    {
        return (string) $this->audioBookId;
    }

    public function handle(ShotNarrationService $narrationService): void
    {
        $pipeline = AudiobookVideoPipeline::firstOrCreate(['audio_book_id' => $this->audioBookId]);

        $current = $pipeline->bulk_narration_tts_status;
        if ($current && ($current['status'] ?? null) === 'running') {
            return;
        }

        $total = $this->pendingShotsQuery()->count();

        $pipeline->update(['bulk_narration_tts_status' => [
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
        // broken shot can't spin the loop forever — a fresh dispatch later will retry them
        // since narration_audio_path is still null.
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
                $narrationService->generateNarrationTts($shot);
                $succeeded++;
            } catch (\Throwable $e) {
                $failedThisRun[] = $shot->id;
                $failed++;
                Log::warning('BulkGenerateNarrationTtsJob: shot failed', [
                    'audio_book_id' => $this->audioBookId,
                    'shot_id' => $shot->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $processed++;
            $pipeline->refresh();
            $pipeline->update(['bulk_narration_tts_status' => array_merge($pipeline->bulk_narration_tts_status ?? [], [
                'processed' => $processed,
                'succeeded' => $succeeded,
                'failed' => $failed,
                'last_progress_at' => now()->toIso8601String(),
            ])]);
        }

        $pipeline->refresh();
        $pipeline->update(['bulk_narration_tts_status' => array_merge($pipeline->bulk_narration_tts_status ?? [], [
            'status' => 'done',
            'last_progress_at' => now()->toIso8601String(),
        ])]);
    }

    private function pendingShotsQuery()
    {
        return AudiobookVideoShot::whereHas('scene', fn($q) => $q->where('audio_book_id', $this->audioBookId))
            ->where('is_avatar_segment', false)
            ->whereNull('narration_audio_path');
    }
}
