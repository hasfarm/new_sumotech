<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class BflFluxImageService
{
    /**
     * Generate an image via Black Forest Labs' direct API (not the AIML-routed Flux used
     * elsewhere in the app). Same {@see \App\Services\GeminiImageService::generateImage()}
     * call shape so it's a drop-in swap wherever image generation is needed.
     *
     * @return array{success: bool, path?: string, error?: string}
     */
    public function generateImage(string $prompt, string $outputPath, string $aspectRatio = '16:9', ?string $model = null): array
    {
        $apiKey = trim((string) config('services.flux.api_key', ''));
        if ($apiKey === '') {
            return ['success' => false, 'error' => 'FLUX_API_KEY chưa được cấu hình.'];
        }

        $prompt = trim($prompt);
        if ($prompt === '') {
            return ['success' => false, 'error' => 'Thiếu prompt để tạo ảnh Flux.'];
        }

        $baseUrl = rtrim((string) config('services.flux.base_url', 'https://api.bfl.ai'), '/');
        $model = trim((string) ($model ?: config('services.flux.model', 'flux-2-klein-9b')));
        [$width, $height] = $this->resolveDimensions($aspectRatio);

        try {
            $createResponse = Http::withHeaders(['x-key' => $apiKey, 'Content-Type' => 'application/json'])
                ->timeout(30)
                ->post("{$baseUrl}/v1/{$model}", [
                    'prompt' => $prompt,
                    'width' => $width,
                    'height' => $height,
                ]);

            if (!$createResponse->successful()) {
                $shortBody = mb_substr(trim((string) $createResponse->body()), 0, 300);
                return ['success' => false, 'error' => "BFL API lỗi HTTP {$createResponse->status()}: {$shortBody}"];
            }

            $pollingUrl = (string) $createResponse->json('polling_url');
            if ($pollingUrl === '') {
                return ['success' => false, 'error' => 'BFL không trả về polling_url.'];
            }

            $imageUrl = $this->waitForResult($apiKey, $pollingUrl);

            $download = Http::timeout(60)->get($imageUrl);
            if (!$download->successful()) {
                return ['success' => false, 'error' => 'Không tải được ảnh từ BFL.'];
            }

            $dir = dirname($outputPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            file_put_contents($outputPath, $download->body());

            return ['success' => true, 'path' => $outputPath];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function waitForResult(string $apiKey, string $pollingUrl, int $maxAttempts = 30, int $sleepSeconds = 2): string
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            $response = Http::withHeaders(['x-key' => $apiKey])->timeout(15)->get($pollingUrl);
            $data = $response->json();
            $status = (string) ($data['status'] ?? '');

            if ($status === 'Ready') {
                $sample = (string) data_get($data, 'result.sample', '');
                if ($sample === '') {
                    throw new \RuntimeException('BFL trả về trạng thái Ready nhưng không có ảnh.');
                }
                return $sample;
            }

            if (in_array($status, ['Error', 'Failed', 'Content Moderated', 'Request Moderated'], true)) {
                throw new \RuntimeException("BFL tạo ảnh thất bại: {$status}");
            }

            sleep($sleepSeconds);
        }

        throw new \RuntimeException('BFL tạo ảnh timeout.');
    }

    /**
     * @return array{0:int,1:int}
     */
    private function resolveDimensions(string $aspectRatio): array
    {
        return match ($aspectRatio) {
            '9:16' => [768, 1344],
            '1:1' => [1024, 1024],
            '4:3' => [1024, 768],
            '3:4' => [768, 1024],
            '16:9' => [1344, 768],
            default => [1344, 768],
        };
    }
}
