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

class GenerateReviewAssetsJob implements ShouldQueue
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

        $service = app(BookReviewVideoService::class);
        $chunks = $service->loadChunks($audioBook->id);

        if (!$chunks || empty($chunks)) {
            $this->updateProgress(['status' => 'error', 'message' => 'Chưa có segments. Hãy tạo kịch bản trước.']);
            return;
        }

        $provider = $this->options['provider'] ?? $audioBook->tts_provider ?? 'microsoft';
        $voiceName = $this->options['voice_name'] ?? $audioBook->tts_voice_name ?? '';
        $voiceGender = $this->options['voice_gender'] ?? $audioBook->tts_voice_gender ?? 'female';
        $styleInstruction = $this->options['style_instruction'] ?? $audioBook->tts_style_instruction;
        $imageProvider = strtolower((string) ($this->options['image_provider'] ?? 'gemini'));
        $imageProvider = $imageProvider === 'flux' ? 'flux' : 'gemini';

        $totalChunks = count($chunks);

        try {
            // ===== STAGE 1: Generate images (0-50%) =====
            foreach ($chunks as $i => $chunk) {
                $percent = (int)(($i / max(1, $totalChunks)) * 50);

                if (!empty($chunk['image_path']) && file_exists($chunk['image_path'])) {
                    $this->updateProgress([
                        'status' => 'processing',
                        'stage' => 1, 'total_stages' => 2,
                        'stage_name' => 'Tạo ảnh minh họa',
                        'percent' => min(50, $percent),
                        'detail' => "Segment " . ($i + 1) . "/{$totalChunks} đã có ảnh, bỏ qua.",
                        'chunk_progress' => ($i + 1) . "/{$totalChunks}",
                    ]);
                    continue;
                }

                $providerLabel = $imageProvider === 'flux' ? 'Flux' : 'Gemini';
                $this->updateProgress([
                    'status' => 'processing',
                    'stage' => 1, 'total_stages' => 2,
                    'stage_name' => "Tạo ảnh ({$providerLabel})",
                    'percent' => min(50, $percent),
                    'detail' => "Đang tạo ảnh segment " . ($i + 1) . "/{$totalChunks} bằng {$providerLabel}...",
                    'chunk_progress' => ($i + 1) . "/{$totalChunks}",
                ]);

                $imagePrompt = $chunk['image_prompt'];

                // Inject art style instruction if available
                $artStyle = $this->options['art_style_instruction'] ?? '';
                if ($artStyle) {
                    $imagePrompt .= "\n\nIMPORTANT CHARACTER/VISUAL STYLE: " . $artStyle;
                }

                $imageResult = $service->generateChunkImage($audioBook->id, $i, $imagePrompt, $imageProvider);
                if (!$imageResult['success']) {
                    Log::warning("Review assets: image generation failed for chunk {$i} with {$imageProvider}");
                }

                sleep(1);
            }

            // ===== STAGE 2: Generate TTS (50-100%) =====
            $chunks = $service->loadChunks($audioBook->id);
            foreach ($chunks as $i => $chunk) {
                $percent = 50 + (int)(($i / max(1, $totalChunks)) * 50);

                if (!empty($chunk['audio_path']) && file_exists($chunk['audio_path'])) {
                    $this->updateProgress([
                        'status' => 'processing',
                        'stage' => 2, 'total_stages' => 2,
                        'stage_name' => 'Tạo audio TTS',
                        'percent' => min(98, $percent),
                        'detail' => "Segment " . ($i + 1) . "/{$totalChunks} đã có audio, bỏ qua.",
                        'chunk_progress' => ($i + 1) . "/{$totalChunks}",
                    ]);
                    continue;
                }

                $this->updateProgress([
                    'status' => 'processing',
                    'stage' => 2, 'total_stages' => 2,
                    'stage_name' => 'Tạo audio TTS',
                    'percent' => min(98, $percent),
                    'detail' => "Đang tạo audio segment " . ($i + 1) . "/{$totalChunks}...",
                    'chunk_progress' => ($i + 1) . "/{$totalChunks}",
                ]);

                $service->generateChunkTts(
                    $audioBook->id, $i, $chunk['text'],
                    $provider, $voiceName, $voiceGender, $styleInstruction
                );

                sleep(1);
            }

            // ===== COMPLETED =====
            $this->updateProgress([
                'status' => 'completed',
                'stage' => 2, 'total_stages' => 2,
                'stage_name' => 'Hoàn thành',
                'percent' => 100,
                'detail' => "Đã tạo xong ảnh & audio cho {$totalChunks} segments!",
                'chunks_count' => $totalChunks,
            ]);

        } catch (\Exception $e) {
            Log::error("GenerateReviewAssetsJob failed for audiobook {$this->audioBookId}: " . $e->getMessage());
            $this->updateProgress([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function updateProgress(array $data): void
    {
        $data['updated_at'] = now()->toIso8601String();
        Cache::put("review_assets_progress_{$this->audioBookId}", $data, now()->addHours(2));
    }
}
