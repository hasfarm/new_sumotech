<?php

namespace App\Jobs;

use App\Models\AudioBook;
use App\Models\AudiobookStoryBible;
use App\Services\StoryBibleAnalysisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reads an entire audiobook (all chapters) and produces a Story Bible + Character Bibles —
 * must run before scene splitting/shot enrichment so Phase 3 can ground each scene's
 * character/location/timeline assignment in a canonical roster instead of each chunk
 * re-inventing its own description (see StoryBibleAnalysisService for the map-reduce and
 * claim-normalization mechanics).
 *
 * Never deletes the currently-`active` bible before a new version proves out: a fresh
 * `draft` row is built (batches ledger-tracked exactly like EnrichVideoShotsJob's
 * shot_chunks — resumable, per-batch retry with backoff, one HTTP call per attempt,
 * guaranteed api_usages logging), validated, and only then atomically activated in a
 * transaction. Any failure leaves the previous active version — and all its
 * timelines/locations/characters/phases — completely untouched.
 */
class AnalyzeStoryDirectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries = 1;

    private const RETRY_DELAYS = [5, 15, 45];

    public function __construct(
        public readonly int $audioBookId,
        public readonly bool $force = false
    ) {}

    public function handle(StoryBibleAnalysisService $service): void
    {
        $audioBook = AudioBook::find($this->audioBookId);
        if (!$audioBook) {
            Log::error('AnalyzeStoryDirectionJob: audiobook not found', ['audio_book_id' => $this->audioBookId]);
            return;
        }

        $activeBible = AudiobookStoryBible::where('audio_book_id', $this->audioBookId)->where('is_active', true)->first();
        if ($activeBible && !$this->force) {
            return; // idempotent: an active bible already exists and reuse was not overridden
        }

        $chapters = $audioBook->chapters;
        if ($chapters->isEmpty()) {
            Log::warning('AnalyzeStoryDirectionJob: no chapters to analyze', ['audio_book_id' => $this->audioBookId]);
            return;
        }
        $chaptersById = $chapters->keyBy('id')->all();

        $nextVersion = (int) (AudiobookStoryBible::where('audio_book_id', $this->audioBookId)->max('bible_version') ?? 0) + 1;
        $draft = AudiobookStoryBible::create([
            'audio_book_id' => $this->audioBookId,
            'bible_version' => $nextVersion,
            'schema_version' => StoryBibleAnalysisService::SCHEMA_VERSION,
            'status' => 'extracting',
            'is_active' => false,
        ]);

        try {
            $batches = $service->buildChapterBatches($chapters);
            if (empty($batches)) {
                throw new \RuntimeException('Không có nội dung chương nào để phân tích.');
            }

            $ledger = collect($batches)->map(fn($b) => [
                'index' => $b['index'],
                'chapter_ids' => $b['chapter_ids'],
                'status' => 'pending',
                'attempts' => 0,
                'error' => null,
            ])->all();
            $draft->update(['batches' => $ledger, 'total_batches' => count($batches), 'processed_batches' => 0]);

            $rawFacts = [];
            $processed = 0;

            foreach ($batches as $batch) {
                $lastError = null;
                $success = false;
                $attempts = 0;
                $facts = null;

                foreach (array_merge([0], self::RETRY_DELAYS) as $delay) {
                    if ($delay > 0) {
                        sleep($delay);
                    }
                    $attempts++;

                    try {
                        $facts = $service->extractBatchFacts($batch, [
                            'book_id' => $this->audioBookId,
                            'chunk_index' => $batch['index'],
                            'job_attempt' => $attempts,
                        ]);
                        $success = true;
                        break;
                    } catch (\Throwable $e) {
                        $lastError = $e->getMessage();
                        Log::warning('AnalyzeStoryDirectionJob: batch attempt failed', [
                            'audio_book_id' => $this->audioBookId,
                            'batch' => $batch['index'],
                            'attempt' => $attempts,
                            'error_type' => get_class($e),
                            'error' => $lastError,
                        ]);
                    }
                }

                // A batch that ultimately fails does not abort the whole analysis — it's
                // recorded in the ledger and simply contributes no facts to the reduce step,
                // mirroring EnrichVideoShotsJob's "one bad chunk doesn't sink the run" ethos.
                $rawFacts[$batch['index']] = $success ? $facts : null;
                $processed++;
                $this->updateBatchStatus($draft, $batch['index'], $success ? 'done' : 'failed', $attempts, $lastError);
                $draft->update(['raw_facts' => $rawFacts, 'processed_batches' => $processed]);
            }

            $batchResults = collect($rawFacts)->map(fn($facts, $index) => ['index' => $index, 'facts' => $facts])->values()->all();
            if (collect($batchResults)->every(fn($b) => empty($b['facts']))) {
                throw new \RuntimeException('Không trích xuất được dữ liệu nào từ tác phẩm (mọi batch đều lỗi).');
            }

            $draft->update(['status' => 'consolidating']);
            $reduced = $service->reduceStoryBible($batchResults, $audioBook, [
                'book_id' => $this->audioBookId,
                'job_attempt' => 1,
            ]);

            $draft->update(['status' => 'validating']);
            $validated = $service->normalizeReducedBible($reduced, $chaptersById);

            DB::transaction(function () use ($draft, $validated, $service, $activeBible) {
                $service->persistBibleChildren($draft, $validated);

                $draft->update([
                    'status' => 'active',
                    'is_active' => true,
                    'source_facts' => $validated['source_facts'],
                    'director_treatment' => $validated['director_treatment'],
                    'activated_at' => now(),
                ]);

                // Only deactivate/delete the previous version AFTER the new one is fully
                // persisted and marked active within this same transaction — if anything
                // above throws, this never runs and the previous version stays exactly as
                // it was.
                if ($activeBible) {
                    $activeBible->update(['is_active' => false, 'status' => 'superseded']);
                    $activeBible->delete(); // cascades to its timelines/locations/characters/phases
                }
            });
        } catch (\Throwable $e) {
            Log::error('AnalyzeStoryDirectionJob failed', [
                'audio_book_id' => $this->audioBookId,
                'bible_version' => $nextVersion,
                'error' => $e->getMessage(),
            ]);
            $draft->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            // Deliberately no further action: the previously-active bible (if any) was never
            // touched, so it remains fully active and intact.
        }
    }

    private function updateBatchStatus(AudiobookStoryBible $draft, int $index, string $status, int $attempts, ?string $error): void
    {
        $draft->refresh();
        $batches = $draft->batches ?? [];

        foreach ($batches as &$batch) {
            if ($batch['index'] === $index) {
                $batch['status'] = $status;
                $batch['attempts'] = $attempts;
                $batch['error'] = $error;
                break;
            }
        }
        unset($batch);

        $draft->update(['batches' => $batches]);
    }
}
