<?php

namespace App\Services;

use App\Models\ApiUsage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ApiUsageService
{
    private static ?bool $apiUsagesTableExists = null;

    /**
     * Log API usage
     */
    public static function log(array $data): ApiUsage
    {
        $payload = array_merge([
            'user_id' => Auth::id(),
            'ip_address' => Request::ip(),
            'status' => 'success',
        ], $data);

        if (!self::canWriteApiUsage()) {
            return new ApiUsage($payload);
        }

        try {
            return ApiUsage::create($payload);
        } catch (QueryException $e) {
            // Avoid breaking core flows (e.g. TTS) when tracking table is absent/misaligned.
            if (self::isMissingApiUsageTableError($e)) {
                self::$apiUsagesTableExists = false;
                Log::warning('ApiUsageService: api_usages table is missing, skip usage logging.', [
                    'error' => $e->getMessage(),
                ]);

                return new ApiUsage($payload);
            }

            throw $e;
        }
    }

    /**
     * Log OpenAI API call
     */
    public static function logOpenAI(
        string $purpose,
        ?int $tokens = null,
        ?float $cost = null,
        string $model = 'gpt-3.5-turbo',
        ?int $projectId = null,
        array $additionalData = []
    ): ApiUsage {
        // Calculate cost if tokens provided
        if ($tokens && !$cost) {
            $cost = self::calculateOpenAICost($tokens, $model);
        }

        return self::log(array_merge([
            'api_type' => 'OpenAI',
            'api_endpoint' => 'https://api.openai.com/v1/chat/completions',
            'purpose' => $purpose,
            'tokens_used' => $tokens,
            'estimated_cost' => $cost ?? 0,
            'project_id' => $projectId,
            'description' => "Model: {$model}",
        ], $additionalData));
    }

    /**
     * Log TTS API call (Google, ElevenLabs, Azure, etc)
     */
    public static function logTTS(
        string $service,
        string $purpose,
        ?int $characters = null,
        ?float $cost = null,
        ?int $projectId = null,
        array $additionalData = []
    ): ApiUsage {
        // Calculate cost if characters provided
        if ($characters && !$cost) {
            $cost = self::calculateTTSCost($service, $characters);
        }

        return self::log(array_merge([
            'api_type' => $service,
            'purpose' => $purpose,
            'characters_used' => $characters,
            'estimated_cost' => $cost ?? 0,
            'project_id' => $projectId,
        ], $additionalData));
    }

    /**
     * Log YouTube API call
     */
    public static function logYouTube(
        string $purpose,
        ?int $projectId = null,
        array $additionalData = []
    ): ApiUsage {
        return self::log(array_merge([
            'api_type' => 'YouTube',
            'purpose' => $purpose,
            'estimated_cost' => 0, // Free tier
            'project_id' => $projectId,
        ], $additionalData));
    }

    /**
     * Log Google Translate API call
     */
    public static function logGoogleTranslate(
        ?int $characters = null,
        ?float $cost = null,
        ?int $projectId = null,
        array $additionalData = []
    ): ApiUsage {
        if ($characters && !$cost) {
            $cost = self::calculateGoogleTranslateCost($characters);
        }

        return self::log(array_merge([
            'api_type' => 'Google Translate',
            'purpose' => 'translate_transcript',
            'characters_used' => $characters,
            'estimated_cost' => $cost ?? 0,
            'project_id' => $projectId,
        ], $additionalData));
    }

    /**
     * Log API failure
     */
    public static function logFailure(
        string $apiType,
        string $purpose,
        string $error,
        ?int $projectId = null,
        array $additionalData = []
    ): ApiUsage {
        return self::log(array_merge([
            'api_type' => $apiType,
            'purpose' => $purpose,
            'status' => 'failed',
            'error_message' => $error,
            'estimated_cost' => 0,
            'project_id' => $projectId,
        ], $additionalData));
    }

    /**
     * Calculate OpenAI cost based on tokens and model
     * Reference: https://openai.com/pricing
     */
    public static function calculateOpenAICost(int $tokens, string $model = 'gpt-3.5-turbo'): float
    {
        // Cost per 1K tokens
        $rates = [
            'gpt-4' => 0.03,
            'gpt-4-turbo' => 0.01,
            'gpt-3.5-turbo' => 0.0005,
        ];

        $rate = $rates[$model] ?? 0.0005;
        return ($tokens / 1000) * $rate;
    }

    /**
     * Calculate TTS cost based on service and characters
     */
    public static function calculateTTSCost(string $service, int $characters): float
    {
        // Cost per character or per 1K characters
        $rates = [
            'Google Cloud TTS' => 0.000016, // $16 per 1M characters
            'ElevenLabs' => 0.00030, // $0.30 per 1K characters
            'Azure TTS' => 0.000016, // $16 per 1M characters
            'Gemini TTS' => 0.000016, // $16 per 1M characters
            'OpenAI TTS' => 0.000015, // $15 per 1M characters
        ];

        $rate = $rates[$service] ?? 0.000016;
        return $characters * $rate;
    }

    /**
     * Calculate Google Translate cost
     * Reference: https://cloud.google.com/translate/pricing
     */
    public static function calculateGoogleTranslateCost(int $characters): float
    {
        // $15 per 1M characters
        return ($characters / 1000000) * 15;
    }

    /**
     * Log FFmpeg processing (audio/video)
     */
    public static function logFFmpeg(
        string $purpose,
        ?float $durationSeconds = null,
        ?int $projectId = null,
        array $additionalData = []
    ): ApiUsage {
        return self::log(array_merge([
            'api_type' => 'FFmpeg',
            'purpose' => $purpose,
            'duration_seconds' => $durationSeconds,
            'estimated_cost' => 0, // Local processing, no cost
            'project_id' => $projectId,
        ], $additionalData));
    }

    /**
     * Batch log multiple API calls
     */
    public static function batchLog(array $calls): array
    {
        return array_map(fn($call) => self::log($call), $calls);
    }

    private static function canWriteApiUsage(): bool
    {
        if (self::$apiUsagesTableExists !== null) {
            return self::$apiUsagesTableExists;
        }

        try {
            self::$apiUsagesTableExists = Schema::hasTable('api_usages');
        } catch (Throwable $e) {
            Log::warning('ApiUsageService: Unable to verify api_usages table, skip logging.', [
                'error' => $e->getMessage(),
            ]);
            self::$apiUsagesTableExists = false;
        }

        return self::$apiUsagesTableExists;
    }

    private static function isMissingApiUsageTableError(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'api_usages')
            && (
                str_contains($message, 'base table or view not found')
                || str_contains($message, 'doesn\'t exist')
                || str_contains($message, 'no such table')
            );
    }
}
