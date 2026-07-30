<?php

namespace App\Jobs;

use App\Models\AudioBook;
use App\Models\AudiobookStoryBible;
use App\Models\AudiobookSummary;
use App\Models\AudiobookVideoPipeline;
use App\Models\AudiobookVideoScene;
use App\Services\VideoSceneAnalysisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Stage A of the split video-analysis pipeline (see happy-cuddling-bonbon.md plan): classify
 * scene batches ONLY (title/scene_type/is_emotional_climax) — no shot enumeration, which is
 * what made the old monolithic AnalyzeVideoScriptJob call slow/timeout-prone. Dispatches
 * EnrichVideoShotsJob (Stage B) when done.
 */
class SplitVideoScenesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 1;

    public function __construct(
        public readonly int $audioBookId,
        public readonly ?string $versionId = null
    ) {}

    public function handle(VideoSceneAnalysisService $service): void
    {
        $audioBook = AudioBook::find($this->audioBookId);
        $pipeline = AudiobookVideoPipeline::firstOrCreate(['audio_book_id' => $this->audioBookId]);

        if (!$audioBook) {
            $pipeline->update(['status' => 'failed', 'error_message' => 'Không tìm thấy audiobook.']);
            return;
        }

        $summary = $audioBook->summary;
        if (!$summary instanceof AudiobookSummary || empty($summary->clusters)) {
            $pipeline->update(['status' => 'failed', 'error_message' => 'Sách chưa có kịch bản tóm tắt (Bước 3) để phân tích.']);
            return;
        }

        // "Bắt đầu phân tích" is always an explicit fresh start — clears any previous run's
        // scenes/shots (cascades to shots via FK). Resumability for the expensive part
        // (shot enrichment) lives in EnrichVideoShotsJob's per-chunk progress tracking, not
        // here.
        AudiobookVideoScene::where('audio_book_id', $this->audioBookId)->delete();

        $pipeline->update([
            'status' => 'analyzing',
            'current_stage' => 'scene_splitting',
            'source_version_id' => $this->versionId,
            'total_batches' => null,
            'processed_batches' => 0,
            'error_message' => null,
            'shot_chunks' => null,
            'last_heartbeat_at' => now(),
        ]);

        try {
            $segments = $service->resolveScriptSegments($summary, $this->versionId);
            $batches = $service->buildSceneBatches($segments);

            if (empty($batches)) {
                $pipeline->update(['status' => 'failed', 'error_message' => 'Không có nội dung kịch bản để tạo cảnh.']);
                return;
            }

            $pipeline->update(['total_batches' => count($batches), 'processed_batches' => 0]);

            // Determine the book's cultural/historical/geographic setting ONCE, so every
            // scene's keyword generation stays consistent.
            $contextHint = $service->determineContext($audioBook);
            $pipeline->update(['context_hint' => $contextHint, 'last_heartbeat_at' => now()]);

            // Backward-compatible: a book with no active Story Bible (Phase 2 never run, or
            // not yet built for this book) skips scene-context assignment entirely — every
            // new column stays null and the pipeline behaves exactly as before Phase 3.
            $activeBible = AudiobookStoryBible::where('audio_book_id', $this->audioBookId)->where('is_active', true)->first();

            // Scene-meta calls are tiny now (~2s each measured live) — sequential with a
            // heartbeat per batch is simple and already fast; pooling isn't needed here.
            $processed = 0;
            foreach ($batches as $i => $batch) {
                $meta = $service->analyzeSceneMeta($batch['text'], $audioBook);
                $duration = $service->estimateDurationSeconds($batch['text'], 60);

                $scene = AudiobookVideoScene::updateOrCreate(
                    ['audio_book_id' => $this->audioBookId, 'scene_index' => $i + 1],
                    [
                        'audiobook_summary_id' => $summary->id,
                        'source_version_id' => $this->versionId,
                        'cluster_index' => $batch['cluster_index'],
                        'title' => $meta['title'],
                        'script_text' => $batch['text'],
                        'estimated_duration_seconds' => $duration,
                        'scene_type' => $meta['scene_type'],
                        'is_avatar_segment' => false,
                        'is_emotional_climax' => $meta['is_emotional_climax'],
                        'status' => 'analyzed',
                    ]
                );

                if ($activeBible) {
                    $assignment = $service->assignSceneContext($scene, $activeBible, [
                        'book_id' => $this->audioBookId,
                        'scene_id' => $scene->id,
                    ]);
                    $service->persistSceneContext($scene, $activeBible, $assignment);
                }

                $processed++;
                $pipeline->update(['processed_batches' => $processed, 'last_heartbeat_at' => now()]);
            }

            $pipeline->update(['current_stage' => 'shot_enrichment', 'last_heartbeat_at' => now()]);

            EnrichVideoShotsJob::dispatch($this->audioBookId);
        } catch (\Throwable $e) {
            Log::error('SplitVideoScenesJob failed', [
                'audio_book_id' => $this->audioBookId,
                'error' => $e->getMessage(),
            ]);
            $pipeline->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
        }
    }
}
