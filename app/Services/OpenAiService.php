<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around OpenAI's chat completions API for structured-JSON tasks — same
 * complete()/completeJson() interface shape as App\Services\ClaudeService, so callers can
 * swap providers with a constructor-injection change only.
 */
class OpenAiService
{
    private const DEFAULT_MODEL = 'gpt-5-mini';
    private const API_URL = 'https://api.openai.com/v1/chat/completions';

    // Placeholder rate — gpt-5-mini isn't on OpenAI's current public pricing page (only
    // newer gpt-5.4-mini/nano are listed there), so this is NOT a verified price. Logged
    // costs using this constant are clearly an estimate, not a bill-matching figure, until
    // real billing data confirms the actual rate. Override via .env once known.
    private const ESTIMATED_INPUT_PER_1M = 0.75;
    private const ESTIMATED_OUTPUT_PER_1M = 4.50;

    /**
     * @param array{model?:string,max_tokens?:int,timeout?:int,reasoning_effort?:string,json?:bool,json_schema?:array,purpose?:string} $options
     */
    public function complete(string $prompt, array $options = []): string
    {
        $apiKey = trim((string) config('services.openai.api_key', ''));
        if ($apiKey === '') {
            throw new \RuntimeException('Thiếu OPENAI_API_KEY trong cấu hình runtime.');
        }

        $model = $options['model'] ?? self::DEFAULT_MODEL;

        $body = [
            'model' => $model,
            // NOTE: gpt-5-mini rejects non-default `temperature` with a 400 — do not add it.
            // max_completion_tokens (NOT max_tokens) also has to cover this model's invisible
            // reasoning tokens on top of the visible output — budget generously.
            'max_completion_tokens' => $options['max_tokens'] ?? 16000,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ];

        // Cuts invisible reasoning-token spend for tasks that don't need deep reasoning —
        // verified live: "minimal" drops reasoning_tokens to 0 on a simple prompt (vs 64 at
        // the model's default effort).
        if (!empty($options['reasoning_effort'])) {
            $body['reasoning_effort'] = $options['reasoning_effort'];
        }

        if (!empty($options['json_schema'])) {
            $body['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => $options['json_schema'],
            ];
        } elseif (!empty($options['json'])) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        // A single call for a large/complex structured-analysis prompt can legitimately take
        // 300s+ of invisible reasoning (measured up to ~330s on real content) — a short
        // per-attempt timeout means retries mostly fail by cutting off an in-progress
        // response rather than recovering from a real transient error, so success ends up
        // depending on luck (whichever attempt happens to run faster than usual). Give each
        // attempt real room to finish instead. (Smaller reasoning_effort + smaller per-call
        // shot count should make this a non-issue in practice — kept generous as a backstop.)
        $start = microtime(true);
        $response = Http::withToken($apiKey)
            ->connectTimeout(15)
            ->timeout($options['timeout'] ?? 360)
            ->retry(2, 5000)
            ->post(self::API_URL, $body);
        $durationSeconds = round(microtime(true) - $start, 2);

        $usage = (array) data_get($response->json(), 'usage', []);
        $this->logUsage($model, $prompt, $options, $usage, $durationSeconds, $response->successful());

        if (!$response->successful()) {
            $shortBody = mb_substr(trim((string) $response->body()), 0, 500);
            throw new \RuntimeException('OpenAI API lỗi HTTP ' . $response->status() . ($shortBody !== '' ? (': ' . $shortBody) : ''));
        }

        $finishReason = (string) data_get($response->json(), 'choices.0.finish_reason', '');
        $text = trim((string) data_get($response->json(), 'choices.0.message.content', ''));

        if ($finishReason === 'length') {
            throw new \RuntimeException('OpenAI bị cắt nội dung do vượt giới hạn token đầu ra (finish_reason: length) — model có thể đã dùng hết ngân sách cho reasoning ẩn.');
        }

        if ($text === '') {
            throw new \RuntimeException('OpenAI không trả về nội dung.' . ($finishReason ? " (finish_reason: {$finishReason})" : ''));
        }

        return $text;
    }

    /**
     * @param array{model?:string,max_tokens?:int,timeout?:int,reasoning_effort?:string,json_schema?:array,purpose?:string} $options
     * @return array<string,mixed>
     */
    public function completeJson(string $prompt, array $options = []): array
    {
        $text = $this->complete($prompt, array_merge($options, ['json' => true]));

        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            $cleaned = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?: $text;
            $decoded = json_decode(trim($cleaned), true);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('OpenAI trả về nội dung không phải JSON hợp lệ.');
        }

        return $decoded;
    }

    /**
     * @param array<string,mixed> $options
     * @param array<string,mixed> $usage
     */
    private function logUsage(string $model, string $prompt, array $options, array $usage, float $durationSeconds, bool $successful): void
    {
        $promptTokens = (int) ($usage['prompt_tokens'] ?? 0);
        $completionTokens = (int) ($usage['completion_tokens'] ?? 0);
        $reasoningTokens = (int) data_get($usage, 'completion_tokens_details.reasoning_tokens', 0);

        $estimatedCost = ($promptTokens / 1_000_000 * self::ESTIMATED_INPUT_PER_1M)
            + ($completionTokens / 1_000_000 * self::ESTIMATED_OUTPUT_PER_1M);

        try {
            ApiUsageService::log([
                'api_type' => 'OpenAI',
                'api_endpoint' => 'chat/completions',
                'purpose' => $options['purpose'] ?? 'video_pipeline_analysis',
                'description' => sprintf(
                    'model=%s reasoning_effort=%s prompt_tokens=%d completion_tokens=%d reasoning_tokens=%d',
                    $model,
                    $options['reasoning_effort'] ?? 'default',
                    $promptTokens,
                    $completionTokens,
                    $reasoningTokens
                ),
                'status' => $successful ? 'success' : 'failed',
                'characters_used' => mb_strlen($prompt),
                'tokens_used' => $promptTokens + $completionTokens,
                'duration_seconds' => $durationSeconds,
                'estimated_cost' => round($estimatedCost, 6),
                'request_data' => ['model' => $model, 'reasoning_effort' => $options['reasoning_effort'] ?? null],
                'response_data' => ['usage' => $usage],
            ]);
        } catch (\Throwable $e) {
            // Metrics logging must never break the actual pipeline.
        }
    }
}
