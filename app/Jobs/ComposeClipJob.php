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

class ComposeClipJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $audioBookId;
    public string $clipId;
    public array $payload;
    public $timeout = 3600; // 1 hour max

    /**
     * Cache key pattern for clip compose progress.
     */
    public static function cacheKey(int $audioBookId, string $clipId): string
    {
        return "compose_clip_progress_{$audioBookId}_{$clipId}";
    }

    public function __construct(int $audioBookId, string $clipId, array $payload)
    {
        $this->audioBookId = $audioBookId;
        $this->clipId = $clipId;
        $this->payload = $payload;
    }

    public function handle(): void
    {
        $audioBook = AudioBook::find($this->audioBookId);
        if (!$audioBook) {
            $this->updateProgress([
                'status' => 'error',
                'percent' => 100,
                'message' => 'Không tìm thấy audiobook.',
            ]);
            return;
        }

        $this->updateProgress([
            'status' => 'processing',
            'percent' => 5,
            'message' => 'Bắt đầu ghép video clip...',
        ]);

        try {
            $controller = app(AudioBookController::class);
            $request = new Request($this->payload);

            $this->updateProgress([
                'status' => 'processing',
                'percent' => 10,
                'message' => 'Đang xử lý FFmpeg (ghép clip + CTA + subtitle)...',
            ]);

            $response = $controller->composeClip($request, $audioBook, $this->clipId);

            $data = method_exists($response, 'getData') ? $response->getData(true) : null;
            $statusCode = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 500;

            if (!$data || $statusCode >= 400 || empty($data['success'])) {
                $this->updateProgress([
                    'status' => 'error',
                    'percent' => 100,
                    'message' => $data['error'] ?? 'Ghép video thất bại.',
                    'result' => $data,
                ]);
                return;
            }

            $this->updateProgress([
                'status' => 'completed',
                'percent' => 100,
                'message' => 'Ghép video hoàn tất!',
                'result' => $data,
            ]);
        } catch (\Throwable $e) {
            Log::error('ComposeClipJob failed', [
                'audio_book_id' => $this->audioBookId,
                'clip_id' => $this->clipId,
                'error' => $e->getMessage(),
            ]);

            $this->updateProgress([
                'status' => 'error',
                'percent' => 100,
                'message' => 'Lỗi: ' . $e->getMessage(),
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ComposeClipJob failed permanently', [
            'audio_book_id' => $this->audioBookId,
            'clip_id' => $this->clipId,
            'error' => $exception->getMessage(),
        ]);

        $this->updateProgress([
            'status' => 'error',
            'percent' => 100,
            'message' => 'Job thất bại: ' . $exception->getMessage(),
        ]);
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

        Cache::put(
            self::cacheKey($this->audioBookId, $this->clipId),
            $payload,
            now()->addHours(4)
        );
    }
}
