<?php

namespace App\Jobs;

use App\Models\AudioBook;
use App\Services\TTSService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GenerateDescriptionAudioJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $audioBookId;
    public array $options;
    public $timeout = 300; // 5 minutes

    public function __construct(int $audioBookId, array $options = [])
    {
        $this->audioBookId = $audioBookId;
        $this->options = $options;
    }

    public function handle(): void
    {
        $audioBook = AudioBook::find($this->audioBookId);
        if (!$audioBook) {
            $this->updateProgress([
                'status' => 'error',
                'message' => 'Audiobook không tồn tại',
            ]);
            return;
        }

        $this->updateProgress([
            'status' => 'processing',
            'message' => 'Đang tạo audio giới thiệu...',
        ]);

        $description = $this->options['description'];
        $provider = $this->options['provider'];
        $voiceName = $this->options['voice_name'];
        $voiceGender = $this->options['voice_gender'] ?? 'female';
        $styleInstruction = $this->options['style_instruction'] ?? null;

        // Skip style_instruction for Microsoft and OpenAI TTS
        if (in_array($provider, ['microsoft', 'openai'])) {
            $styleInstruction = null;
        }

        try {
            $ttsService = app(TTSService::class);

            $audioPath = $ttsService->generateAudio(
                $description,
                0,
                $voiceGender,
                $voiceName,
                $provider,
                $styleInstruction,
                null
            );

            // Move to permanent location
            $bookId = $audioBook->id;
            $outputDir = storage_path('app/public/books/' . $bookId);
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            $timestamp = time();
            $filename = "description_{$timestamp}.mp3";
            $outputPath = $outputDir . DIRECTORY_SEPARATOR . $filename;

            $sourcePath = storage_path('app/' . $audioPath);
            if (file_exists($sourcePath)) {
                // Delete old description audio if exists
                if ($audioBook->description_audio) {
                    $oldPath = storage_path('app/public/' . $audioBook->description_audio);
                    if (file_exists($oldPath)) {
                        unlink($oldPath);
                    }
                }

                copy($sourcePath, $outputPath);
                unlink($sourcePath);
            }

            // Get audio duration
            $duration = $this->getAudioDuration($outputPath);

            // Save to audiobook
            $relativePath = 'books/' . $bookId . '/' . $filename;
            $audioBook->update([
                'description_audio' => $relativePath,
                'description_audio_duration' => $duration,
            ]);

            Log::info("GenerateDescriptionAudioJob completed for audiobook {$bookId}: {$filename}");

            $this->updateProgress([
                'status' => 'completed',
                'message' => 'Đã tạo audio giới thiệu thành công!',
                'result' => [
                    'audio_file' => $relativePath,
                    'audio_url' => asset('storage/' . $relativePath),
                    'duration' => $duration,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error("GenerateDescriptionAudioJob failed for audiobook {$this->audioBookId}: " . $e->getMessage());

            $this->updateProgress([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function getAudioDuration(string $filePath): ?float
    {
        if (!file_exists($filePath)) {
            return null;
        }

        $command = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 \"{$filePath}\" 2>&1";
        exec($command, $output, $returnCode);

        if ($returnCode === 0 && isset($output[0]) && is_numeric($output[0])) {
            return (float) $output[0];
        }

        return null;
    }

    private function updateProgress(array $data): void
    {
        $data['updated_at'] = now()->toIso8601String();
        Cache::put("desc_audio_progress_{$this->audioBookId}", $data, now()->addMinutes(30));
    }
}
