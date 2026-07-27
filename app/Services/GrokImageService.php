<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class GrokImageService
{
    /**
     * Generate an image via xAI's Grok image API. Same call shape as
     * {@see \App\Services\BflFluxImageService::generateImage()} so it's a drop-in swap.
     *
     * Note: xAI's image endpoint does not accept a width/height or aspect-ratio param —
     * it always returns a fixed ~1280x720 (16:9) image, so $aspectRatio is accepted for
     * interface parity but has no effect here.
     *
     * @return array{success: bool, path?: string, error?: string}
     */
    public function generateImage(string $prompt, string $outputPath, string $aspectRatio = '16:9', ?string $model = null): array
    {
        $apiKey = trim((string) config('services.grok.api_key', ''));
        if ($apiKey === '') {
            return ['success' => false, 'error' => 'GROK_API_KEY chưa được cấu hình.'];
        }

        $prompt = trim($prompt);
        if ($prompt === '') {
            return ['success' => false, 'error' => 'Thiếu prompt để tạo ảnh Grok.'];
        }

        $baseUrl = rtrim((string) config('services.grok.base_url', 'https://api.x.ai/v1'), '/');
        $model = trim((string) ($model ?: config('services.grok.image_model', 'grok-imagine-image')));

        try {
            $response = Http::withToken($apiKey)
                ->timeout(90)
                ->post("{$baseUrl}/images/generations", [
                    'model' => $model,
                    'prompt' => $prompt,
                    'n' => 1,
                ]);

            if (!$response->successful()) {
                $shortBody = mb_substr(trim((string) $response->body()), 0, 300);
                return ['success' => false, 'error' => "Grok API lỗi HTTP {$response->status()}: {$shortBody}"];
            }

            $imageUrl = (string) $response->json('data.0.url');
            $b64 = (string) $response->json('data.0.b64_json');

            if ($imageUrl === '' && $b64 === '') {
                return ['success' => false, 'error' => 'Grok không trả về ảnh.'];
            }

            $imageBytes = $b64 !== '' ? base64_decode($b64) : null;
            if ($imageBytes === null) {
                $download = Http::timeout(60)->get($imageUrl);
                if (!$download->successful()) {
                    return ['success' => false, 'error' => 'Không tải được ảnh từ Grok.'];
                }
                $imageBytes = $download->body();
            }

            $dir = dirname($outputPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            file_put_contents($outputPath, $imageBytes);

            return ['success' => true, 'path' => $outputPath];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
