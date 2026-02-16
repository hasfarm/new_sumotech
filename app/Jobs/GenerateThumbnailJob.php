<?php

namespace App\Jobs;

use App\Models\AudioBook;
use App\Services\GeminiImageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GenerateThumbnailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $audioBookId;
    public array $options;
    public $timeout = 600; // 10 minutes

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
            'message' => 'Đang chuẩn bị tạo thumbnail...',
        ]);

        $style = $this->options['style'] ?? 'cinematic';
        $withText = $this->options['with_text'] ?? true;
        $aiResearch = $this->options['ai_research'] ?? false;
        $useCoverImage = $this->options['use_cover_image'] ?? false;
        $customPrompt = $this->options['custom_prompt'] ?? null;
        $chapterNumber = $this->options['chapter_number'] ?? null;
        $customTitle = $this->options['custom_title'] ?? null;
        $customAuthor = $this->options['custom_author'] ?? null;
        $textStyling = $this->options['text_styling'] ?? [];
        $overridePrompt = $this->options['override_prompt'] ?? null;

        try {
            $imageService = app(GeminiImageService::class);

            $bookInfo = [
                'book_id' => $audioBook->id,
                'title' => $customTitle ?: $audioBook->title,
                'author' => $customAuthor ?: ($audioBook->author ? 'Tác giả: ' . $audioBook->author : ''),
                'category' => $audioBook->category,
                'book_type' => null,
                'description' => $audioBook->description,
                'channel_name' => '',
                'cover_image' => $audioBook->cover_image,
                'text_styling' => $textStyling,
            ];

            // Use cover image
            if ($useCoverImage && $audioBook->cover_image) {
                $this->updateProgress([
                    'status' => 'processing',
                    'message' => 'Đang xử lý ảnh bìa...',
                ]);

                $result = $imageService->createThumbnailFromCover($bookInfo, $chapterNumber);
                $this->handleResult($result, $audioBook->id);
                return;
            }

            // AI research
            if ($aiResearch) {
                $this->updateProgress([
                    'status' => 'processing',
                    'message' => 'AI đang tìm kiếm thông tin về sách...',
                ]);

                $researchResult = $imageService->researchAndCreatePrompt($bookInfo);
                if ($researchResult['success']) {
                    $customPrompt = $researchResult['prompt'];
                }
            }

            // Generate
            if ($withText) {
                $this->updateProgress([
                    'status' => 'processing',
                    'message' => 'AI đang tạo thumbnail có chữ với Gemini...',
                ]);
                $result = $imageService->generateThumbnailWithText($bookInfo, $style, $chapterNumber, $customPrompt, $overridePrompt);
            } else {
                $this->updateProgress([
                    'status' => 'processing',
                    'message' => 'AI đang tạo hình nền với Gemini...',
                ]);
                $result = $imageService->generateThumbnail($bookInfo, $style, $chapterNumber, $customPrompt, $overridePrompt);
            }

            $this->handleResult($result, $audioBook->id);
        } catch (\Exception $e) {
            Log::error("GenerateThumbnailJob failed for audiobook {$this->audioBookId}: " . $e->getMessage());

            $this->updateProgress([
                'status' => 'error',
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function handleResult(array $result, int $audioBookId): void
    {
        if ($result['success']) {
            Log::info("GenerateThumbnailJob completed for audiobook {$audioBookId}", [
                'path' => $result['path'] ?? null,
            ]);

            $this->updateProgress([
                'status' => 'completed',
                'message' => 'Đã tạo thumbnail thành công!',
                'result' => $result,
            ]);
        } else {
            $this->updateProgress([
                'status' => 'error',
                'message' => $result['error'] ?? 'Không thể tạo thumbnail',
            ]);
        }
    }

    private function updateProgress(array $data): void
    {
        $data['updated_at'] = now()->toIso8601String();
        Cache::put("thumbnail_progress_{$this->audioBookId}", $data, now()->addMinutes(30));
    }
}
