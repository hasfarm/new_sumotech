<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Exception;
use Illuminate\Support\Facades\Log;
use App\Services\ApiUsageService;

class TTSService
{
    // Google Cloud TTS voice mapping for Vietnamese
    private const GOOGLE_VOICES = [
        'female' => [
            'vi-VN-Standard-A' => 'Nữ A (Standard)',
            'vi-VN-Standard-B' => 'Nữ B (Standard)',
            'vi-VN-Studio-A' => 'Nữ A (Studio)',
            'vi-VN-Studio-B' => 'Nữ B (Studio)',
        ],
        'male' => [
            'vi-VN-Standard-C' => 'Nam C (Standard)',
            'vi-VN-Standard-D' => 'Nam D (Standard)',
            'vi-VN-Studio-C' => 'Nam C (Studio)',
            'vi-VN-Studio-D' => 'Nam D (Studio)',
        ]
    ];

    // OpenAI TTS voice mapping (via RapidAPI)
    private const OPENAI_VOICES = [
        'male' => [
            'alloy' => 'Nam Alloy (trung, rõ ràng)',
            'onyx' => 'Nam Onyx (trầm, authoritative)',
            'echo' => 'Nam Echo (ấm, thân thiện)',
        ],
        'female' => [
            'nova' => 'Nữ Nova (trẻ, tự nhiên)',
            'shimmer' => 'Nữ Shimmer (mềm, cảm xúc)',
            'fable' => 'Nữ Fable (kể chuyện chậm)',
        ],
    ];

    // Gemini Pro voice mapping (voiceName values from Gemini TTS list)
    private const GEMINI_VOICES = [
        'female' => [
            'Zephyr' => 'Zephyr – Tươi sáng',
            'Kore' => 'Kore – Firm',
            'Leda' => 'Leda – Trẻ trung',
            'Aoede' => 'Aoede – Breezy',
            'Callirrhoe' => 'Callirrhoe – Dễ chịu',
            'Autonoe' => 'Autonoe – Sáng',
            'Despina' => 'Despina – Smooth (Mượt mà)',
            'Erinome' => 'Erinome – Clear',
            'Laomedeia' => 'Laomedeia – Rộn ràng',
            'Achernar' => 'Achernar – Mềm',
            'Gacrux' => 'Gacrux – Dành cho người lớn',
            'Vindemiatrix' => 'Vindemiatrix – Êm dịu',
            'Sulafat' => 'Sulafat – Ấm',
        ],
        'male' => [
            'Puck' => 'Puck – Rộn ràng',
            'Charon' => 'Charon – Cung cấp nhiều thông tin',
            'Fenrir' => 'Fenrir – Dễ kích động',
            'Orus' => 'Orus – Firm',
            'Enceladus' => 'Enceladus – Breathy',
            'Iapetus' => 'Iapetus – Rõ ràng',
            'Umbriel' => 'Umbriel – Dễ tính',
            'Algieba' => 'Algieba – Lầm mịn',
            'Algenib' => 'Algenib – Gravelly',
            'Alnilam' => 'Alnilam – Firm',
            'Schedar' => 'Schedar – Even',
            'Zubenelgenubi' => 'Zubenelgenubi – Thông thường',
            'Sadachbia' => 'Sadachbia – Lively',
            'Sadaltager' => 'Sadaltager – Hiểu biết',
        ],
    ];

    // Microsoft Azure TTS voices (Vietnamese - via edge-tts)
    private const MICROSOFT_VOICES = [
        'female' => [
            'vi-VN-HoaiMyNeural' => 'Hoài My (Neural) – Tự nhiên',
            'vi-VN-NamMinhNeural' => 'Nam Minh Nữ (Neural)',
        ],
        'male' => [
            'vi-VN-NamMinhNeural' => 'Nam Minh (Neural) – Tự nhiên',
        ],
    ];

    // Vbee TTS voices (Vietnamese)
    private const VBEE_VOICES = [
        'female' => [
            'hn_female_ngochuyen_full_48k-fhg' => 'HN – Ngọc Huyền (48k)',
            'hn_female_maiphuong_vdts_48k-fhg' => 'HN – Mai Phương (48k)',
            'sg_female_lantrinh_vdts_48k-fhg' => 'SG – Lan Trinh (48k)',
            'hue_female_huonggiang_full_48k-fhg' => 'Huế – Hương Giang (48k)',
            'sg_female_thaotrinh_full_48k-fhg' => 'SG – Thảo Trinh (48k)',
            'sg_female_tuongvy_call_44k-fhg' => 'SG – Tường Vy (44k)',
            'sg_female_thaotrinh_full_44k-phg' => 'SG – Thảo Trinh (44k)',
            'hn_female_hermer_stor_48k-fhg' => 'HN – Ngọc Lan (48k, kể chuyện)',
            'hn_female_lenka_stor_48k-phg' => 'HN – Nguyệt Dương (48k, kể chuyện)',
            'hn_female_hachi_book_22k-vc' => 'HN – Hà Chi (22k, sách)',
            'n_hanoi_female_nguyetnga2_book_vc' => 'HN – Nguyệt Nga Podcast (sách)',
        ],
        'male' => [
        'n_hanoi_male_thedong_education_vc' => 'HN – Thế Đông (giáo dục)',
        'sg_male_trungkien_vdts_48k-fhg' => 'SG – Trung Kiên (48k)',
            'hue_male_duyphuong_full_48k-fhg' => 'Huế – Duy Phương (48k)',
            'sg_male_minhhoang_full_48k-fhg' => 'SG – Minh Hoàng (48k)',
            'hn_male_manhdung_news_48k-fhg' => 'HN – Mạnh Dũng (48k, tin tức)',
            'hn_male_thanhlong_talk_48k-fhg' => 'HN – Thanh Long (48k, nói chuyện)',
            'hn_male_phuthang_news65dt_44k-fhg' => 'HN – Anh Khôi (44k, tin tức)',
            'hn_male_manhdung_news_48k-phg' => 'HN – Mạnh Dũng (48k, phg)',
            'hn_male_phuthang_stor80dt_48k-fhg' => 'HN – Anh Khôi (48k, kể chuyện)',
            'sg_male_chidat_ebook_48k-phg' => 'SG – Chí Đạt (48k, ebook)',
            'hn_male_vietbach_child_22k-vc' => 'HN – Việt Bách (22k, trẻ em)',
            's_sg_male_thientam_ytstable_vc' => 'SG – Thiện Tâm (YT)',
            'n_hn_male_ngankechuyen_ytstable_vc' => 'HN – Ngạn Kể Chuyện (YT)',
            'n_hn_male_duyonyx_oaistable_vc' => 'HN – Duy Onyx (OAI)',
            'n_hanoi_male_baotrungmc_news_vc' => 'HN – Bảo Trung MC (tin tức)',
        ],
    ];

