<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * ElevenLabs Music API — generally-available (not preview/experimental, unlike Gemini Lyria 3
 * at the time this was researched), millisecond-precise duration control, same xi-api-key auth
 * as ElevenLabsSoundEffectsService. See the Audio Direction Pipeline plan for why ElevenLabs
 * was chosen over Lyria for music generation.
 */
class ElevenLabsMusicService
{
    private const ENDPOINT = '/v1/music';
    private const MIN_LENGTH_MS = 3000;
    private const MAX_LENGTH_MS = 600000;

    /**
     * @return array{bytes:string,requested_duration_seconds:float,latency_ms:int,credits_used:?int,provider_request_id:?string}
     */
    public function generate(string $prompt, int $lengthMs): array
    {
        $apiKey = (string) config('services.elevenlabs.api_key', '');
        if ($apiKey === '') {
            throw new RuntimeException('Missing ELEVENLAB_API_KEY.');
        }

        $lengthMs = max(self::MIN_LENGTH_MS, min(self::MAX_LENGTH_MS, $lengthMs));
        $baseUrl = rtrim((string) config('services.elevenlabs.base_url', 'https://api.elevenlabs.io'), '/');

        $start = microtime(true);
        $response = Http::withHeaders(['xi-api-key' => $apiKey])
            ->timeout(120)
            ->post($baseUrl . self::ENDPOINT, [
                'prompt' => $prompt,
                'music_length_ms' => $lengthMs,
            ]);
        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        if (!$response->successful()) {
            throw new RuntimeException('ElevenLabs music-generation thất bại: ' . $this->extractError($response));
        }

        $contentType = (string) $response->header('Content-Type');
        if (!str_starts_with($contentType, 'audio/')) {
            throw new RuntimeException('ElevenLabs music-generation trả về nội dung không phải audio: ' . $this->extractError($response));
        }

        return [
            'bytes' => $response->body(),
            'requested_duration_seconds' => $lengthMs / 1000,
            'latency_ms' => $latencyMs,
            // The Music endpoint has no cost header (unlike Sound Effects' character-cost) —
            // ElevenLabs doesn't expose per-call credit cost for this endpoint synchronously.
            'credits_used' => null,
            'provider_request_id' => $response->header('song-id') ?: $response->header('x-trace-id'),
        ];
    }

    private function extractError(Response $response): string
    {
        $json = $response->json();
        if (is_array($json)) {
            $detail = data_get($json, 'detail.message') ?? data_get($json, 'detail') ?? data_get($json, 'message');
            if (is_string($detail) && trim($detail) !== '') {
                return $detail . ' (HTTP ' . $response->status() . ')';
            }
        }

        return 'HTTP ' . $response->status();
    }
}
