<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class HeyGenLipsyncService
{
    private $apiKey;
    private $baseUrl;

    public function __construct()
    {
        $this->apiKey = env('HEYGEN_API_KEY');
        $this->baseUrl = env('HEYGEN_BASE_URL', 'https://api.heygen.com');
    }

    /**
     * Generate lip-sync video from a photo + audio using HeyGen's v3 API (POST /v3/videos,
     * type: "image"). The old v1/v2 endpoints this originally targeted were never real paths
     * (404'd on first real use) — v2's actual equivalent additionally required a separate
     * "talking photo" upload step to get a talking_photo_id before video creation; v3's
     * type:"image" flow accepts a direct image URL instead, no upload step needed, which is
     * also the path HeyGen's own API steers new integrations toward (v1/v2 sunset 2026-10-31).
     *
     * @param string $audioPath Storage path or public URL to the audio file
     * @param string $imagePath Storage path or public URL to the avatar image
     * @param array $options Additional options
     * @return array ['video_url' => string, 'video_id' => string]
     */
    public function generateVideo($audioPath, $imagePath, $options = [])
    {
        if (!$this->apiKey) {
            throw new \Exception('HeyGen API key not configured. Please set HEYGEN_API_KEY in .env file.');
        }

        $audioUrl = $this->resolvePublicUrl($audioPath, 'audio');
        $imageUrl = $this->resolvePublicUrl($imagePath, 'image');

        $payload = $this->buildPayload($audioUrl, $imageUrl, $options);

        Log::info('Creating HeyGen video', [
            'endpoint' => $this->baseUrl . '/v3/videos',
        ]);

        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])->post($this->baseUrl . '/v3/videos', $payload);

        if (!$response->successful()) {
            throw new \Exception('Failed to create HeyGen video: ' . $response->body());
        }

        $data = $response->json();
        $videoId = $data['data']['video_id'] ?? null;

        if (!$videoId) {
            throw new \Exception('HeyGen response missing video_id: ' . $response->body());
        }

        $result = $this->waitForCompletion($videoId);

        return [
            'video_url' => $result['video_url'],
            'video_id' => $videoId,
            'duration' => $result['duration'] ?? null
        ];
    }

    /**
     * Download video from HeyGen and save to local storage
     */
    public function downloadVideo($videoUrl, $savePath)
    {
        Log::info('Downloading HeyGen video', ['url' => $videoUrl, 'path' => $savePath]);

        $directory = dirname($savePath);
        $fullDirectory = storage_path('app/public/' . $directory);
        if (!is_dir($fullDirectory)) {
            mkdir($fullDirectory, 0755, true);
        }

        $videoContent = file_get_contents($videoUrl);
        if ($videoContent === false) {
            throw new \Exception('Failed to download video from HeyGen');
        }

        $fullPath = storage_path('app/public/' . $savePath);
        file_put_contents($fullPath, $videoContent);

        return $savePath;
    }

    private function buildPayload($audioUrl, $imageUrl, $options)
    {
        $payload = [
            'type' => 'image',
            'image' => [
                'type' => 'url',
                'url' => $imageUrl,
            ],
            'audio_url' => $audioUrl,
            'output_format' => 'mp4',
        ];

        if (!empty($options['resolution'])) {
            $payload['resolution'] = $options['resolution'];
        }
        if (!empty($options['aspect_ratio'])) {
            $payload['aspect_ratio'] = $options['aspect_ratio'];
        }

        return $payload;
    }

    private function waitForCompletion($videoId, $maxAttempts = 60, $pollInterval = 5)
    {
        Log::info('Waiting for HeyGen video completion', ['video_id' => $videoId]);

        for ($i = 0; $i < $maxAttempts; $i++) {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
            ])->get($this->baseUrl . '/v3/videos/' . $videoId);

            if (!$response->successful()) {
                throw new \Exception('Failed to check HeyGen status: ' . $response->body());
            }

            $data = $response->json();
            $status = $data['data']['status'] ?? null;

            Log::info('HeyGen status', [
                'video_id' => $videoId,
                'status' => $status,
                'attempt' => $i + 1,
            ]);

            if ($status === 'completed') {
                $videoUrl = $data['data']['video_url'] ?? null;
                if (!$videoUrl) {
                    throw new \Exception('HeyGen completed but missing video_url');
                }

                return [
                    'video_url' => $videoUrl,
                    'duration' => $data['data']['duration'] ?? null,
                ];
            }

            if ($status === 'failed') {
                $error = $data['data']['error'] ?? 'Unknown error';
                throw new \Exception('HeyGen video generation failed: ' . (is_array($error) ? json_encode($error) : $error));
            }

            sleep($pollInterval);
        }

        throw new \Exception('HeyGen video generation timeout after ' . ($maxAttempts * $pollInterval) . ' seconds');
    }

    private function resolvePublicUrl($pathOrUrl, $type)
    {
        if (filter_var($pathOrUrl, FILTER_VALIDATE_URL)) {
            return $pathOrUrl;
        }

        $publicRoot = storage_path('app/public/');
        if (str_starts_with($pathOrUrl, $publicRoot)) {
            $relative = ltrim(str_replace($publicRoot, '', $pathOrUrl), DIRECTORY_SEPARATOR);
            return rtrim(config('app.url'), '/') . Storage::url($relative);
        }

        $normalized = ltrim($pathOrUrl, '/');
        if (Storage::disk('public')->exists($normalized)) {
            return rtrim(config('app.url'), '/') . Storage::url($normalized);
        }

        throw new \Exception("{$type} file is not publicly accessible: {$pathOrUrl}");
    }
}
