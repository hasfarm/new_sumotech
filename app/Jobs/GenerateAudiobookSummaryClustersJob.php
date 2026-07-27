<?php

namespace App\Jobs;

use App\Models\AudioBook;
use App\Models\AudiobookSummary;
use App\Services\AudiobookSummaryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateAudiobookSummaryClustersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries = 1;

    public function __construct(public readonly int $audioBookId) {}

    public function handle(AudiobookSummaryService $service): void
    {
        $audioBook = AudioBook::with('chapters')->find($this->audioBookId);
        $summary = AudiobookSummary::firstOrCreate(['audio_book_id' => $this->audioBookId]);

        if (!$audioBook) {
            $summary->update(['status' => 'failed', 'error_message' => 'Không tìm thấy audiobook.']);
            return;
        }

        $summary->update([
            'status' => 'clustering',
            'clusters' => [],
            'timelines' => [],
            'retells' => [],
            'outro' => null,
            'error_message' => null,
        ]);

        try {
            $batches = $service->buildBatches($audioBook->chapters);

            if (empty($batches)) {
                $summary->update(['status' => 'failed', 'error_message' => 'Sách chưa có nội dung chương để tóm tắt.']);
                return;
            }

            $summary->update(['total_batches' => count($batches), 'processed_batches' => 0]);

            $fineClusters = $service->splitBatchesIntoClusters($batches, $audioBook, function (int $processed, array $clustersSoFar) use ($summary) {
                $summary->update(['processed_batches' => $processed, 'clusters' => $clustersSoFar]);
            });

            $summary->update(['status' => 'consolidating']);
            $majorClusters = $service->consolidateClusters($fineClusters, $audioBook);

            $summary->update(['status' => 'clustered', 'clusters' => $majorClusters]);
        } catch (\Throwable $e) {
            Log::error('GenerateAudiobookSummaryClustersJob failed', [
                'audio_book_id' => $this->audioBookId,
                'error' => $e->getMessage(),
            ]);
            $summary->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
        }
    }
}
