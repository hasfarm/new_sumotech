<?php

namespace App\Services;

use Exception;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class FluxImageService
{
    private Client $client;
    private ?string $apiKey;
    private string $baseUrl;
    private string $defaultModel;

    public function __construct()
    {
        $this->apiKey = config('services.aiml.api_key');
        $this->baseUrl = rtrim((string) config('services.aiml.base_url', 'https://api.aimlapi.com'), '/');
        $this->defaultModel = (string) config('services.aiml.flux_model', 'flux/schnell');

        $this->client = new Client([
            'timeout' => 180,
            'connect_timeout' => 20,
        ]);
    }

    /**
     * Generate image with Flux model via AIML API.
     *
     * @return array{success: bool, path?: string, error?: string}
     */
    public function generateImage(string $prompt, string $outputPath, string $aspectRatio = '16:9', ?string $model = null): array
    {
        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'error' => 'AIML_API_KEY chưa được cấu hình',
            ];
        }

        $prompt = trim($prompt);
        if ($prompt === '') {
            return [
                'success' => false,
                'error' => 'Thiếu prompt để tạo ảnh Flux.',
            ];
        }

        $selectedModel = trim((string) ($model ?: $this->defaultModel));
        $size = $this->getSizeByAspectRatio($aspectRatio);

        try {
            Log::info('FluxImageService: Generating image', [
                'model' => $selectedModel,
                'aspect_ratio' => $aspectRatio,
                'size' => $size,
                'prompt_preview' => mb_substr($prompt, 0, 180),
            ]);

            $response = $this->client->post($this->baseUrl . '/v1/images/generations/', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => [
                    'model' => $selectedModel,
                    'prompt' => $prompt,
                    'size' => $size,
                    'n' => 1,
                ],
            ]);

            $result = json_decode($response->getBody()->getContents(), true);
            if (!is_array($result)) {
                throw new Exception('Phản hồi từ Flux không hợp lệ.');
            }

            $imageBytes = $this->extractImageBytes($result);
            if (!$imageBytes) {
                throw new Exception('Flux không trả về dữ liệu ảnh.');
            }

            $dir = dirname($outputPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            file_put_contents($outputPath, $imageBytes);

            Log::info('FluxImageService: Image generated successfully', [
                'path' => $outputPath,
                'size_bytes' => strlen($imageBytes),
            ]);

            return [
                'success' => true,
                'path' => $outputPath,
            ];
        } catch (\Throwable $e) {
            Log::error('FluxImageService: Generation failed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function extractImageBytes(array $result): ?string
    {
        $candidate = null;

        if (!empty($result['data'][0]) && is_array($result['data'][0])) {
            $candidate = $result['data'][0];
        } elseif (!empty($result['images'][0]) && is_array($result['images'][0])) {
            $candidate = $result['images'][0];
        }

        if (is_array($candidate)) {
            if (!empty($candidate['b64_json'])) {
                $decoded = base64_decode((string) $candidate['b64_json'], true);
                if ($decoded !== false) {
                    return $decoded;
                }
            }

            if (!empty($candidate['base64'])) {
                $decoded = base64_decode((string) $candidate['base64'], true);
                if ($decoded !== false) {
                    return $decoded;
                }
            }

            if (!empty($candidate['url'])) {
                return $this->downloadImage((string) $candidate['url']);
            }
        }

        if (!empty($result['image'])) {
            $imageData = (string) $result['image'];
            if (str_starts_with($imageData, 'data:image')) {
                $parts = explode(',', $imageData, 2);
                if (isset($parts[1])) {
                    $decoded = base64_decode($parts[1], true);
                    if ($decoded !== false) {
                        return $decoded;
                    }
                }
            }

            $decoded = base64_decode($imageData, true);
            if ($decoded !== false) {
                return $decoded;
            }
        }

        if (!empty($result['url']) && is_string($result['url'])) {
            return $this->downloadImage($result['url']);
        }

        return null;
    }

    private function downloadImage(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $response = $this->client->get($url, [
            'headers' => [
                'Accept' => 'image/*,*/*;q=0.8',
            ],
            'timeout' => 120,
        ]);

        $bytes = (string) $response->getBody();
        return $bytes !== '' ? $bytes : null;
    }

    private function getSizeByAspectRatio(string $aspectRatio): string
    {
        return match ($aspectRatio) {
            '9:16' => '768x1365',
            '1:1' => '1024x1024',
            '4:3' => '1024x768',
            '3:4' => '768x1024',
            '16:9' => '1365x768',
            default => '1365x768',
        };
    }
}
