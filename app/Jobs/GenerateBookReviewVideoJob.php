<?php

namespace App\Jobs;

use App\Models\AudioBook;
use App\Services\BookReviewVideoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GenerateBookReviewVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $audioBookId;
    public array $options;
    public $timeout = 3600;
    public $tries = 1;

    public function __construct(int $audioBookId, array $options = [])
    {
        $this->audioBookId = $audioBookId;
        $this->options = $options;
    }

    public function handle(): void
    {
        $audioBook = AudioBook::find($this->audioBookId);
        if (!$audioBook) {
            $this->updateProgress(['status' => 'error', 'message' => 'Audiobook không tồn tại']);
            return;
        }

        $audioBook->load(['chapters', 'youtubeChannel']);
        $service = app(BookReviewVideoService::class);

        try {
            // ===== STAGE 1: Generate review script (0-60%) =====
            $this->updateProgress([
                'status' => 'processing',
                'stage' => 1, 'total_stages' => 2,
                'stage_name' => 'Tóm tắt chương & viết kịch bản',
                'percent' => 2,
                'detail' => 'Bắt đầu tóm tắt các chương...',
            ]);

            $chaptersWithContent = $audioBook->chapters->filter(fn($ch) => !empty($ch->content));
            $totalChapters = $chaptersWithContent->count();

            $script = $service->generateReviewScript($audioBook, function ($message, $current, $total) use ($totalChapters) {
                $percent = (int)(2 + ($current / max(1, $totalChapters)) * 55);
                $this->updateProgress([
                    'status' => 'processing',
                    'stage' => 1, 'total_stages' => 2,
                    'stage_name' => 'Tóm tắt chương & viết kịch bản',
                    'percent' => min(58, $percent),
                    'detail' => $message,
                    'chapter_progress' => "{$current}/{$total}",
                ]);
            });

            $wordCount = str_word_count($script) + mb_substr_count($script, ' ');

            // ===== STAGE 2: Chunk review script (60-100%) =====
            $this->updateProgress([
                'status' => 'processing',
                'stage' => 2, 'total_stages' => 2,
                'stage_name' => 'Phân chia kịch bản thành segments',
                'percent' => 62,
                'detail' => 'AI đang phân tích kịch bản...',
                'script_word_count' => $wordCount,
            ]);

            $chunks = $service->chunkReviewScript($audioBook, $script);
            $totalChunks = count($chunks);

            // ===== COMPLETED =====
            $this->updateProgress([
                'status' => 'completed',
                'stage' => 2, 'total_stages' => 2,
                'stage_name' => 'Hoàn thành',
                'percent' => 100,
                'detail' => "Đã tạo kịch bản với {$totalChunks} segments. Hãy chỉnh sửa prompt rồi tạo ảnh & audio.",
                'chunks_count' => $totalChunks,
            ]);

        } catch (\Exception $e) {
            Log::error("GenerateBookReviewVideoJob failed for audiobook {$this->audioBookId}: " . $e->getMessage());
            $this->updateProgress([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function updateProgress(array $data): void
    {
        $data['updated_at'] = now()->toIso8601String();
        Cache::put("review_video_progress_{$this->audioBookId}", $data, now()->addHours(2));
    }
}
