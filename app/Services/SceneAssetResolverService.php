<?php

namespace App\Services;

use App\Models\AudiobookVideoShot;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class SceneAssetResolverService
{
    public function __construct(
        private readonly BflFluxImageService $fluxImageService,
        private readonly GrokImageService $grokImageService,
        private readonly KlingAIService $klingService,
        private readonly SeedanceAIService $seedanceService
    ) {}

    /**
     * Download the winning stock candidate's media file to local storage.
     *
     * @param array<string,mixed> $candidate
     * @return string storage/app/public-relative path
     */
    public function downloadStockAsset(AudiobookVideoShot $shot, array $candidate): string
    {
        $ext = ($candidate['media_type'] ?? 'video') === 'video' ? 'mp4' : 'jpg';
        $relativePath = $this->shotDir($shot) . "/stock_{$candidate['source']}.{$ext}";

        $response = Http::withHeaders(['User-Agent' => 'SumoTech-VideoPipeline/1.0 (contact@sumotech.ai)'])
            ->timeout(60)
            ->get((string) $candidate['download_url']);

        if (!$response->successful()) {
            throw new \RuntimeException("Không tải được file từ {$candidate['source']} (HTTP {$response->status()}).");
        }

        Storage::disk('public')->put($relativePath, $response->body());

        return $relativePath;
    }

    /**
     * AI fallback step 1 (either "score < 75" or "Scene này có thật ngoài đời không?" = NO):
     * generate a still image with Flux (Black Forest Labs) ONLY — no video yet. The user
     * reviews the image first; converting it to video is a separate, explicit step
     * ({@see animateImage()}) so nothing gets sent to the (paid, slower) video API before
     * the still is approved.
     *
     * @return string storage/app/public-relative path
     */
    public function generateAiImage(AudiobookVideoShot $shot): string
    {
        $imageRelativePath = $this->shotDir($shot) . '/ai_source.png';
        $imageAbsolutePath = storage_path('app/public/' . $imageRelativePath);

        $pipeline = $shot->scene->audioBook->videoPipeline ?? null;
        $provider = $pipeline->image_api_provider ?? 'flux';
        $model = $pipeline->image_api_model ?: null;
        $service = $provider === 'grok' ? $this->grokImageService : $this->fluxImageService;

        $imageResult = $service->generateImage($this->buildImagePrompt($shot), $imageAbsolutePath, '16:9', $model);
        if (empty($imageResult['success'])) {
            $providerLabel = $provider === 'grok' ? 'Grok' : 'Flux';
            throw new \RuntimeException("Tạo ảnh AI ({$providerLabel}) thất bại: " . ($imageResult['error'] ?? 'lỗi không xác định'));
        }

        return $imageRelativePath;
    }

    /**
     * AI fallback step 2: animate an already-approved still image via Kling or Seedance
     * image-to-video (same call/poll shape already used by
     * GenerateMediaCenterSentenceAssetJob). A shot is ~5-10s, matching what these tools
     * actually support in one generation — unlike a whole 5-10 min scene.
     *
     * @return string storage/app/public-relative path
     */
    public function animateImage(AudiobookVideoShot $shot, string $imageRelativePath, string $provider = 'seedance'): string
    {
        $prompt = $this->buildImagePrompt($shot);
        $service = $provider === 'seedance' ? $this->seedanceService : $this->klingService;

        $duration = min(10, max(5, (int) round($shot->estimated_duration_seconds)));
        $taskResult = $service->createImageToVideoTask($imageRelativePath, $prompt, ['duration' => $duration]);
        if (empty($taskResult['success']) || empty($taskResult['task_id'])) {
            throw new \RuntimeException('Không tạo được tác vụ AI video: ' . ($taskResult['error'] ?? 'lỗi không xác định'));
        }

        $videoUrl = $this->waitForCompletion($service, $taskResult['task_id']);

        $videoRelativePath = $this->shotDir($shot) . "/ai_video_{$provider}.mp4";
        $download = Http::timeout(240)->retry(2, 1000)->get($videoUrl);
        if (!$download->successful()) {
            throw new \RuntimeException('Không tải được video AI đã tạo.');
        }

        Storage::disk('public')->put($videoRelativePath, $download->body());

        return $videoRelativePath;
    }

    private function shotDir(AudiobookVideoShot $shot): string
    {
        $scene = $shot->scene;
        return "audiobooks/{$scene->audio_book_id}/video-pipeline/scenes/{$scene->id}/shots/{$shot->id}";
    }

    /**
     * Style is chosen per-book (via the pipeline's `image_style` setting, picked in the UI)
     * so every AI-generated image across the whole book stays visually consistent — never
     * mixed mid-book. Defaults to 'illustration' if unset.
     */
    private function buildImagePrompt(AudiobookVideoShot $shot): string
    {
        $keywords = implode(', ', $shot->keywords ?? []);
        $sceneTitle = $shot->scene->title ?? '';
        $style = (string) ($shot->scene->audioBook->videoPipeline->image_style ?? 'illustration');

        $styleLine = $style === 'cinematic_realistic'
            ? 'Style: photorealistic cinematic still (like a real film frame), realistic lighting and textures, shot on cinema camera, shallow depth of field, 16:9, no people looking at camera.'
            : 'Style: painterly digital illustration (not a photo), consistent art style, muted cinematic color grading, 16:9, dramatic lighting, no people looking at camera.';

        $intro = $style === 'cinematic_realistic'
            ? 'Cinematic photorealistic B-roll still image for a documentary-style narration video.'
            : 'Digital painting illustration, B-roll still image for a documentary-style narration video.';

        // "No text" is repeated/emphasized because Flux frequently renders garbled, misspelled
        // text into images when a prompt doesn't explicitly forbid it.
        return "{$intro} "
            . "Scene context: {$sceneTitle}. Visual keywords: {$keywords}. "
            . "{$styleLine} "
            . 'STRICTLY NO TEXT ANYWHERE IN THE IMAGE: no letters, no words, no writing, no captions, no watermark, no logos. '
            . 'This also means flags, banners, scrolls, signs, clothing and armor must be PLAIN and blank/unmarked — no calligraphy, no symbols, no characters, no glyphs, no engraved text of any kind, even decorative ones.';
    }

    private function waitForCompletion(object $service, string $taskId, int $maxAttempts = 36, int $sleepSeconds = 5): string
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            $status = $service->getTaskStatus($taskId);

            if (($status['status'] ?? '') === 'completed' && !empty($status['video_url'])) {
                return $status['video_url'];
            }

            if (($status['status'] ?? '') === 'failed') {
                throw new \RuntimeException('AI video generation thất bại: ' . ($status['error'] ?? 'lỗi không xác định'));
            }

            sleep($sleepSeconds);
        }

        throw new \RuntimeException('AI video generation timeout sau ' . ($maxAttempts * $sleepSeconds) . ' giây.');
    }
}
