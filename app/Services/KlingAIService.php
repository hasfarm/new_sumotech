<?php

namespace App\Services;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class KlingAIService
{
    private string $apiKey;
    private string $baseUrl;
    private Client $client;

    public function __construct()
    {
        $this->apiKey = (string) config('services.aiml.api_key', '');
        $configuredBaseUrl = rtrim((string) config('services.aiml.base_url', 'https://api.aimlapi.com'), '/');
        $this->baseUrl = str_ends_with($configuredBaseUrl, '/v2') ? $configuredBaseUrl : ($configuredBaseUrl . '/v2');

        $this->client = new Client([
            'base_uri' => $this->baseUrl . '/',
            'timeout' => 120,
            'verify' => false
        ]);
    }

    /**
     * Create image-to-video animation task via AIML API
     * 
     * @param string $imagePath Path to the image file (relative to public storage)
     * @param string|null $prompt Animation prompt
     * @param array $options Additional options
     * @return array{success: bool, task_id?: string, error?: string}
     */
    public function createImageToVideoTask(string $imagePath, ?string $prompt = null, array $options = []): array
    {
        try {
            Log::info('KlingAI (AIML): Creating image-to-video task', [
                'image_path' => $imagePath
            ]);

            // Convert image to base64 data URI (since local URL won't be accessible by AIML)
            $fullPath = storage_path('app/public/' . $imagePath);
            if (!file_exists($fullPath)) {
                throw new Exception("Image file not found: {$imagePath}");
            }

            $imageData = file_get_contents($fullPath);
            $mimeType = mime_content_type($fullPath) ?: 'image/png';
            $base64Image = 'data:' . $mimeType . ';base64,' . base64_encode($imageData);

            // Default prompt for subtle animation
            $defaultPrompt = "Subtle ambient animation with gentle movements: soft smoke or mist drifting slowly, flickering candlelight or lamp glow, slight hair or fabric movement from breeze, gentle eye blinking, subtle breathing motion. Keep the scene peaceful and dreamy, suitable for audiobook background.";

            // AIML API request body for Kling - use image as base64 data URI
            $requestBody = [
                'model' => $options['model'] ?? 'kling-video/v1.6/standard/image-to-video',
                'image_url' => $base64Image,
                'prompt' => $prompt ?? $defaultPrompt,
                'duration' => (string)($options['duration'] ?? '5'), // Must be string
            ];

            Log::info('KlingAI (AIML): Sending request with base64 image', [
                'endpoint' => '/video/generations',
                'model' => $requestBody['model'],
                'image_size' => strlen($imageData) . ' bytes',
                'duration' => $requestBody['duration']
            ]);

            $response = $this->client->post('/video/generations', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                ],
                'json' => $requestBody
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            Log::info('KlingAI (AIML): Response received', [
                'result' => $result
            ]);

            if (isset($result['id'])) {
                $generationId = $result['id'];

                Log::info('KlingAI (AIML): Task created successfully', [
                    'generation_id' => $generationId
                ]);

                return [
                    'success' => true,
                    'task_id' => $generationId,
                    'message' => 'Animation task created. Processing...'
                ];
            }

            throw new Exception($result['error'] ?? $result['message'] ?? 'Failed to create animation task');
        } catch (RequestException $e) {
            $responseBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : '';
            Log::error('KlingAI (AIML): Request failed', [
                'error' => $e->getMessage(),
                'response' => $responseBody
            ]);

            $errorData = json_decode($responseBody, true);
            $errorMsg = $errorData['message'] ?? $errorData['error'] ?? $e->getMessage();
            if (is_array($errorMsg)) {
                $errorMsg = json_encode($errorMsg);
            }
            return [
                'success' => false,
                'error' => $errorMsg
            ];
        } catch (Exception $e) {
            Log::error('KlingAI (AIML): Failed to create task', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Check task status and get result via AIML API
     * 
     * @param string $generationId Generation ID from createImageToVideoTask
     * @return array{success: bool, status?: string, video_url?: string, error?: string}
     */
    public function getTaskStatus(string $generationId): array
    {
        try {
            Log::info('KlingAI (AIML): Checking task status', [
                'generation_id' => $generationId
            ]);

            $response = $this->client->get('/video/generations', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->apiKey,
                    'Content-Type' => 'application/json'
                ],
                'query' => [
                    'generation_id' => $generationId
                ]
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            Log::info('KlingAI (AIML): Status response', [
                'result' => $result
            ]);

            $status = $result['status'] ?? 'unknown';

            $responseData = [
                'success' => true,
                'status' => $status,
                'task_id' => $generationId
            ];

            // If completed, get video URL
            if ($status === 'completed') {
                // Check different possible response structures
                if (isset($result['video_url'])) {
                    $responseData['video_url'] = $result['video_url'];
                } elseif (isset($result['video']['url'])) {
                    $responseData['video_url'] = $result['video']['url'];
                } elseif (isset($result['output']['video_url'])) {
                    $responseData['video_url'] = $result['output']['video_url'];
                }
            }

            // If failed
            if ($status === 'failed') {
                $responseData['error'] = $result['error'] ?? $result['message'] ?? 'Task failed';
            }

            return $responseData;
        } catch (RequestException $e) {
            $responseBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : '';
            Log::error('KlingAI (AIML): Failed to get task status', [
                'generation_id' => $generationId,
                'error' => $e->getMessage(),
                'response' => $responseBody
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        } catch (Exception $e) {
            Log::error('KlingAI (AIML): Failed to get task status', [
                'generation_id' => $generationId,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Download video from URL and save locally
     * 
     * @param string $videoUrl Remote video URL
     * @param int $bookId Book ID for organizing files
     * @param string $filename Output filename
     * @return array{success: bool, path?: string, url?: string, error?: string}
     */
    public function downloadVideo(string $videoUrl, int $bookId, string $filename): array
    {
        try {
            Log::info('KlingAI (AIML): Downloading video', [
                'video_url' => $videoUrl,
                'book_id' => $bookId
            ]);

            // Download video content
            $videoContent = file_get_contents($videoUrl);
            if ($videoContent === false) {
                throw new Exception('Failed to download video');
            }

            // Save to storage
            $outputDir = "books/{$bookId}/animations";
            $relativePath = "{$outputDir}/{$filename}";

            Storage::disk('public')->makeDirectory($outputDir);
            Storage::disk('public')->put($relativePath, $videoContent);

            Log::info('KlingAI (AIML): Video saved successfully', [
                'path' => $relativePath
            ]);

            return [
                'success' => true,
                'path' => $relativePath,
                'url' => asset('storage/' . $relativePath)
            ];
        } catch (Exception $e) {
            Log::error('KlingAI (AIML): Failed to download video', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Create animation and wait for result (synchronous)
     * This polls the API until complete or timeout
     * 
     * @param string $imagePath Image path
     * @param int $bookId Book ID
     * @param string|null $prompt Custom prompt
     * @param int $maxWaitSeconds Maximum wait time (default 10 minutes)
     * @return array
     */
    public function createAnimationSync(string $imagePath, int $bookId, ?string $prompt = null, int $maxWaitSeconds = 600): array
    {
        // Create task
        $taskResult = $this->createImageToVideoTask($imagePath, $prompt);

        if (!$taskResult['success']) {
            return $taskResult;
        }

        $generationId = $taskResult['task_id'];
        $startTime = time();

        // Poll for result every 15 seconds
        while ((time() - $startTime) < $maxWaitSeconds) {
            sleep(15);

            $statusResult = $this->getTaskStatus($generationId);

            if (!$statusResult['success']) {
                continue; // Retry on error
            }

            $status = $statusResult['status'] ?? 'unknown';

            // If completed
            if ($status === 'completed' && !empty($statusResult['video_url'])) {
                $timestamp = time();
                $filename = "anim_{$timestamp}.mp4";

                $downloadResult = $this->downloadVideo(
                    $statusResult['video_url'],
                    $bookId,
                    $filename
                );

                if ($downloadResult['success']) {
                    return [
                        'success' => true,
                        'path' => $downloadResult['path'],
                        'url' => $downloadResult['url'],
                        'generation_id' => $generationId
                    ];
                }

                return $downloadResult;
            }

            // If failed
            if ($status === 'failed') {
                return [
                    'success' => false,
                    'error' => $statusResult['error'] ?? 'Animation generation failed'
                ];
            }

            // Still processing (queued, generating), continue polling
        }

        return [
            'success' => false,
            'error' => 'Timeout: Animation generation took too long'
        ];
    }
}
