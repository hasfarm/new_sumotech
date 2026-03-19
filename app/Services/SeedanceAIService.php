<?php

namespace App\Services;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SeedanceAIService
{
    private string $apiKey;
    private string $baseUrl;
    private string $defaultModel;
    private Client $client;

    public function __construct()
    {
        $this->apiKey = (string) config('services.seedance.api_key', '');

        $configuredBaseUrl = rtrim((string) config('services.seedance.base_url', 'https://ark.ap-southeast.bytepluses.com/api/v3'), '/');
        $this->baseUrl = str_contains($configuredBaseUrl, '/api/v3') ? $configuredBaseUrl : ($configuredBaseUrl . '/api/v3');
        $this->defaultModel = (string) config('services.seedance.model', 'seedance-1-5-pro-251215');

        $this->client = new Client([
            'base_uri' => $this->baseUrl . '/',
            'timeout' => 120,
            'verify' => false,
        ]);
    }

    /**
     * @return array{success: bool, task_id?: string, message?: string, error?: string}
     */
    public function createImageToVideoTask(string $imagePath, ?string $prompt = null, array $options = []): array
    {
        if ($this->apiKey === '') {
            return [
                'success' => false,
                'error' => 'SEEDANCE_API_KEY chưa được cấu hình.',
            ];
        }

        try {
            Log::info('Seedance (ModelArk): Creating image-to-video task', [
                'image_path' => $imagePath,
            ]);

            $fullPath = storage_path('app/public/' . ltrim($imagePath, '/'));
            if (!file_exists($fullPath)) {
                throw new Exception("Image file not found: {$imagePath}");
            }

            $imageData = file_get_contents($fullPath);
            if ($imageData === false || $imageData === '') {
                throw new Exception('Không thể đọc dữ liệu ảnh để gửi Seedance.');
            }

            $mimeType = mime_content_type($fullPath) ?: 'image/png';
            $base64Image = 'data:' . $mimeType . ';base64,' . base64_encode($imageData);

            $defaultPrompt = 'Subtle cinematic motion, smooth camera movement, natural ambient animation, no sudden cuts, high quality frame coherence.';

            $duration = (int) ($options['duration'] ?? 5);
            if ($duration <= 0) {
                $duration = 5;
            }

            $requestBody = [
                'model' => (string) ($options['model'] ?? $this->defaultModel),
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $prompt ?: $defaultPrompt,
                    ],
                    [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => $base64Image,
                        ],
                    ],
                ],
                'duration' => $duration,
                'ratio' => (string) ($options['ratio'] ?? 'adaptive'),
                'watermark' => (bool) ($options['watermark'] ?? false),
            ];

            if (array_key_exists('generate_audio', $options)) {
                $requestBody['generate_audio'] = (bool) $options['generate_audio'];
            }

            if (!empty($options['resolution'])) {
                $requestBody['resolution'] = (string) $options['resolution'];
            }

            if (!empty($options['service_tier'])) {
                $requestBody['service_tier'] = (string) $options['service_tier'];
            }

            Log::info('Seedance (ModelArk): Sending request', [
                'endpoint' => 'contents/generations/tasks',
                'model' => $requestBody['model'],
                'duration' => $requestBody['duration'],
                'image_size' => strlen($imageData) . ' bytes',
            ]);

            $response = $this->client->post('contents/generations/tasks', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => $requestBody,
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (isset($result['id'])) {
                return [
                    'success' => true,
                    'task_id' => (string) $result['id'],
                    'message' => 'Seedance task created. Processing...',
                ];
            }

            throw new Exception($this->extractErrorMessage($result, 'Failed to create Seedance task'));
        } catch (RequestException $e) {
            $responseBody = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';
            $errorData = json_decode($responseBody, true);
            $errorMsg = $this->extractErrorMessage(is_array($errorData) ? $errorData : [], $e->getMessage());

            Log::error('Seedance (ModelArk): Request failed', [
                'error' => $e->getMessage(),
                'response' => $responseBody,
            ]);

            return [
                'success' => false,
                'error' => (string) $errorMsg,
            ];
        } catch (Exception $e) {
            Log::error('Seedance (ModelArk): Failed to create task', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{success: bool, status?: string, task_id?: string, video_url?: string, error?: string}
     */
    public function getTaskStatus(string $generationId): array
    {
        if ($this->apiKey === '') {
            return [
                'success' => false,
                'error' => 'SEEDANCE_API_KEY chưa được cấu hình.',
            ];
        }

        try {
            $response = $this->client->get('contents/generations/tasks/' . $generationId, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
            ]);

            $result = json_decode($response->getBody()->getContents(), true);
            $rawStatus = strtolower((string) ($result['status'] ?? 'unknown'));
            $status = $this->mapStatusToLegacy($rawStatus);

            $responseData = [
                'success' => true,
                'status' => $status,
                'raw_status' => $rawStatus,
                'task_id' => $generationId,
            ];

            if ($status === 'completed') {
                if (isset($result['content']['video_url'])) {
                    $responseData['video_url'] = $result['content']['video_url'];
                } elseif (isset($result['video_url'])) {
                    $responseData['video_url'] = $result['video_url'];
                } elseif (isset($result['video']['url'])) {
                    $responseData['video_url'] = $result['video']['url'];
                } elseif (isset($result['output']['video_url'])) {
                    $responseData['video_url'] = $result['output']['video_url'];
                }
            }

            if ($status === 'failed') {
                $responseData['error'] = $this->extractErrorMessage($result, 'Task failed');
            }

            return $responseData;
        } catch (RequestException $e) {
            $responseBody = $e->hasResponse() ? (string) $e->getResponse()->getBody() : '';
            $errorData = json_decode($responseBody, true);

            Log::error('Seedance (ModelArk): Failed to get task status', [
                'generation_id' => $generationId,
                'error' => $e->getMessage(),
                'response' => $responseBody,
            ]);

            return [
                'success' => false,
                'error' => $this->extractErrorMessage(is_array($errorData) ? $errorData : [], $e->getMessage()),
            ];
        } catch (Exception $e) {
            Log::error('Seedance (ModelArk): Failed to get task status', [
                'generation_id' => $generationId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{success: bool, path?: string, url?: string, error?: string}
     */
    public function downloadVideo(string $videoUrl, int $bookId, string $filename): array
    {
        try {
            $videoContent = file_get_contents($videoUrl);
            if ($videoContent === false) {
                throw new Exception('Failed to download video');
            }

            $outputDir = "books/{$bookId}/animations";
            $relativePath = "{$outputDir}/{$filename}";

            Storage::disk('public')->makeDirectory($outputDir);
            Storage::disk('public')->put($relativePath, $videoContent);

            return [
                'success' => true,
                'path' => $relativePath,
                'url' => asset('storage/' . $relativePath),
            ];
        } catch (Exception $e) {
            Log::error('Seedance (ModelArk): Failed to download video', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function mapStatusToLegacy(string $rawStatus): string
    {
        if ($rawStatus === 'succeeded' || $rawStatus === 'completed') {
            return 'completed';
        }

        if ($rawStatus === 'failed' || $rawStatus === 'expired' || $rawStatus === 'canceled' || $rawStatus === 'cancelled') {
            return 'failed';
        }

        return 'processing';
    }

    private function extractErrorMessage(array $payload, string $fallback): string
    {
        $error = $payload['error'] ?? null;
        if (is_string($error) && trim($error) !== '') {
            return $error;
        }

        if (is_array($error)) {
            $errorMessage = $error['message'] ?? $error['code'] ?? null;
            if (is_string($errorMessage) && trim($errorMessage) !== '') {
                return $errorMessage;
            }

            $encoded = json_encode($error, JSON_UNESCAPED_UNICODE);
            if (is_string($encoded) && $encoded !== '') {
                return $encoded;
            }
        }

        $message = $payload['message'] ?? null;
        if (is_string($message) && trim($message) !== '') {
            return $message;
        }

        return $fallback;
    }
}
