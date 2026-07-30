<?php

namespace App\Console\Commands;

use App\Jobs\AnalyzeStoryDirectionJob;
use App\Jobs\EnrichVideoShotsJob;
use App\Jobs\SplitVideoScenesJob;
use App\Models\ApiUsage;
use App\Models\AudioBook;
use App\Models\AudiobookStoryBible;
use App\Models\AudiobookSummary;
use App\Services\AudiobookSummaryService;
use Illuminate\Console\Command;

/**
 * Orchestrates the REAL end-to-end pipeline for a real, already-imported AudioBook: Bước 1
 * (cluster) -> Bước 2/3 per cluster (timeline/retell) -> outro -> Story Bible (Phase 2) ->
 * Stage A scene splitting + Phase 3 binding -> Stage B shot enrichment. Every step is
 * idempotent (skips work already done) so this is safe to re-run.
 *
 * Forces the queue driver to `sync` for the duration of this process only (not persisted to
 * .env) — the real app runs on `QUEUE_CONNECTION=database`, so a dispatch() call here would
 * otherwise just enqueue a row and return immediately instead of actually running anything.
 */
class RunRealPipelineEndToEnd extends Command
{
    protected $signature = 'pipeline:run-real {audioBookId}
        {--skip-summary : Skip Bước 1/2/3 (assumes AudiobookSummary already has clusters/retells)}
        {--skip-bible : Skip Story Bible generation (assumes an active bible already exists)}
        {--skip-scenes : Skip Stage A scene splitting}
        {--skip-shots : Skip Stage B shot enrichment}';

    protected $description = 'Run the real Bước1-3 + Story Bible + Stage A/B pipeline end-to-end for one real AudioBook';

    public function handle(AudiobookSummaryService $summaryService): int
    {
        config(['queue.default' => 'sync']);

        $audioBookId = (int) $this->argument('audioBookId');
        $audioBook = AudioBook::with('chapters')->find($audioBookId);
        if (!$audioBook || $audioBook->chapters->isEmpty()) {
            $this->error("AudioBook #{$audioBookId} không tồn tại hoặc chưa có chương.");
            return self::FAILURE;
        }

        $this->info("=== AudioBook #{$audioBookId}: {$audioBook->title} ===");
        $this->info($audioBook->chapters->count() . ' chương, ' . number_format($audioBook->chapters->sum(fn($c) => mb_strlen($c->content))) . ' ký tự.');

        if (!$this->option('skip-summary')) {
            $this->runSummaryPipeline($audioBook, $summaryService);
        }

        if (!$this->option('skip-bible')) {
            $this->timedStep('Phase 2: Story Bible (AnalyzeStoryDirectionJob)', function () use ($audioBookId) {
                $active = AudiobookStoryBible::where('audio_book_id', $audioBookId)->where('is_active', true)->first();
                if ($active) {
                    $this->line('  (đã có active Story Bible v' . $active->bible_version . ', bỏ qua — dùng --force qua tinker nếu muốn tạo lại)');
                    return;
                }
                AnalyzeStoryDirectionJob::dispatch($audioBookId);
            });
        }

        if (!$this->option('skip-scenes')) {
            $this->timedStep('Stage A: SplitVideoScenesJob (scene splitting + Phase 3 binding)', function () use ($audioBookId) {
                SplitVideoScenesJob::dispatch($audioBookId);
            });
        }

        if (!$this->option('skip-shots')) {
            $this->timedStep('Stage B: EnrichVideoShotsJob (shot enrichment)', function () use ($audioBookId) {
                EnrichVideoShotsJob::dispatch($audioBookId);
            });
        }

        $this->info('=== HOÀN TẤT ===');
        $this->line("Chạy tiếp continuity validation: php artisan tinker --execute=\"(new App\\Jobs\\ValidateStoryContinuityJob({$audioBookId}))->handle(app(App\\Services\\ContinuityValidationService::class));\"");

        return self::SUCCESS;
    }

