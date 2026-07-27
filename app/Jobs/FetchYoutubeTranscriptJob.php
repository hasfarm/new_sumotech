<?php

namespace App\Jobs;

use App\Services\YoutubeTranscriptImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class FetchYoutubeTranscriptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries = 1;

    public function __construct(
        public readonly string $token,
        public readonly string $videoId
    ) {}

    public static function cacheKey(string $token): string
    {
        return "yt_transcript_job_{$token}";
    }

    public function handle(YoutubeTranscriptImportService $service): void
    {
        $key = self::cacheKey($this->token);
        Cache::put($key, ['stage' => 'starting'], now()->addMinutes(15));

        try {
            $result = $service->fetch($this->videoId, function (string $stage) use ($key) {
                Cache::put($key, ['stage' => $stage], now()->addMinutes(15));
            });

            if ($result['success']) {
                Cache::put($key, array_merge(['stage' => 'done'], $result), now()->addMinutes(15));
            } else {
                Cache::put($key, ['stage' => 'failed', 'error' => $result['error'] ?? 'Lỗi không xác định'], now()->addMinutes(15));
            }
        } catch (\Throwable $e) {
            Cache::put($key, ['stage' => 'failed', 'error' => $e->getMessage()], now()->addMinutes(15));
        }
    }
}
