<?php

namespace App\Jobs;

use App\Models\AudioBook;
use App\Models\AudioBookChapter;
use App\Services\ChapterAudioBoostService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class BoostChapterAudioBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 7200;
    public int $tries = 1;

    public function __construct(
        public readonly int $audioBookId,
        public readonly array $chapterIds,
        public readonly int $db = 16,
    ) {}

    public function handle(): void
    {
        $audioBook = AudioBook::find($this->audioBookId);
        if (!$audioBook) {
            $this->updateProgress('error', 0, 'Không tìm thấy audiobook');
            return;
        }

        $boostService = app(ChapterAudioBoostService::class);

        $chapters = AudioBookChapter::whereIn('id', $this->chapterIds)
            ->where('audio_book_id', $this->audioBookId)
            ->whereNotNull('audio_file')
            ->orderBy('chapter_number')
            ->get();

        $total = $chapters->count();
        if ($total === 0) {
            $this->updateProgress('completed', 100, 'Không có chương nào có audio');
            return;
        }

        $success = 0;
        $failed = 0;
        $skipped = 0;

        $this->updateProgress('processing', 0, "Bắt đầu boost {$total} chương...", 0, $total, $success, $failed);

        foreach ($chapters as $index => $chapter) {
            $result = $boostService->boostChapter($audioBook, $chapter, $this->db);

            if ($result['success'] ?? false) {
                $success++;
                $mode = $result['mode'] ?? 'voice-remix';
                if ($mode === 'voice-remix') {
                    $this->addLog("✅ Chương {$chapter->chapter_number}: boost +{$this->db}dB (giọng-only, giữ nguyên nhạc)");
                } elseif ($mode === 'segment-preserve') {
                    $this->addLog("✅ Chương {$chapter->chapter_number}: boost +{$this->db}dB (giữ nguyên đoạn intro/outro có nhạc)");
                } else {
                    $this->addLog("✅ Chương {$chapter->chapter_number}: boost +{$this->db}dB (không có nhạc nền)");
                }
            } else {
                $failed++;
                $error = $result['error'] ?? 'Lỗi không xác định';
                $this->addLog("❌ Chương {$chapter->chapter_number}: {$error}");
                Log::warning("Boost failed for chapter {$chapter->id}", ['error' => $error]);
            }

            $percent = $this->calcPercent($index + 1, $total);
            $this->updateProgress('processing', $percent, "Đã xử lý " . ($index + 1) . "/{$total} chương", $index + 1, $total, $success, $failed);
        }

        $msg = "✅ Hoàn thành! Thành công: {$success}, Thất bại: {$failed}, Bỏ qua: {$skipped}";
        $this->updateProgress('completed', 100, $msg, $total, $total, $success, $failed);
        Log::info("Boost audio batch done", ['audio_book_id' => $this->audioBookId, 'success' => $success, 'failed' => $failed]);
    }

    private function calcPercent(int $done, int $total): int
    {
        return $total > 0 ? (int) round($done / $total * 100) : 100;
    }

    private function updateProgress(string $status, int $percent, string $message, int $done = 0, int $total = 0, int $success = 0, int $failed = 0): void
    {
        Cache::put("boost_batch_progress_{$this->audioBookId}", [
            'status'  => $status,
            'percent' => $percent,
            'message' => $message,
            'done'    => $done,
            'total'   => $total,
            'success' => $success,
            'failed'  => $failed,
        ], now()->addHours(6));
    }

    private function addLog(string $line): void
    {
        $logs = Cache::get("boost_batch_logs_{$this->audioBookId}", []);
        $logs[] = $line;
        // Keep last 200 lines
        if (count($logs) > 200) {
            $logs = array_slice($logs, -200);
        }
        Cache::put("boost_batch_logs_{$this->audioBookId}", $logs, now()->addHours(6));
    }
}
