<?php

namespace App\Jobs;

use App\Http\Controllers\AudioBookController;
use App\Models\AudioBook;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GenerateClipTitleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $audioBookId;
    public string $clipId;
    public array $payload;
    public $timeout = 600;

    public static function cacheKey(int $audioBookId, string $clipId): string
    {
        return "clip_title_progress_{$audioBookId}_{$clipId}";
    }

    public function __construct(int $audioBookId, string $clipId, array $payload = [])
    {
        $this->audioBookId = $audioBookId;
        $this->clipId = $clipId;
        $this->payload = $payload;
    }

    public function handle(): void
    {
        $audioBook = AudioBook::find($this->audioBookId);
        if (!$audioBook) {
            $this->updateProgress(['status' => 'error', 'message' => 'Không tìm thấy audiobook.', 'percent' => 100]);
            return;
        }

        $this->updateProgress(['status' => 'processing', 'message' => 'Đang tạo tiêu đề hook...', 'percent' => 10]);

        try {
            $controller = app(AudioBookController::class);
            $payload = array_merge($this->payload, ['_from_job' => true]);
            $request = new Request($payload);

            $response = $controller->generateClipHookTitle($request, $audioBook, $this->clipId);
            $data = method_exists($response, 'getData') ? $response->getData(true) : null;
            $code = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 500;

            if (!$data || $code >= 400 || empty($data['success'])) {
                $this->updateProgress(['status' => 'error', 'message' => $data['error'] ?? 'Không thể tạo tiêu đề.', 'percent' => 100]);
                return;
            }

            $this->updateProgress(['status' => 'completed', 'message' => 'Đã tạo tiêu đề!', 'percent' => 100, 'result' => $data]);
        } catch (\Throwable $e) {
            Log::error('GenerateClipTitleJob failed', [
                'audio_book_id' => $this->audioBookId,
                'clip_id' => $this->clipId,
                'error' => $e->getMessage(),
            ]);
            $this->updateProgress(['status' => 'error', 'message' => 'Lỗi: ' . $e->getMessage(), 'percent' => 100]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->updateProgress(['status' => 'error', 'message' => 'Job thất bại: ' . $exception->getMessage(), 'percent' => 100]);
    }

    private function updateProgress(array $data): void
    {
        $payload = array_merge([
            'status' => 'processing',
            'percent' => 0,
            'message' => '',
            'clip_id' => $this->clipId,
            'updated_at' => now()->toIso8601String(),
        ], $data);

        Cache::put(self::cacheKey($this->audioBookId, $this->clipId), $payload, now()->addHours(2));
    }
}