    // Conservative guard to avoid intermittent "Text too long" rejects from Vbee.
    private const VBEE_SAFE_TEXT_LIMIT = 1700;

    private function safeLog(string $level, string $message, array $context = []): void
    {
        try {
            Log::log($level, $message, $context);
        } catch (\Throwable $logException) {
            error_log('[TTSService] Log write failed: ' . $logException->getMessage() . ' | message: ' . $message);
        }
    }

    /**
     * Persist detailed Vbee call history for the API usage page.
     */
    private function logVbeeApiHistory(
        string $purpose,
        string $endpoint,
        string $status,
        ?string $errorMessage,
        array $requestData,
        array $responseData,
        ?int $charactersUsed = null,
        ?int $projectId = null
    ): void {
        try {
            ApiUsageService::log([
                'api_type' => 'Vbee',
                'api_endpoint' => $endpoint,
                'purpose' => $purpose,
                'status' => $status,
                'error_message' => $errorMessage,
                'request_data' => $requestData,
                'response_data' => $responseData,
                'characters_used' => $charactersUsed,
                'estimated_cost' => 0,
                'project_id' => $projectId,
                'description' => 'Vbee TTS call history',
            ]);
        } catch (\Throwable $e) {
            $this->safeLog('warning', 'Vbee history log failed', [
                'purpose' => $purpose,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get available voices for gender
     */
    public static function getAvailableVoices(string $gender = 'female', string $provider = 'google'): array
    {
        $gender = strtolower($gender);
        $provider = strtolower($provider);

        if ($provider === 'openai') {
            return $gender === 'male' ? self::OPENAI_VOICES['male'] : self::OPENAI_VOICES['female'];
        }

        if ($provider === 'gemini') {
            return $gender === 'male' ? self::GEMINI_VOICES['male'] : self::GEMINI_VOICES['female'];
        }

        if ($provider === 'microsoft') {
            return $gender === 'male' ? self::MICROSOFT_VOICES['male'] : self::MICROSOFT_VOICES['female'];
        }

        if ($provider === 'vbee') {
            return $gender === 'male' ? self::VBEE_VOICES['male'] : self::VBEE_VOICES['female'];
        }

        return $gender === 'male' ? self::GOOGLE_VOICES['male'] : self::GOOGLE_VOICES['female'];
    }

    /**
     * Get all available voices
     */
    public static function getAllVoices(string $provider = 'google'): array
    {
        $provider = strtolower($provider);

        if ($provider === 'openai') {
            return [
                'female' => self::OPENAI_VOICES['female'],
                'male' => self::OPENAI_VOICES['male'],
            ];
        }

        if ($provider === 'gemini') {
            return [
                'female' => self::GEMINI_VOICES['female'],
                'male' => self::GEMINI_VOICES['male'],
            ];
        }

        if ($provider === 'microsoft') {
            return [
                'female' => self::MICROSOFT_VOICES['female'],
                'male' => self::MICROSOFT_VOICES['male'],
            ];
        }

        if ($provider === 'vbee') {
            return [
                'female' => self::VBEE_VOICES['female'],
                'male' => self::VBEE_VOICES['male'],
            ];
        }

        return [
            'female' => self::GOOGLE_VOICES['female'],
            'male' => self::GOOGLE_VOICES['male'],
        ];
    }

    /**
     * Get audio duration in seconds using ffprobe
     */
    public function getAudioDuration(string $audioPath): float
    {
        try {
            $fullPath = Storage::path($audioPath);

            if (!file_exists($fullPath)) {
                throw new Exception("Audio file not found: {$audioPath}");
            }

            // Use ffprobe to get duration
            $command = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 \"{$fullPath}\" 2>&1";

            exec($command, $output, $returnCode);

            if ($returnCode === 0 && isset($output[0]) && is_numeric($output[0])) {
                return (float) $output[0];
            }

            $this->safeLog('warning', 'Failed to get audio duration via ffprobe', [
                'path' => $audioPath,
                'return_code' => $returnCode,
                'output' => $output
            ]);

            // Fallback: estimate based on file size (very rough)
            // Assume average bitrate of 128kbps for MP3/WAV
            $fileSize = filesize($fullPath);
            $estimatedDuration = $fileSize / (128 * 1024 / 8); // bytes / (bitrate in bytes/sec)

            return $estimatedDuration;
        } catch (Exception $e) {
            $this->safeLog('error', 'Get audio duration error', [
                'error' => $e->getMessage(),
                'path' => $audioPath
            ]);
            return 1.0; // Default fallback
        }
    }


    /**
     * Generate audio from text using TTS
     * 
     * @param string $text
     * @param int $index
     * @param string $voiceGender
     * @param string|null $voiceName
     * @param string $styleInstruction Optional style instruction for TTS
     * @return string Path to generated audio file
     */
    public function generateAudio(
        string $text,
        int $index,
        string $voiceGender = 'female',
        ?string $voiceName = null,
        string $provider = 'google',
        ?string $styleInstruction = null,
        ?int $projectId = null,
        float $speed = 1.0
    ): string {
        try {
            $provider = strtolower($provider);
            $speed = max(0.5, min(2.0, $speed)); // Clamp speed between 0.5 and 2.0

            // Prepend style instruction to text for Gemini TTS (and other providers if needed)
            $finalText = $text;
            if (!empty($styleInstruction) && !str_contains($text, $styleInstruction)) {
                $finalText = $styleInstruction . "\n\n" . $text;
            }

            $this->safeLog('info', 'TTS Generation', [
                'index' => $index,
                'provider' => $provider,
                'voice_gender' => $voiceGender,
                'voice_name' => $voiceName,
                'text_length' => strlen($finalText),
                'has_style_instruction' => !empty($styleInstruction),
                'speed' => $speed
            ]);

            if ($provider === 'openai') {
                // Use OpenAI API directly
                $apiKey = config('services.openai.api_key');

                if ($apiKey) {
                    return $this->generateWithOpenAIDirectTTS($finalText, $index, $voiceGender, $voiceName, $apiKey, $projectId, $speed);
                }

                throw new Exception('Missing OPENAI_API_KEY for OpenAI TTS. Please set OPENAI_API_KEY in .env');
            } elseif ($provider === 'gemini') {
                $geminiApiKey = config('services.gemini.api_key') ?: config('services.gemini.tts_api_key');

                if (!$geminiApiKey) {
                    throw new Exception('Missing GEMINI_API_KEY for Gemini TTS');
                }

                return $this->generateWithGeminiTTS($finalText, $index, $voiceGender, $voiceName, $geminiApiKey, $projectId);
            } elseif ($provider === 'microsoft') {
                // Use local edge-tts (no API key needed)
                return $this->generateWithEdgeTTS($finalText, $index, $voiceGender, $voiceName, $projectId, $speed);
            } elseif ($provider === 'vbee') {
                $vbeeAppId = config('services.vbee.app_id');
                $vbeeToken = config('services.vbee.token');

                if (!$vbeeAppId || !$vbeeToken) {
                    throw new Exception('Missing VBEE_TTS_APP_ID or VBEE_TTS_TOKEN. Please set both in .env');
                }

                return $this->generateWithVbeeTTS($text, $index, $voiceGender, $voiceName, $vbeeAppId, $vbeeToken, $projectId, $speed);
            } else {
                $apiKey = config('services.google_tts.api_key');
                if ($apiKey) {
                    return $this->generateWithGoogleTTS($finalText, $index, $voiceGender, $voiceName, $apiKey, $projectId, $speed);
                }
            }

            // No valid provider configured - throw error instead of mock
            throw new Exception("No valid TTS provider configured. Please set up API keys for: openai, gemini, or microsoft");
        } catch (Exception $e) {
            $this->safeLog('error', 'TTS Generation Error', ['error' => $e->getMessage()]);
            // Always throw error - never fallback to mock audio in production
            // This prevents silent failures that create corrupt audio files
            throw $e;
        }
    }

    /**
     * Generate audio using Google Cloud TTS
     */
    private function generateWithGoogleTTS(string $text, int $index, string $voiceGender, ?string $voiceName, string $apiKey, ?int $projectId = null, float $speed = 1.0): string
    {
        $url = "https://texttospeech.googleapis.com/v1/text:synthesize?key=" . $apiKey;

        // Use provided voice name or get default
        $voiceGender = strtolower($voiceGender);
        $selectedVoice = $voiceName;

        if (!$selectedVoice) {
            // Get first available voice for gender
            $voices = self::getAvailableVoices($voiceGender, 'google');
            $selectedVoice = array_key_first($voices) ?? 'vi-VN-Standard-A';
        }

        $ssmlGender = $voiceGender === 'male' ? 'MALE' : 'FEMALE';

        $data = [
            'input' => ['text' => $text],
            'voice' => [
                'languageCode' => 'vi-VN',
                'name' => $selectedVoice,
                'ssmlGender' => $ssmlGender
            ],
            'audioConfig' => [
                'audioEncoding' => 'MP3',
                'speakingRate' => $speed,
                'pitch' => 0.0
            ]
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode($response, true);

        if ($httpCode === 200 && isset($result['audioContent'])) {
            $audioData = base64_decode($result['audioContent']);
            $folder = $projectId ? "public/projects/{$projectId}" : "public/dubsync/tts";
            $filename = "{$folder}/s{$index}_" . time() . ".mp3";
            Storage::put($filename, $audioData);
            $this->safeLog('info', 'TTS Generated Successfully', ['file' => $filename]);

            // Log API usage
            ApiUsageService::logTTS(
                'Google Cloud TTS',
                'generate_audio',
                strlen($text),
                null,
                $projectId,
                ['voice' => $selectedVoice ?? 'default']
            );

            return $filename;
        }

        $this->safeLog('error', 'Google TTS Error', ['http_code' => $httpCode, 'response' => $response]);

        // Log failure
        ApiUsageService::logFailure(
            'Google Cloud TTS',
            'generate_audio',
            'HTTP ' . $httpCode . ': ' . substr($response, 0, 200),
            $projectId,
            ['text_length' => strlen($text)]
        );

        throw new Exception('Failed to generate TTS audio');
    }

    /**
     * Generate audio using OpenAI TTS (RapidAPI)
     */
    private function generateWithOpenAITTS(
        string $text,
        int $index,
        string $voiceGender,
        ?string $voiceName,
        string $apiKey,
        string $apiHost,
        ?int $projectId = null
    ): string {
        $selectedVoice = $this->resolveOpenAIVoice($voiceGender, $voiceName);
        $url = "https://{$apiHost}/";

        $payload = [
            'model' => 'tts-1-hd',
            'input' => $text,
            'voice' => $selectedVoice
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            "x-rapidapi-host: {$apiHost}",
            "x-rapidapi-key: {$apiKey}"
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            $this->safeLog('error', 'OpenAI TTS Error', [
                'http_code' => $httpCode,
                'content_type' => $contentType,
                'curl_error' => $curlError,
                'response_preview' => is_string($response) ? substr($response, 0, 500) : null
            ]);

            // Log failure
            ApiUsageService::logFailure(
                'OpenAI TTS',
                'generate_audio',
                'HTTP ' . $httpCode . ': ' . ($curlError ? $curlError : substr($response, 0, 200)),
                $projectId,
                ['text_length' => strlen($text)]
            );

            throw new Exception('Failed to generate OpenAI TTS audio');
        }

        $audioData = null;

        if ($contentType && stripos($contentType, 'application/json') !== false) {
            $decoded = json_decode($response, true);
            if (is_array($decoded)) {
                if (!empty($decoded['audioContent'])) {
                    $audioData = base64_decode($decoded['audioContent']);
                } elseif (!empty($decoded['audio'])) {
                    $audioData = base64_decode($decoded['audio']);
                } elseif (!empty($decoded['data'])) {
                    $audioData = base64_decode($decoded['data']);
                }
            }
        }

        if (!$audioData) {
            $audioData = $response;
        }

        $folder = $projectId ? "public/projects/{$projectId}" : "public/dubsync/tts";
        $filename = "{$folder}/s{$index}_" . time() . "_openai.mp3";
        Storage::put($filename, $audioData);
        $this->safeLog('info', 'OpenAI TTS Generated Successfully', ['file' => $filename, 'voice' => $selectedVoice]);

        // Log API usage
        ApiUsageService::logTTS(
            'OpenAI TTS',
            'generate_audio',
            strlen($text),
            null,
            $projectId,
            ['voice' => $selectedVoice]
        );

        return $filename;
    }

    /**
     * Generate audio using OpenAI TTS Direct API (not RapidAPI)
     * Uses official OpenAI API endpoint: https://api.openai.com/v1/audio/speech
     */
    private function generateWithOpenAIDirectTTS(
        string $text,
        int $index,
        string $voiceGender,
        ?string $voiceName,
        string $apiKey,
        ?int $projectId = null,
        float $speed = 1.0
    ): string {
        $selectedVoice = $this->resolveOpenAIVoice($voiceGender, $voiceName);
        $url = "https://api.openai.com/v1/audio/speech";

        $payload = [
            'model' => 'tts-1-hd',
            'input' => $text,
            'voice' => $selectedVoice,
            'response_format' => 'mp3',
            'speed' => $speed
        ];

        $this->safeLog('info', 'OpenAI Direct TTS Request', [
            'voice' => $selectedVoice,
            'text_length' => strlen($text),
            'model' => 'tts-1-hd'
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            "Authorization: Bearer {$apiKey}"
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            $errorMessage = $curlError ?: substr($response, 0, 500);

            // Try to parse JSON error response
            if ($response && stripos($contentType, 'application/json') !== false) {
                $decoded = json_decode($response, true);
                if (isset($decoded['error']['message'])) {
                    $errorMessage = $decoded['error']['message'];
                }
            }

            $this->safeLog('error', 'OpenAI Direct TTS Error', [
                'http_code' => $httpCode,
                'content_type' => $contentType,
                'curl_error' => $curlError,
                'error_message' => $errorMessage
            ]);

            // Log failure
            ApiUsageService::logFailure(
                'OpenAI Direct TTS',
                'generate_audio',
                'HTTP ' . $httpCode . ': ' . $errorMessage,
                $projectId,
                ['text_length' => strlen($text)]
            );

            throw new Exception('OpenAI TTS Error: ' . $errorMessage);
        }

        // OpenAI returns raw audio data directly (not base64 encoded)
        $audioData = $response;

        $folder = $projectId ? "public/projects/{$projectId}" : "public/dubsync/tts";
        $filename = "{$folder}/s{$index}_" . time() . "_openai_direct.mp3";
        Storage::put($filename, $audioData);

        $this->safeLog('info', 'OpenAI Direct TTS Generated Successfully', [
            'file' => $filename,
            'voice' => $selectedVoice,
            'size' => strlen($audioData)
        ]);

        // Log API usage
        ApiUsageService::logTTS(
            'OpenAI Direct TTS',
            'generate_audio',
            strlen($text),
            null,
            $projectId,
            ['voice' => $selectedVoice, 'model' => 'tts-1-hd']
        );

        return $filename;
    }

    /**
     * Generate audio using Microsoft Azure TTS
     */
    private function generateWithMicrosoftTTS(
        string $text,
        int $index,
        string $voiceGender,
        ?string $voiceName,
        string $azureKey,
        string $region = 'southeastasia',
        ?int $projectId = null
    ): string {
        // Use local edge-tts Python script instead of Azure API
        return $this->generateWithEdgeTTS($text, $index, $voiceGender, $voiceName, $projectId);
    }

    /**
     * Generate audio using Edge TTS (local Python script)
     */
    private function generateWithEdgeTTS(
        string $text,
        int $index,
        string $voiceGender,
        ?string $voiceName,
        ?int $projectId = null,
        float $speed = 1.0
    ): string {
        // Resolve voice name
        $selectedVoice = $voiceName;
        if (!$selectedVoice) {
            $voices = self::getAvailableVoices($voiceGender, 'microsoft');
            $selectedVoice = array_key_first($voices) ?? 'vi-VN-HoaiMyNeural';
        }

        // Prepare output directory
        $directory = 'public/dubsync/tts_audio';
        if (!Storage::exists($directory)) {
            Storage::makeDirectory($directory);
        }

        $filename = $directory . '/edge_' . $index . '_' . time() . '_' . uniqid() . '.mp3';
        $outputPath = Storage::path($filename);

        // Path to Python script
        $scriptPath = storage_path('scripts/edge_tts_generate.py');
        $pythonPath = base_path('.venv/bin/python');

        // Escape text for command line
        $escapedText = str_replace(['"', "\n", "\r"], ['\\"', ' ', ' '], $text);

        // Convert speed to edge-tts rate format (e.g., 0.8 → "-20%", 1.2 → "+20%")
        $ratePercent = round(($speed - 1.0) * 100);
        $rateStr = ($ratePercent >= 0 ? '+' : '') . $ratePercent . '%';

        // Build command
        $command = "\"{$pythonPath}\" \"{$scriptPath}\" --text \"{$escapedText}\" --out \"{$outputPath}\" --voice \"{$selectedVoice}\" --rate \"{$rateStr}\" 2>&1";

        $this->safeLog('info', 'Edge TTS Command', [
            'voice' => $selectedVoice,
            'text_length' => strlen($text),
            'output' => $outputPath
        ]);

        \exec($command, $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($outputPath)) {
            $this->safeLog('error', 'Edge TTS Error', [
                'return_code' => $returnCode,
                'output' => $output,
                'command' => $command
            ]);
            throw new Exception('Edge TTS failed: ' . implode("\n", $output));
        }

        // Track API usage (edge-tts is free, but log for statistics)
        try {
            ApiUsageService::log([
                'api_type' => 'Edge TTS',
                'api_endpoint' => 'local',
                'purpose' => 'TTS Generation',
                'tokens_used' => strlen($text),
                'estimated_cost' => 0,
                'project_id' => $projectId,
                'description' => "Voice: {$selectedVoice}",
            ]);
        } catch (\Exception $e) {
            // Ignore usage tracking errors
        }

        return $filename;
    }

    /**
     * Generate audio using Gemini Pro TTS (Google Cloud with regional endpoint)
     */
    private function generateWithGeminiTTS(
        string $text,
        int $index,
        string $voiceGender,
        ?string $voiceName,
        string $apiKey,
        ?int $projectId = null
    ): string {
        $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-preview-tts:generateContent?key={$apiKey}";

        $selectedVoice = $this->resolveGeminiVoice($voiceGender, $voiceName);

        $data = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $text]
                    ]
                ]
            ],
            'generationConfig' => [
                'responseModalities' => ['AUDIO'],
                'speechConfig' => [
                    'multiSpeakerVoiceConfig' => [
                        'speakerVoiceConfigs' => [
                            [
                                'speaker' => 'Speaker 1',
                                'voiceConfig' => [
                                    'prebuiltVoiceConfig' => [
                                        'voiceName' => $selectedVoice
                                    ]
                                ]
                            ],
                            [
                                'speaker' => 'Speaker 2',
                                'voiceConfig' => [
                                    'prebuiltVoiceConfig' => [
                                        'voiceName' => $selectedVoice
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode($response, true);

        if ($httpCode === 200 && isset($result['candidates'][0]['content']['parts'][0]['inlineData']['data'])) {
            $inlineData = $result['candidates'][0]['content']['parts'][0]['inlineData'];
            $audioData = base64_decode($inlineData['data']);
            $mimeType = $inlineData['mimeType'] ?? 'application/octet-stream';

            $ext = 'mp3';
            if (str_contains($mimeType, 'wav') || str_contains($mimeType, 'x-wav')) {
                $ext = 'wav';
            } elseif (str_contains($mimeType, 'mpeg') || str_contains($mimeType, 'mp3')) {
                $ext = 'mp3';
            } elseif (str_contains($mimeType, 'pcm') || str_contains($mimeType, 'l16') || str_contains($mimeType, 'raw')) {
                // Convert raw PCM to WAV for browser playback
                $sampleRate = 24000;
                $channels = 1;
                if (preg_match('/rate=(\d+)/', $mimeType, $match)) {
                    $sampleRate = (int) $match[1];
                }
                if (preg_match('/channels=(\d+)/', $mimeType, $match)) {
                    $channels = (int) $match[1];
                }
                $audioData = $this->wrapPcmToWav($audioData, $sampleRate, $channels, 16);
                $ext = 'wav';
            }

            $folder = $projectId ? "public/projects/{$projectId}" : "public/dubsync/tts";
            $filename = "{$folder}/s{$index}_" . time() . "_gemini.{$ext}";
            Storage::put($filename, $audioData);
            $this->safeLog('info', 'Gemini TTS Generated Successfully', [
                'file' => $filename,
                'voice' => $selectedVoice,
                'mimeType' => $mimeType
            ]);
            return $filename;
        }

        $this->safeLog('error', 'Gemini TTS Error', [
            'http_code' => $httpCode,
            'response' => $response,
            'voice' => $selectedVoice
        ]);
        $errorMessage = 'Failed to generate Gemini TTS audio';
        if (is_array($result) && isset($result['error']['message'])) {
            $errorMessage .= ': ' . $result['error']['message'];
        } elseif (is_string($response) && $response !== '') {
            $errorMessage .= ': ' . $response;
        }
        throw new Exception($errorMessage);
    }

    /**
     * Generate mock audio file for development
     */
    private function generateMockAudio(string $text, int $index, string $voiceGender, ?string $voiceName, string $provider, ?int $projectId = null): string
    {
        // Create a placeholder audio file
        $folder = $projectId ? "public/projects/{$projectId}" : "public/dubsync/tts";
        $filename = "{$folder}/s{$index}_" . time() . ".mp3";

        // Create directory if it doesn't exist
        $directory = dirname(storage_path('app/' . $filename));
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        // Create a small placeholder file with voice info
        $voiceInfo = $voiceName ? " [{$voiceName}]" : " [{$voiceGender}]";
        $voiceInfo .= " [{$provider}]";
        $placeholderContent = "Mock audio for: " . substr($text, 0, 50) . $voiceInfo;
        Storage::put($filename, $placeholderContent);

        $this->safeLog('info', 'Mock TTS Generated', [
            'file' => $filename,
            'voice' => $voiceName ?? $voiceGender,
            'provider' => $provider
        ]);

        return $filename;
    }

    private function resolveOpenAIVoice(string $voiceGender, ?string $voiceName): string
    {
        $voiceGender = strtolower($voiceGender);
        $voices = $voiceGender === 'male' ? self::OPENAI_VOICES['male'] : self::OPENAI_VOICES['female'];

        if ($voiceName && array_key_exists($voiceName, $voices)) {
            return $voiceName;
        }

        return array_key_first($voices) ?? 'alloy';
    }

    private function resolveGeminiVoice(string $voiceGender, ?string $voiceName): string
    {
        $voiceGender = strtolower($voiceGender);
        $voices = $voiceGender === 'male' ? self::GEMINI_VOICES['male'] : self::GEMINI_VOICES['female'];

        if ($voiceName && array_key_exists($voiceName, $voices)) {
            return $voiceName;
        }

        return array_key_first($voices) ?? 'Kore';
    }

    /**
     * Resolve Vbee voice code
     */
    private function resolveVbeeVoice(string $voiceGender, ?string $voiceName): string
    {
        $voiceGender = strtolower($voiceGender);
        $voices = $voiceGender === 'male' ? self::VBEE_VOICES['male'] : self::VBEE_VOICES['female'];

        if ($voiceName && array_key_exists($voiceName, $voices)) {
            return $voiceName;
        }

        return array_key_first($voices) ?? 'hn_female_ngochuyen_full_48k-fhg';
    }

    /**
     * Vbee voices are optimized for Vietnamese. CJK-heavy text frequently stalls in IN_PROGRESS.
     */
    private function isLikelyUnsupportedForVbee(string $text): bool
    {
        return preg_match('/[\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}]/u', $text) === 1;
    }

    /**
     * Replace sensitive/violent Vietnamese words with softer alternatives
     * to avoid Vbee "hate speech" rejection on literary content.
     */
    private function sanitizeVbeeText(string $text): string
    {
        $replacements = [
            // Violence - killing
            'giết chết' => 'hạ gục',
            'giết người' => 'hại người',
            'giết' => 'hại',
            'sát nhân' => 'hung thủ',
            'sát hại' => 'làm hại',
            'trảm quyết' => 'xử tội',
            'tra khảo' => 'thẩm vấn',
            'tra tấn' => 'hành hạ',
            'chém chết' => 'hạ gục',
            'chém đầu' => 'xử tội',
            'đâm chết' => 'hạ gục',
            'bắn chết' => 'hạ gục',
            'thảm sát' => 'tàn sát',
            // Death
            'chết chóc' => 'thương vong',
            'chết' => 'bỏ mạng',
            'xác chết' => 'thi thể',
            'tử hình' => 'xử phạt nặng nhất',
            'đền mạng' => 'đền tội',
            // Crime
            'tên chó má' => 'tên đáng khinh',
            'chó má' => 'đáng khinh',
            'ác độc' => 'tàn nhẫn',
            'ác bá' => 'cường hào',
            'đút lót' => 'hối lộ',
            // Body/blood
            'máu me' => 'thương tích',
            'đổ máu' => 'bị thương',
        ];

        // Use case-insensitive replacement, longer phrases first (already ordered)
        foreach ($replacements as $find => $replace) {
            $text = mb_eregi_replace(preg_quote($find, '/'), $replace, $text);
        }

        return $text;
    }

    /**
     * Generate audio using Vbee TTS API (async → poll → download)
     * Retries up to 2 times on transient FAILURE.
     * On "hate speech" rejection, uses AI to rewrite text and retries.
     */
    private function generateWithVbeeTTS(
        string $text,
        int $index,
        string $voiceGender,
        ?string $voiceName,
        string $appId,
        string $token,
        ?int $projectId = null,
        float $speed = 1.0
    ): string {
        if ($this->isLikelyUnsupportedForVbee($text)) {
            throw new Exception('Vbee TTS chi ho tro noi dung tieng Viet. Hay dich segment sang tieng Viet hoac chon provider khac (Microsoft/OpenAI/Gemini).');
        }

        $maxRetries = 1;
        $lastException = null;
        // Always sanitize text before sending to Vbee to avoid "hate speech" rejection
        $currentText = $this->sanitizeVbeeText($text);

        for ($retry = 0; $retry <= $maxRetries; $retry++) {
            try {
                if ($retry > 0) {
                    $this->safeLog('info', 'Vbee TTS: Retry attempt', ['retry' => $retry, 'index' => $index]);
                    sleep(5 * $retry); // backoff: 5s, 10s
                }
                return $this->doVbeeTTSRequest($currentText, $index, $voiceGender, $voiceName, $appId, $token, $projectId, $speed);
            } catch (Exception $e) {
                $lastException = $e;

                // On hate speech rejection → AI rewrite then retry
                if (str_contains($e->getMessage(), 'hate speech')) {
                    $this->safeLog('info', 'Vbee TTS: Hate speech detected, attempting AI rewrite', [
                        'index' => $index,
                        'text_length' => mb_strlen($currentText),
                    ]);
                    $rewritten = $this->rewriteForTTS($currentText);
                    if ($rewritten && $rewritten !== $currentText) {
                        $this->safeLog('info', 'Vbee TTS: Text rewritten by AI', [
                            'index' => $index,
                            'original_length' => mb_strlen($currentText),
                            'rewritten_length' => mb_strlen($rewritten),
                        ]);
                        $currentText = $rewritten;
                        continue; // retry with rewritten text
                    }
                    $this->safeLog('warning', 'Vbee TTS: AI rewrite returned no change, giving up');
                    throw $e;
                }

                // Timeout usually means Vbee job is stuck in IN_PROGRESS.
                // Do not keep retrying and making users wait many extra minutes.
                if (str_contains($e->getMessage(), 'Timeout waiting for audio')) {
                    throw $e;
                }

                // Other submit errors — don't retry
                if (str_contains($e->getMessage(), 'submit failed')) {
                    throw $e;
                }

                $this->safeLog('warning', 'Vbee TTS: Attempt failed', [
                    'retry' => $retry,
                    'index' => $index,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        throw $lastException;
    }

    /**
     * Use AI (Gemini) to rewrite text that was flagged as sensitive by Vbee.
     * Keeps the same meaning but replaces violent/sensitive words with softer alternatives.
     */
    private function rewriteForTTS(string $text): ?string
    {
        $apiKey = config('services.gemini.api_key');
        if (!$apiKey) {
            $this->safeLog('warning', 'Vbee TTS rewrite: No GEMINI_API_KEY configured');
            return null;
        }

        try {
            $client = new \GuzzleHttp\Client();

            $prompt = <<<PROMPT
Bạn là chuyên gia viết lại văn bản tiếng Việt cho hệ thống text-to-speech.

Văn bản dưới đây bị hệ thống TTS từ chối vì chứa nội dung nhạy cảm (bạo lực, thù hận, v.v.).

YÊU CẦU:
- Viết lại văn bản để giữ nguyên ý nghĩa câu chuyện nhưng dùng ngôn từ nhẹ nhàng hơn
- Thay thế các từ bạo lực, nhạy cảm bằng cách diễn đạt gián tiếp hoặc uyển chuyển hơn
- Ví dụ: "giết" → "hại", "chém" → "tấn công", "máu" → "vết thương", "chết" → "qua đời/ra đi", "đâm" → "làm tổn thương"
- KHÔNG thay đổi cấu trúc câu chuyện, tên nhân vật, hoặc bối cảnh
- KHÔNG thêm hoặc bớt thông tin
- Giữ nguyên độ dài tương đương
- CHỈ trả về văn bản đã viết lại, không thêm giải thích

VĂN BẢN GỐC:
{$text}
PROMPT;

            $response = $client->post(
                'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . $apiKey,
                [
                    'json' => [
                        'contents' => [
                            ['parts' => [['text' => $prompt]]]
                        ],
                        'generationConfig' => [
                            'temperature' => 0.3,
                            'maxOutputTokens' => 8192,
                        ],
                    ],
                    'timeout' => 60,
                ]
            );

            $body = json_decode($response->getBody()->getContents(), true);
            $rewritten = $body['candidates'][0]['content']['parts'][0]['text'] ?? null;

            if ($rewritten) {
                $rewritten = trim($rewritten);
                $this->safeLog('info', 'Vbee TTS rewrite: Success', [
                    'original_preview' => mb_substr($text, 0, 100),
                    'rewritten_preview' => mb_substr($rewritten, 0, 100),
                ]);
            }

            return $rewritten;
        } catch (\Throwable $e) {
            $this->safeLog('error', 'Vbee TTS rewrite: AI rewrite failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Single attempt to generate audio via Vbee TTS API
     */
    private function doVbeeTTSRequest(
        string $text,
        int $index,
        string $voiceGender,
        ?string $voiceName,
        string $appId,
        string $token,
        ?int $projectId = null,
        float $speed = 1.0
    ): string {
        $voiceCode = $this->resolveVbeeVoice($voiceGender, $voiceName);
        $textLength = mb_strlen($text, 'UTF-8');
        $textBytes = strlen($text);
        $submitEndpoint = 'https://vbee.vn/api/v1/tts';

        if ($textLength > self::VBEE_SAFE_TEXT_LIMIT) {
            $this->logVbeeApiHistory(
                'vbee_tts_guard',
                $submitEndpoint,
                'failed',
                'Text too long (local guard)',
                [
                    'index' => $index,
                    'voice_code' => $voiceCode,
                    'text_chars' => $textLength,
                    'text_bytes' => $textBytes,
                    'safe_limit' => self::VBEE_SAFE_TEXT_LIMIT,
                ],
                [],
                $textLength,
                $projectId
            );
            throw new Exception(
                'Vbee TTS submit failed: Text too long (local guard ' .
                "{$textLength}/" . self::VBEE_SAFE_TEXT_LIMIT . ')'
            );
        }

        $this->safeLog('info', 'Vbee TTS: Starting', [
            'voice_code' => $voiceCode,
            'text_length' => $textLength,
            'index' => $index,
        ]);

        // Step 1: Submit TTS request
        $client = new \GuzzleHttp\Client();
        $callbackUrl = rtrim(config('app.url', 'http://localhost'), '/') . '/api/vbee-callback';
        $submitPayload = [
            'app_id' => $appId,
            'response_type' => 'indirect',
            'callback_url' => $callbackUrl,
            'input_text' => $text,
            'voice_code' => $voiceCode,
            'audio_type' => 'mp3',
            'bitrate' => 128,
            'speed_rate' => (string) $speed,
        ];
        $submitRequestData = [
            'index' => $index,
            'voice_code' => $voiceCode,
            'text_chars' => $textLength,
            'text_bytes' => $textBytes,
            'speed_rate' => (string) $speed,
            'callback_url' => $callbackUrl,
            'text_preview' => mb_substr($text, 0, 200, 'UTF-8'),
        ];

        try {
            $response = $client->post($submitEndpoint, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                ],
                'json' => $submitPayload,
                'timeout' => 30,
            ]);
        } catch (\Throwable $submitEx) {
            $this->logVbeeApiHistory(
                'vbee_tts_submit',
                $submitEndpoint,
                'failed',
                $submitEx->getMessage(),
                $submitRequestData,
                [],
                $textLength,
                $projectId
            );
            throw $submitEx;
        }

        $body = json_decode($response->getBody()->getContents(), true);

        if (($body['status'] ?? 0) !== 1) {
            $errMsg = $body['error_message'] ?? 'Vbee API returned error';
            $this->safeLog('warning', 'Vbee TTS: Submit rejected', [
                'index' => $index,
                'voice_code' => $voiceCode,
                'char_length' => $textLength,
                'byte_length' => strlen($text),
                'error_message' => $errMsg,
                'response_status' => $body['status'] ?? null,
                'response' => $body,
            ]);
            $this->logVbeeApiHistory(
                'vbee_tts_submit',
                $submitEndpoint,
                'failed',
                $errMsg,
                $submitRequestData,
                $body,
                $textLength,
                $projectId
            );
            throw new Exception('Vbee TTS submit failed: ' . $errMsg);
        }

        $requestId = $body['result']['request_id'] ?? null;
        if (!$requestId) {
            $this->logVbeeApiHistory(
                'vbee_tts_submit',
                $submitEndpoint,
                'failed',
                'No request_id returned',
                $submitRequestData,
                $body,
                $textLength,
                $projectId
            );
            throw new Exception('Vbee TTS: No request_id returned');
        }

        $this->logVbeeApiHistory(
            'vbee_tts_submit',
            $submitEndpoint,
            'success',
            null,
            array_merge($submitRequestData, ['request_id' => $requestId]),
            $body,
            $textLength,
            $projectId
        );

        $this->safeLog('info', 'Vbee TTS: Request submitted', ['request_id' => $requestId]);

        // Step 2: Poll for completion (max ~3 minutes)
        $audioLink = null;
        $maxAttempts = 40; // 40 × 3s = 120s
        $pollInterval = 3;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            sleep($pollInterval);

            $pollEndpoint = "https://vbee.vn/api/v1/tts/{$requestId}";
            $pollRequestData = [
                'index' => $index,
                'request_id' => $requestId,
                'attempt' => $attempt + 1,
            ];

            try {
                $pollResp = $client->get($pollEndpoint, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                    ],
                    'timeout' => 15,
                ]);
            } catch (\Throwable $pollEx) {
                $this->safeLog('warning', 'Vbee TTS: Poll request error', [
                    'attempt' => $attempt + 1,
                    'request_id' => $requestId,
                    'error' => $pollEx->getMessage(),
                ]);
                $this->logVbeeApiHistory(
                    'vbee_tts_poll',
                    $pollEndpoint,
                    'failed',
                    $pollEx->getMessage(),
                    $pollRequestData,
                    [],
                    $textLength,
                    $projectId
                );
                // Network error during poll — continue retrying
                continue;
            }

            $pollBody = json_decode($pollResp->getBody()->getContents(), true);
            $status = $pollBody['result']['status'] ?? '';

            $pollLogStatus = 'success';
            $pollError = null;
            if ($status === 'FAILURE') {
                $pollLogStatus = 'failed';
                $pollError = $pollBody['result']['error_message']
                    ?? $pollBody['error_message']
                    ?? 'Vbee returned FAILURE status';
            }

            $this->logVbeeApiHistory(
                'vbee_tts_poll',
                $pollEndpoint,
                $pollLogStatus,
                $pollError,
                $pollRequestData,
                [
                    'status' => $status,
                    'result' => $pollBody['result'] ?? null,
                ],
                $textLength,
                $projectId
            );

            if ($status === 'SUCCESS') {
                $audioLink = $pollBody['result']['audio_link'] ?? null;
                break;
            } elseif ($status === 'FAILURE') {
                $errorMsg = $pollBody['result']['error_message']
                    ?? $pollBody['error_message']
                    ?? json_encode($pollBody['result'] ?? $pollBody);
                $this->safeLog('error', 'Vbee TTS: FAILURE response', [
                    'request_id' => $requestId,
                    'error_message' => $errorMsg,
                    'full_response' => $pollBody,
                ]);
                throw new Exception('Vbee TTS failed for request ' . $requestId . ': ' . $errorMsg);
            }

            // IN_PROGRESS — keep polling
            if ($attempt % 5 === 0) {
                $this->safeLog('debug', 'Vbee TTS: Polling', ['attempt' => $attempt + 1, 'status' => $status, 'request_id' => $requestId]);
            }
        }

        if (!$audioLink) {
            $this->logVbeeApiHistory(
                'vbee_tts_poll',
                "https://vbee.vn/api/v1/tts/{$requestId}",
                'failed',
                'Timeout waiting for audio',
                [
                    'index' => $index,
                    'request_id' => $requestId,
                    'attempts' => $maxAttempts,
                    'poll_interval' => $pollInterval,
                ],
                [],
                $textLength,
                $projectId
            );
            throw new Exception('Vbee TTS: Timeout waiting for audio after ' . ($maxAttempts * $pollInterval) . 's (request ' . $requestId . ')');
        }

        $this->safeLog('info', 'Vbee TTS: Audio ready', ['audio_link' => $audioLink]);

        // Step 3: Download the audio file (link expires after 3 minutes)
        try {
            $audioData = $client->get($audioLink, ['timeout' => 30])->getBody()->getContents();
        } catch (\Throwable $downloadEx) {
            $this->logVbeeApiHistory(
                'vbee_tts_download',
                $audioLink,
                'failed',
                $downloadEx->getMessage(),
                [
                    'index' => $index,
                    'request_id' => $requestId,
                ],
                [],
                $textLength,
                $projectId
            );
            throw $downloadEx;
        }

        // Save using Storage facade (relative path) — consistent with other providers
        $folder = $projectId ? "public/projects/{$projectId}" : "public/dubsync/tts";
        $filename = "{$folder}/s{$index}_" . time() . "_vbee.mp3";
        Storage::put($filename, $audioData);

        $this->safeLog('info', 'Vbee TTS: Saved audio', ['file' => $filename, 'size' => strlen($audioData)]);

        $this->logVbeeApiHistory(
            'vbee_tts_download',
            $audioLink,
            'success',
            null,
            [
                'index' => $index,
                'request_id' => $requestId,
            ],
            [
                'file' => $filename,
                'bytes' => strlen($audioData),
            ],
            $textLength,
            $projectId
        );

        return $filename;
    }

    private function wrapPcmToWav(string $pcmData, int $sampleRate, int $channels, int $bitsPerSample): string
    {
        $dataSize = strlen($pcmData);
        $byteRate = $sampleRate * $channels * ($bitsPerSample / 8);
        $blockAlign = $channels * ($bitsPerSample / 8);

        $header = "RIFF";
        $header .= pack('V', 36 + $dataSize);
        $header .= "WAVE";
        $header .= "fmt ";
        $header .= pack('V', 16);
        $header .= pack('v', 1); // PCM format
        $header .= pack('v', $channels);
        $header .= pack('V', $sampleRate);
        $header .= pack('V', (int) $byteRate);
        $header .= pack('v', (int) $blockAlign);
        $header .= pack('v', $bitsPerSample);
        $header .= "data";
        $header .= pack('V', $dataSize);

        return $header . $pcmData;
    }
}
