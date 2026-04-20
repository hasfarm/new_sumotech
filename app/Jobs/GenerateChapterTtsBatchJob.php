<?php

namespace App\Jobs;

use App\Http\Controllers\AudioBookChapterController;
use App\Models\AudioBook;
use App\Models\AudioBookChapter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GenerateChapterTtsBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $audioBookId;
    public array $chapterIds;
    public array $ttsSettings;
    public $timeout = 14400; // 4 hours

    public function __construct(int $audioBookId, array $chapterIds, array $ttsSettings)
    {
        $this->audioBookId = $audioBookId;
        $this->chapterIds = $chapterIds;
        $this->ttsSettings = $ttsSettings;
    }

    public function handle(): void
    {
        $lockKey = $this->getBatchLockKey();
        $lockToken = (string) Str::uuid();

        if (!Cache::add($lockKey, $lockToken, now()->addHours(8))) {
            $this->addLog("Bo qua batch: audiobook {$this->audioBookId} dang duoc xu ly boi mot job khac.");
            $this->updateProgress([
                'status' => 'processing',
                'message' => 'Dang co batch khac chay cho audiobook nay. Bo qua job trung lap.',
            ]);
            return;
        }

        try {
        $audioBook = AudioBook::find($this->audioBookId);
        if (!$audioBook) {
            $this->updateProgress([
                'status' => 'error',
                'percent' => 100,
                'message' => 'Khong tim thay audiobook.'
            ]);
            return;
        }

        $chapters = AudioBookChapter::where('audio_book_id', $this->audioBookId)
            ->whereIn('id', $this->chapterIds)
            ->orderBy('chapter_number')
            ->get();

        if ($chapters->isEmpty()) {
            $this->updateProgress([
                'status' => 'error',
                'percent' => 100,
                'message' => 'Khong tim thay chuong nao.'
            ]);
            return;
        }

        $controller = app(AudioBookChapterController::class);
        $totalSteps = 0;

        foreach ($chapters as $chapter) {
            $initRequest = new Request($this->ttsSettings);
            $initResponse = $controller->initializeChunks($initRequest, $audioBook, $chapter);

            $chapter->refresh();
            $chunks = $chapter->chunks()->orderBy('chunk_number')->get();
            $chunkCount = $chunks->filter(fn ($chunk) => $this->chunkNeedsGeneration($chunk))->count();
            $mergeSteps = $this->chapterNeedsMerge($chapter, $chunks) ? 1 : 0;
            $totalSteps += $chunkCount + $mergeSteps;
        }

        if ($totalSteps === 0) {
            $this->updateProgress([
                'status' => 'error',
                'percent' => 100,
                'message' => 'Khong co doan nao de tao.'
            ]);
            return;
        }

        $doneSteps = 0;
        $errorCount = 0;

        foreach ($chapters as $chapter) {
            $chapterNum = $chapter->chapter_number;
            $chapter->refresh();
            $chunks = $chapter->chunks()->orderBy('chunk_number')->get();
            $totalChunks = $chunks->count();
            $chunksToGenerate = $chunks->filter(fn ($chunk) => $this->chunkNeedsGeneration($chunk))->values();
            $totalChunksToGenerate = $chunksToGenerate->count();

            if ($totalChunksToGenerate === 0) {
                $this->addLog("C{$chapterNum}: Tat ca chunk da co audio, bo qua tao TTS.");
            }

            foreach ($chunksToGenerate as $chunk) {
                $this->updateProgress([
                    'status' => 'processing',
                    'percent' => $this->calcPercent($doneSteps, $totalSteps),
                    'message' => "Chuong {$chapterNum}: Dang tao doan {$chunk->chunk_number}/{$totalChunks}",
                    'current_chapter_number' => $chapterNum,
                    'current_chunk_number' => $chunk->chunk_number,
                    'current_chunk_total' => $totalChunks,
                    'chunk_percent' => $totalChunksToGenerate > 0
                        ? (int) floor((($doneSteps + 1) / max(1, $totalSteps)) * 100)
                        : 0,
                ]);

                $genRequest = new Request($this->ttsSettings);
                $genResponse = $controller->generateSingleChunk($genRequest, $audioBook, $chapter, $chunk);
                $genData = method_exists($genResponse, 'getData') ? $genResponse->getData(true) : [];

                if (!($genData['success'] ?? false)) {
                    $errorCount++;
                    $err = $genData['error'] ?? 'TTS chunk failed';
                    $this->addLog("Loi C{$chapterNum}-{$chunk->chunk_number}: {$err}");
                }

                $doneSteps++;
                $this->updateProgress([
                    'status' => 'processing',
                    'percent' => $this->calcPercent($doneSteps, $totalSteps),
                ]);
            }

            if ($this->chapterNeedsMerge($chapter, $chunks)) {
                $mergeResponse = $controller->mergeChapterAudioEndpoint(new Request([]), $audioBook, $chapter);
                $mergeData = method_exists($mergeResponse, 'getData') ? $mergeResponse->getData(true) : [];
                if (!($mergeData['success'] ?? false)) {
                    $errorCount++;
                    $err = $mergeData['error'] ?? 'Merge failed';
                    $this->addLog("Loi merge C{$chapterNum}: {$err}");
                } else {
                    $this->addLog("Hoan tat merge C{$chapterNum}");
                }

                $doneSteps++;
                $this->updateProgress([
                    'status' => 'processing',
                    'percent' => $this->calcPercent($doneSteps, $totalSteps),
                ]);
            } else {
                $this->addLog("C{$chapterNum}: Da co file merge, bo qua merge.");
            }
        }

        if ($errorCount > 0) {
            $this->updateProgress([
                'status' => 'error',
                'percent' => 100,
                'message' => "Hoan tat co {$errorCount} loi.",
            ]);
            return;
        }

        $this->updateProgress([
            'status' => 'completed',
            'percent' => 100,
            'message' => 'Hoan tat tao TTS.',
        ]);
        } finally {
            $this->releaseBatchLock($lockKey, $lockToken);
        }
    }

    private function getBatchLockKey(): string
    {
        return "tts_batch_lock_{$this->audioBookId}";
    }

    private function releaseBatchLock(string $lockKey, string $lockToken): void
    {
        if (Cache::get($lockKey) === $lockToken) {
            Cache::forget($lockKey);
        }
    }

    private function chunkNeedsGeneration($chunk): bool
    {
        if (!$chunk->audio_file) {
            return true;
        }

        return !file_exists(storage_path('app/public/' . $chunk->audio_file));
    }

    private function chapterNeedsMerge(AudioBookChapter $chapter, $chunks): bool
    {
        if ($chunks->isEmpty()) {
            return false;
        }

        if (!$chapter->audio_file) {
            return true;
        }

        return !file_exists(storage_path('app/public/' . $chapter->audio_file));
    }

    private function calcPercent(int $doneSteps, int $totalSteps): int
    {
        if ($totalSteps <= 0) {
            return 0;
        }
        return (int) min(100, floor(($doneSteps / $totalSteps) * 100));
    }

    private function updateProgress(array $data): void
    {
        $payload = array_merge([
            'status' => 'processing',
            'percent' => 0,
            'message' => '',
            'current_chapter_number' => null,
            'current_chunk_number' => null,
            'current_chunk_total' => null,
            'chunk_percent' => null,
            'logs' => Cache::get("tts_batch_logs_{$this->audioBookId}", []),
            'updated_at' => now()->toIso8601String(),
        ], $data);

        Cache::put("tts_batch_progress_{$this->audioBookId}", $payload, now()->addHours(6));
    }

    private function addLog(string $line): void
    {
        $logs = Cache::get("tts_batch_logs_{$this->audioBookId}", []);
        $logs[] = '[' . now()->format('H:i:s') . '] ' . $line;
        if (count($logs) > 200) {
            $logs = array_slice($logs, -200);
        }
        Cache::put("tts_batch_logs_{$this->audioBookId}", $logs, now()->addHours(6));
    }
}
