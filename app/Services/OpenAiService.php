<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
     * @param array{model?:string,max_tokens?:int,timeout?:int,reasoning_effort?:string,json?:bool,json_schema?:array,purpose?:string,retry?:bool,log_context?:array} $options
     *
     * `retry` (default true) controls the client-level `Http::retry(2, 5000)` guard against
     * connection blips. Callers that already own their own retry/backoff loop (e.g.
     * EnrichVideoShotsJob, chunk-level 5s/15s/45s backoff) must pass `retry: false` — otherwise
     * a single job attempt could silently fire up to 3 real HTTP requests, breaking the
     * "one job attempt = one HTTP request" invariant fault-injection tests rely on.
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

        $start = microtime(true);
        $response = null;
        $caught = null;

        try {
            $pending = Http::withToken($apiKey)
                ->connectTimeout(15)
                ->timeout($options['timeout'] ?? 360);

            if ($options['retry'] ?? true) {
                $pending = $pending->retry(2, 5000);
            }

            // A single call for a large/complex structured-analysis prompt can legitimately
            // take 300s+ of invisible reasoning (measured up to ~330s on real content) — a
            // short per-attempt timeout means retries mostly fail by cutting off an
            // in-progress response rather than recovering from a real transient error, so
            // success ends up depending on luck. Give each attempt real room to finish
            // instead. This call makes exactly ONE HTTP request when `retry` is disabled.
            $response = $pending->post(self::API_URL, $body);

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
        } catch (\Throwable $e) {
            $caught = $e;
            throw $e;
        } finally {
            // Always write exactly one api_usages row per call attempt, success or failure —
            // including connection-level failures that never produced a $response at all.
            $durationSeconds = round(microtime(true) - $start, 2);
            $this->logUsage($model, $prompt, $options, $response, $caught, $durationSeconds);
        }
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
     * Called exactly once per complete() call (success, HTTP failure, or connection-level
     * exception — see the try/catch/finally in complete()) so every real call to OpenAI
     * produces exactly one api_usages row.
     *
     * @param array<string,mixed> $options
     */
    private function logUsage(string $model, string $prompt, array $options, ?Response $response, ?\Throwable $exception, float $durationSeconds): void
    {
        $usage = $response ? (array) data_get($response->json(), 'usage', []) : [];
        $hasUsage = !empty($usage);
        $successful = $exception === null;

        // No usage payload means OpenAI never billed/returned token counts for this call
        // (e.g. connection error, timeout, or non-2xx before a body could be parsed) — record
        // that honestly as null rather than guessing at a cost.
        $promptTokens = $hasUsage ? (int) ($usage['prompt_tokens'] ?? 0) : null;
        $completionTokens = $hasUsage ? (int) ($usage['completion_tokens'] ?? 0) : null;
        $reasoningTokens = $hasUsage ? (int) data_get($usage, 'completion_tokens_details.reasoning_tokens', 0) : null;

        $estimatedCost = $hasUsage
            ? round(($promptTokens / 1_000_000 * self::ESTIMATED_INPUT_PER_1M) + ($completionTokens / 1_000_000 * self::ESTIMATED_OUTPUT_PER_1M), 6)
            : null;

        $httpStatus = $response?->status();
        $providerRequestId = $response?->header('x-request-id') ?: null;
        $errorType = $exception ? get_class($exception) : null;
        $errorMessage = $exception?->getMessage();

        $logContext = (array) ($options['log_context'] ?? []);

        Log::log($successful ? 'info' : 'warning', 'OpenAiService: API call', [
            'book_id' => $logContext['book_id'] ?? null,
            'scene_id' => $logContext['scene_id'] ?? null,
            'chunk_index' => $logContext['chunk_index'] ?? null,
            'job_attempt' => $logContext['job_attempt'] ?? null,
            'purpose' => $options['purpose'] ?? null,
            'model' => $model,
            'http_status' => $httpStatus,
            'duration_seconds' => $durationSeconds,
            'error_type' => $errorType,
            'error_message' => $errorMessage,
            'provider_request_id' => $providerRequestId,
            'tokens_used' => $hasUsage ? ($promptTokens + $completionTokens) : null,
        ]);

        try {
            ApiUsageService::log([
                'api_type' => 'OpenAI',
                'api_endpoint' => 'chat/completions',
                'purpose' => $options['purpose'] ?? 'video_pipeline_analysis',
                'description' => sprintf(
                    'model=%s reasoning_effort=%s prompt_tokens=%s completion_tokens=%s reasoning_tokens=%s',
                    $model,
                    $options['reasoning_effort'] ?? 'default',
                    $promptTokens ?? 'n/a',
                    $completionTokens ?? 'n/a',
                    $reasoningTokens ?? 'n/a'
                ),
                'status' => $successful ? 'success' : 'failed',
                'error_message' => $errorMessage,
                'characters_used' => mb_strlen($prompt),
                'tokens_used' => $hasUsage ? ($promptTokens + $completionTokens) : null,
                'duration_seconds' => $durationSeconds,
                'estimated_cost' => $estimatedCost,
                'request_data' => [
                    'model' => $model,
                    'reasoning_effort' => $options['reasoning_effort'] ?? null,
                    'book_id' => $logContext['book_id'] ?? null,
                    'scene_id' => $logContext['scene_id'] ?? null,
                    'chunk_index' => $logContext['chunk_index'] ?? null,
                    'job_attempt' => $logContext['job_attempt'] ?? null,
                ],
                'response_data' => [
                    'usage' => $usage,
                    'http_status' => $httpStatus,
                    'error_type' => $errorType,
                    'provider_request_id' => $providerRequestId,
                ],
            ]);
        } catch (\Throwable $e) {
            // Metrics logging must never break the actual pipeline.
        }
    }
}