    private function runSummaryPipeline(AudioBook $audioBook, AudiobookSummaryService $service): void
    {
        $summary = $audioBook->summary;

        if (!$summary || empty($summary->clusters)) {
            $this->timedStep('Bước 1: cluster chapters (Gemini)', function () use ($audioBook) {
                AudiobookSummary::updateOrCreate(
                    ['audio_book_id' => $audioBook->id],
                    ['status' => 'queued', 'clusters' => [], 'timelines' => [], 'retells' => [], 'outro' => null, 'processed_batches' => 0, 'error_message' => null]
                );
                \App\Jobs\GenerateAudiobookSummaryClustersJob::dispatch($audioBook->id);
            });
            $summary = $audioBook->summary()->first()->fresh();
        } else {
            $this->line('Bước 1: đã có ' . count($summary->clusters) . ' cluster, bỏ qua.');
        }

        $clusters = collect($summary->clusters)->sortBy('index')->values();
        $minIndex = $clusters->min('index');

        foreach ($clusters as $cluster) {
            $idx = (string) $cluster['index'];
            $summary->refresh();

            if (empty($summary->timelines[$idx] ?? null)) {
                $this->timedStep("Bước 2: timeline cho cluster {$idx} (Gemini)", function () use ($service, $cluster, $summary, $idx) {
                    $rows = $service->generateTimelineForCluster($cluster);
                    $timelines = $summary->timelines ?? [];
                    $timelines[$idx] = $rows;
                    $summary->update(['timelines' => $timelines]);
                });
                $summary->refresh();
            } else {
                $this->line("Bước 2: cluster {$idx} đã có timeline, bỏ qua.");
            }

            if (empty($summary->retells[$idx] ?? null)) {
                $this->timedStep("Bước 3: retell cho cluster {$idx} (Claude)", function () use ($service, $audioBook, $cluster, $summary, $idx, $minIndex) {
                    $levels = AudiobookSummaryService::levels();
                    $levelInfo = $levels[$summary->target_level ?: 'detailed'];
                    $text = $service->generateRetellForCluster(
                        $audioBook,
                        $cluster,
                        $summary->timelines[$idx] ?? [],
                        $cluster['index'] === $minIndex,
                        $levelInfo['min'],
                        $levelInfo['max']
                    );
                    $retells = $summary->retells ?? [];
                    $retells[$idx] = $text;
                    $summary->update(['retells' => $retells]);
                });
                $summary->refresh();
            } else {
                $this->line("Bước 3: cluster {$idx} đã có retell, bỏ qua.");
            }
        }

        $summary->refresh();
        if (empty($summary->outro)) {
            $maxIndex = (string) $clusters->max('index');
            $lastRetell = (string) ($summary->retells[$maxIndex] ?? '');
            if ($lastRetell !== '') {
                $this->timedStep('Outro (Claude)', function () use ($service, $audioBook, $summary, $lastRetell) {
                    $outro = $service->generateOutro($audioBook, $lastRetell);
                    $summary->update(['outro' => $outro]);
                });
            }
        } else {
            $this->line('Outro: đã có, bỏ qua.');
        }
    }

    private function timedStep(string $label, \Closure $fn): void
    {
        $this->info("--- {$label} ---");
        $beforeUsageId = ApiUsage::max('id') ?? 0;
        $start = microtime(true);

        try {
            $fn();
        } catch (\Throwable $e) {
            $this->error('  LỖI: ' . $e->getMessage());
            throw $e;
        }

        $seconds = round(microtime(true) - $start, 2);
        $usages = ApiUsage::where('id', '>', $beforeUsageId)->get();
        $tokens = $usages->sum('tokens_used');
        $cost = $usages->sum('estimated_cost');

        $this->line("  {$seconds}s" . ($usages->isNotEmpty() ? " | {$usages->count()} api_usages call(s), {$tokens} tokens, \${$cost}" : ' | (không có api_usages log cho bước này — Gemini/Claude calls không log usage trong codebase hiện tại)'));
    }
}
