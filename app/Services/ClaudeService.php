<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ClaudeService
{
    private const DEFAULT_MODEL = 'claude-sonnet-5';
    private const API_URL = 'https://api.anthropic.com/v1/messages';

    /**
     * @param array{model?:string,max_tokens?:int,timeout?:int} $options
     */
    public function complete(string $prompt, array $options = []): string
    {
        $apiKey = trim((string) config('services.claude.api_key', ''));
        if ($apiKey === '') {
            throw new \RuntimeException('Thiếu CLAUDE_API_KEY trong cấu hình runtime.');
        }

        $model = $options['model'] ?? (string) config('services.claude.model', self::DEFAULT_MODEL);

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])
            ->connectTimeout(15)
            ->timeout($options['timeout'] ?? 180)
            // Claude's response time scales with extended-thinking length, which can vary
            // a lot for the same prompt shape — 4 attempts with growing backoff gives real
            // transient slow-patches (network or Anthropic-side) room to clear instead of
            // failing the whole pipeline step after one bad window.
            ->retry(4, 5000)
            ->post(self::API_URL, [
                'model' => $model,
                // NOTE: claude-sonnet-5 rejects `temperature` with a 400 (deprecated for
                // this model) — do not add it back without re-verifying against the API.
                'max_tokens' => $options['max_tokens'] ?? 4096,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]);

        if (!$response->successful()) {
            $shortBody = mb_substr(trim((string) $response->body()), 0, 500);
            throw new \RuntimeException('Claude API lỗi HTTP ' . $response->status() . ($shortBody !== '' ? (': ' . $shortBody) : ''));
        }

        $stopReason = (string) data_get($response->json(), 'stop_reason', '');
        $text = trim($this->extractText($response->json('content', [])));

        if ($stopReason === 'max_tokens') {
            throw new \RuntimeException('Claude bị cắt nội dung do vượt giới hạn token đầu ra (stop_reason: max_tokens).');
        }

        if ($text === '') {
            throw new \RuntimeException('Claude không trả về nội dung.' . ($stopReason ? " (stop_reason: {$stopReason})" : ''));
        }

        return $text;
    }

    /**
     * Claude Sonnet 5 auto-enables extended thinking for non-trivial prompts, which prepends
     * a {"type":"thinking",...} block with no "text" key before the real {"type":"text",...}
     * block — the actual answer is NOT reliably at content[0], so scan for the text block(s)
     * instead of assuming position.
     *
     * @param array<int,array<string,mixed>> $contentBlocks
     */
    private function extractText(array $contentBlocks): string
    {
        $parts = [];
        foreach ($contentBlocks as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text') {
                $parts[] = (string) ($block['text'] ?? '');
            }
        }

        return implode('', $parts);
    }

    /**
     * @param array{model?:string,max_tokens?:int,timeout?:int} $options
     * @return array<string,mixed>
     */
    public function completeJson(string $prompt, array $options = []): array
    {
        $text = $this->complete($prompt, $options);

        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            $cleaned = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?: $text;
            $decoded = json_decode(trim($cleaned), true);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('Claude trả về nội dung không phải JSON hợp lệ.');
        }

        return $decoded;
    }
}
