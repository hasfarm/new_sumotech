<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * ElevenLabs Sound Effects API — covers BOTH one-shot SFX (loop=false, short) and loopable
 * ambience beds (loop=true, longer duration) via the SAME endpoint; see the Audio Direction
 * Pipeline plan for why one provider/endpoint covers both categories instead of a separate
 * ambience-specific one.
 */
class ElevenLabsSoundEffectsService
{
    private const ENDPOINT = '/v1/sound-generation';
    private const MIN_DURATION = 0.5;
    private const MAX_DURATION = 30.0;

    /**
     * @return array{bytes:string,requested_duration_seconds:?float,latency_ms:int,credits_used:?int,provider_request_id:?string}
     */
    public function generate(string $prompt, ?float $durationSeconds = null, bool $loop = false, float $promptInfluence = 0.3): array
    {
        $apiKey = (string) config('services.elevenlabs.api_key', '');
        if ($apiKey === '') {
            throw new RuntimeException('Missing ELEVENLAB_API_KEY.');
        }

        $requestedDuration = $durationSeconds !== null ? max(self::MIN_DURATION, min(self::MAX_DURATION, $durationSeconds)) : null;

        $body = [
            'text' => $prompt,
            'prompt_influence' => max(0.0, min(1.0, $promptInfluence)),
            'loop' => $loop,
        ];
        if ($requestedDuration !== null) {
            $body['duration_seconds'] = $requestedDuration;
        }

        $baseUrl = rtrim((string) config('services.elevenlabs.base_url', 'https://api.elevenlabs.io'), '/');

        $start = microtime(true);
        $response = Http::withHeaders(['xi-api-key' => $apiKey])
            ->timeout(60)
            ->post($baseUrl . self::ENDPOINT, $body);
        $latencyMs = (int) round((microtime(true) - $start) * 1000);

        if (!$response->successful()) {
            throw new RuntimeException('ElevenLabs sound-generation thất bại: ' . $this->extractError($response));
        }

        $contentType = (string) $response->header('Content-Type');
        if (!str_starts_with($contentType, 'audio/')) {
            throw new RuntimeException('ElevenLabs sound-generation trả về nội dung không phải audio: ' . $this->extractError($response));
        }

        $creditsHeader = $response->header('character-cost');

        return [
            'bytes' => $response->body(),
            'requested_duration_seconds' => $requestedDuration,
            'latency_ms' => $latencyMs,
            'credits_used' => $creditsHeader !== null ? (int) $creditsHeader : null,
            // ElevenLabs doesn't log sound-generation/music calls in /v1/history, and has no
            // dedicated "request id" field for this endpoint — x-trace-id is the closest
            // equivalent, minted fresh per HTTP call (not retrievable after the fact).
            'provider_request_id' => $response->header('x-trace-id'),
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
