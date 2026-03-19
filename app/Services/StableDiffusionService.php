<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * Service for generating images using Stable Diffusion WebUI API (Automatic1111).
 * API docs: http://127.0.0.1:7860/docs
 */
class StableDiffusionService
{
    private Client $client;
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.stable_diffusion.base_url', 'http://127.0.0.1:7860'), '/');
        $this->client = new Client([
            'timeout' => 300, // SD can be slow
            'connect_timeout' => 10,
        ]);
    }

    /**
     * Generate image from text prompt using txt2img API.
     *
     * @param string $prompt The image generation prompt
     * @param string $outputPath Path to save the generated image
     * @param string $aspectRatio Aspect ratio: '16:9', '1:1', '9:16'
     * @param string|null $negativePrompt Negative prompt
     * @return array ['success' => bool, 'image_path' => string|null, 'error' => string|null]
     */
    public function generateImage(
        string $prompt,
        string $outputPath,
        string $aspectRatio = '16:9',
        ?string $negativePrompt = null
    ): array {
        [$width, $height] = $this->getResolution($aspectRatio);

        $negativePrompt = $negativePrompt ?? config(
            'services.stable_diffusion.negative_prompt',
            'lowres, bad anatomy, bad hands, text, error, missing fingers, extra digit, fewer digits, cropped, worst quality, low quality, normal quality, jpeg artifacts, signature, watermark, username, blurry, deformed'
        );

        $payload = [
            'prompt' => $prompt,
            'negative_prompt' => $negativePrompt,
            'width' => $width,
            'height' => $height,
            'steps' => (int) config('services.stable_diffusion.steps', 28),
            'cfg_scale' => (float) config('services.stable_diffusion.cfg_scale', 7),
            'sampler_name' => config('services.stable_diffusion.sampler', 'DPM++ 2M'),
            'scheduler' => config('services.stable_diffusion.scheduler', 'Karras'),
            'batch_size' => 1,
            'n_iter' => 1,
            'seed' => -1,
        ];

        try {
            $response = $this->client->post("{$this->baseUrl}/sdapi/v1/txt2img", [
                'json' => $payload,
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            if (empty($result['images'][0])) {
                return ['success' => false, 'image_path' => null, 'error' => 'No image returned from SD API'];
            }

            // Decode base64 image
            $imageData = base64_decode($result['images'][0]);
            if (!$imageData) {
                return ['success' => false, 'image_path' => null, 'error' => 'Failed to decode image data'];
            }

            // Ensure output directory exists
            $dir = dirname($outputPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            file_put_contents($outputPath, $imageData);

            Log::info("SD image generated", [
                'prompt' => mb_substr($prompt, 0, 100),
                'size' => "{$width}x{$height}",
                'output' => $outputPath,
            ]);

            return ['success' => true, 'image_path' => $outputPath, 'error' => null];
        } catch (\Exception $e) {
            Log::error("SD image generation failed: " . $e->getMessage());
            return ['success' => false, 'image_path' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Check if the SD WebUI API is available.
     */
    public function isAvailable(): bool
    {
        try {
            $response = $this->client->get("{$this->baseUrl}/sdapi/v1/options", [
                'timeout' => 5,
                'connect_timeout' => 3,
            ]);
            return $response->getStatusCode() === 200;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get current model info from SD WebUI.
     */
    public function getCurrentModel(): ?string
    {
        try {
            $response = $this->client->get("{$this->baseUrl}/sdapi/v1/options", [
                'timeout' => 5,
            ]);
            $data = json_decode($response->getBody()->getContents(), true);
            return $data['sd_model_checkpoint'] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get available models.
     */
    public function getModels(): array
    {
        try {
            $response = $this->client->get("{$this->baseUrl}/sdapi/v1/sd-models", [
                'timeout' => 10,
            ]);
            return json_decode($response->getBody()->getContents(), true) ?: [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Get resolution from aspect ratio.
     */
    private function getResolution(string $aspectRatio): array
    {
        return match ($aspectRatio) {
            '16:9' => [1024, 576],
            '9:16' => [576, 1024],
            '1:1' => [768, 768],
            '4:3' => [896, 672],
            '3:4' => [672, 896],
            default => [1024, 576],
        };
    }
}
