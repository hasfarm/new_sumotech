<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Services\ApiUsageService;

class TranslationService
{
    private $provider;

    public function __construct($provider = null)
    {
        $this->provider = $provider ?? env('TRANSLATION_PROVIDER', 'google');
    }

    /**
     * Translate segments to Vietnamese
     * 
     * @param array $segments
     * @param string|null $provider
     * @param int|null $projectId
     * @return array
     */
    public function translateSegments(array $segments, $provider = null, ?int $projectId = null, string $sourceLang = 'auto', string $style = 'default'): array
    {
        if ($provider) {
            $this->provider = $provider;
        }

        $translatedSegments = [];

        foreach ($segments as $index => $segment) {
            $sourceText = (string) ($segment['text'] ?? '');

            // Avoid translating Vietnamese text again to prevent mixed/garbled outputs.
            if ($this->isLikelyVietnamese($sourceText)) {
                $translatedText = $sourceText;
            } else {
                $translatedText = $this->translate($sourceText, $sourceLang ?: 'auto', 'vi', null, $style);
            }

            // Handle both 'start' and 'start_time' keys (YouTube uses 'start')
            $startTime = $segment['start'] ?? $segment['start_time'] ?? 0;
            $endTime = $segment['end_time'] ?? ($startTime + ($segment['duration'] ?? 0));
            $duration = $segment['duration'] ?? 0;

            $translatedSegments[] = [
                'index' => $index,
                'original_text' => $this->sanitizeUtf8($sourceText),
                'text' => $this->sanitizeUtf8($translatedText),
                'start' => $startTime,
                'start_time' => $startTime,  // Keep for backward compatibility
                'end_time' => $endTime,
                'duration' => $duration
            ];
        }

        return $translatedSegments;
    }

    /**
     * Translate a single text string
     *
     * @param string $text
     * @param string $from
     * @param string $to
     * @param string|null $provider
     * @return string
     */
    public function translateText(string $text, string $from = 'en', string $to = 'vi', $provider = null): string
    {
        if ($provider) {
            $this->provider = $provider;
        }

        return $this->translate($text, $from, $to);
    }

    /**
     * Translate text using selected provider (OpenAI or Google)
     * 
     * @param string $text
     * @param string $from
     * @param string $to
     * @return string
     */
    private function translate(string $text, string $from, string $to, ?string $provider = null, string $style = 'default'): string
    {
        // Trim text
        $text = trim($text);
        if (empty($text)) return '';

        if ($this->provider === 'openai') {
            return $this->translateWithOpenAI($text, $from, $to, $style);
        } else {
            return $this->translateWithGoogle($text, $from, $to);
        }
    }

    /**
     * Translate text using OpenAI
     * 
     * @param string $text
     * @param string $from
     * @param string $to
     * @return string
     */
    private function translateWithOpenAI(string $text, string $from, string $to, string $style = 'default'): string
    {
        try {
            $apiKey = env('OPENAI_API_KEY');

            if (!$apiKey) {
                Log::error('OpenAI API Key not found in .env');
                return $text;
            }

            $languageMap = [
                'vi' => 'Vietnamese',
                'en' => 'English',
                'zh' => 'Chinese',
                'zh-CN' => 'Chinese (Simplified)'
            ];

            $fromLanguage = $from === 'auto' ? 'source language' : ($languageMap[$from] ?? ucfirst($from));
            $toLanguage = $languageMap[$to] ?? ucfirst($to);

            $systemPrompt = 'You are a professional translator. Translate naturally and keep meaning accurate. If text is already in target language, return it with minimal cleanup only.';
            if ($style === 'humorous') {
                $systemPrompt = 'You are a humorous Vietnamese translator (gen Z style). Translate naturally with a funny, witty tone. AVOID sensitive words about violence, sex, killing, blood. Replace them with funny slang: kill→"cho về vườn", die→"ngủm", blood→"nước sốt cà chua", fight→"giao lưu võ thuật", weapon→"đồ chơi", gun→"gậy phép", prison→"khu nghỉ dưỡng miễn phí". Keep it YouTube-friendly and entertaining.';
            }

            $response = Http::timeout(30)
                ->withToken($apiKey)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-3.5-turbo',
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $systemPrompt
                        ],
                        [
                            'role' => 'user',
                            'content' => "Translate the following text from {$fromLanguage} to {$toLanguage}. Only provide the translated text, nothing else:\n\n{$text}"
                        ]
                    ],
                    'temperature' => $style === 'humorous' ? 0.7 : 0.3,
                    'max_tokens' => 1000
                ]);

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['choices'][0]['message']['content'])) {
                    $translated = trim($data['choices'][0]['message']['content']);
                    Log::info('OpenAI translation success', [
                        'original' => substr($text, 0, 100),
                        'translated' => substr($translated, 0, 100)
                    ]);

                    // Log API usage
                    $tokensUsed = $data['usage']['total_tokens'] ?? strlen($text) / 4;
                    ApiUsageService::logOpenAI(
                        'translate_transcript',
                        (int) $tokensUsed,
                        null,
                        'gpt-3.5-turbo',
                        null,
                        ['text_length' => strlen($text)]
                    );

                    return $translated;
                }
            } else {
                Log::error('OpenAI API error', [
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);

                // Log failure
                ApiUsageService::logFailure(
                    'OpenAI',
                    'translate_transcript',
                    'HTTP ' . $response->status() . ': ' . substr($response->body(), 0, 200),
                    null,
                    ['text_length' => strlen($text)]
                );
            }

            return $text;
        } catch (Exception $e) {
            Log::error('OpenAI translation exception', [
                'error' => $e->getMessage(),
                'text' => substr($text, 0, 100)
            ]);

            // Log failure
            ApiUsageService::logFailure(
                'OpenAI',
                'translate_transcript',
                $e->getMessage(),
                null,
                ['text_length' => strlen($text)]
            );
            return $text;
        }
    }

    /**
     * Translate text using Google Translate API
     * 
     * @param string $text
     * @param string $from
     * @param string $to
     * @return string
     */
    private function translateWithGoogle(string $text, string $from, string $to): string
    {
        try {
            $apiKey = env('GOOGLE_TRANSLATE_API_KEY');

            if (!$apiKey) {
                Log::error('Google Translate API Key not found in .env');
                return $text;
            }

            // Use Google Cloud Translation API v2 with proper format
            // Important: Key must be in URL, data must be form-encoded (not JSON)
            $url = "https://translation.googleapis.com/language/translate/v2?key=" . urlencode($apiKey);

            $payload = [
                'q' => $text,
                'target' => $to
            ];

            // Let Google auto-detect source language unless explicitly specified.
            if (!empty($from) && strtolower($from) !== 'auto') {
                $payload['source'] = $from;
            }

            $response = Http::timeout(15)->asForm()->post($url, $payload);

            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['data']['translations'][0]['translatedText'])) {
                    $translated = html_entity_decode($data['data']['translations'][0]['translatedText']);
                    Log::info('Google Translate success', [
                        'original' => substr($text, 0, 100),
                        'translated' => substr($translated, 0, 100)
                    ]);

                    // Log API usage
                    ApiUsageService::logGoogleTranslate(
                        strlen($text),
                        null,
                        null,
                        ['source' => $from, 'target' => $to]
                    );

                    return $translated;
                }
            } else {
                Log::error('Google Translate API error', [
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);

                // Log failure
                ApiUsageService::logFailure(
                    'Google Translate',
                    'translate_transcript',
                    'HTTP ' . $response->status() . ': ' . substr($response->body(), 0, 200),
                    null,
                    ['text_length' => strlen($text)]
                );
            }

            return $text;
        } catch (Exception $e) {
            Log::error('Google Translate exception', [
                'error' => $e->getMessage(),
                'text' => substr($text, 0, 100)
            ]);
            return $text;
        }
    }

    /**
     * Translate all segments using Gemini with full-article context awareness.
     * Sends all segment texts in one prompt so Gemini understands the whole article
     * and produces coherent, connected translations for each segment.
     */
    public function translateSegmentsWithGemini(array $segments, ?int $projectId = null, string $style = 'default'): array
    {
        $apiKey = env('GEMINI_API_KEY');
        if (!$apiKey) {
            Log::error('GEMINI_API_KEY not configured for translation');
            throw new Exception('GEMINI_API_KEY chưa được cấu hình');
        }

        $configuredModel = trim((string) env('GEMINI_MODEL', 'gemini-2.0-flash'));

        // Build numbered segment list for the prompt
        $segmentTexts = [];
        foreach ($segments as $index => $segment) {
            $text = $this->sanitizeUtf8(trim((string) ($segment['text'] ?? '')));
            $segmentTexts[$index] = $text;
        }

        // Split into batches to stay within token limits (~15 segments per batch)
        // Keeping batches small prevents Gemini from truncating mid-character (broken UTF-8)
        $batchSize = 15;
        $batches = array_chunk($segmentTexts, $batchSize, true);
        $allTranslations = [];

        // Build the full article context (used in every batch prompt)
        $fullArticle = implode("\n", array_filter($segmentTexts, fn($t) => $t !== ''));

        foreach ($batches as $batchSegments) {
            $numberedLines = [];
            foreach ($batchSegments as $idx => $text) {
                $lineNum = $idx + 1;
                $numberedLines[] = "[{$lineNum}] {$text}";
            }
            $numberedBlock = implode("\n", $numberedLines);

            $prompt = $this->buildGeminiTranslationPrompt($fullArticle, $numberedBlock, array_keys($batchSegments), $style);
            $batchResult = $this->callGeminiTranslation($apiKey, $configuredModel, $prompt, $projectId, $style);

            foreach ($batchSegments as $idx => $text) {
                $lineNum = $idx + 1;
                $allTranslations[$idx] = $this->sanitizeUtf8($batchResult[$lineNum] ?? $text);
            }
        }

        // Build translated segments with full metadata
        $translatedSegments = [];
        foreach ($segments as $index => $segment) {
            $sourceText = (string) ($segment['text'] ?? '');
            $startTime = $segment['start'] ?? $segment['start_time'] ?? 0;
            $endTime = $segment['end_time'] ?? ($startTime + ($segment['duration'] ?? 0));
            $duration = $segment['duration'] ?? 0;

            $translatedSegments[] = [
                'index' => $index,
                'original_text' => $this->sanitizeUtf8($sourceText),
                'text' => $this->sanitizeUtf8($allTranslations[$index] ?? $sourceText),
                'start' => $startTime,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'duration' => $duration,
            ];
        }

        // Log API usage
        $totalChars = mb_strlen($fullArticle, 'UTF-8');
        ApiUsageService::log([
            'api_type' => 'Gemini',
            'api_endpoint' => 'generateContent',
            'purpose' => 'translate_segments_context',
            'project_id' => $projectId,
            'characters_used' => $totalChars,
            'description' => "Context-aware translation: {$totalChars} chars, " . count($segments) . " segments",
            'estimated_cost' => 0,
        ]);

        return $translatedSegments;
    }

    private function buildGeminiTranslationPrompt(string $fullArticle, string $numberedBlock, array $indices, string $style = 'default'): string
    {
        $contextSnippet = mb_substr($fullArticle, 0, 6000, 'UTF-8');

        $styleInstructions = '';
        if ($style === 'humorous') {
            $styleInstructions = <<<STYLE

PHONG CÁCH DỊCH: HÀI HƯỚC & AN TOÀN
- Dùng giọng văn hài hước, dí dỏm, vui nhộn kiểu gen Z Việt Nam.
- TUYỆT ĐỐI tránh các từ nhạy cảm liên quan đến bạo lực, tình dục, giết chóc, máu me.
- Thay thế từ nhạy cảm bằng từ lóng hài hước, ví dụ:
  + kill/murder/giết → "cho về vườn", "cho đi chầu ông bà", "tiễn đi chuyến tàu cuối", "xóa sổ"
  + die/chết → "ngủm", "tạch", "bay màu", "offline vĩnh viễn"
  + blood/máu → "nước sốt cà chua", "sơn đỏ"
  + fight/đánh → "giao lưu võ thuật", "trao đổi nắm đấm"
  + weapon/vũ khí → "đồ chơi", "dụng cụ hỗ trợ"
  + stab/đâm → "chọc", "ghim"
  + shoot/bắn → "bấm nút", "gửi quà tốc hành"
  + war/chiến tranh → "đại hội thể thao", "lễ hội cosplay"
  + dead body/xác chết → "người ngủ quên", "NPC"
  + torture/tra tấn → "chăm sóc đặc biệt"
  + prison/tù → "khu nghỉ dưỡng miễn phí"
  + bomb/nổ → "pháo hoa", "bất ngờ bùm"
  + rape/hiếp → "làm chuyện xấu xa"
  + sex/quan hệ → "xem phim cùng nhau"
  + gun/súng → "ống thổi lửa", "gậy phép"
- Giữ nội dung hấp dẫn, cuốn hút dù đang kể chuyện nghiêm túc.
- Bản dịch phải an toàn cho mọi đối tượng và nền tảng (YouTube-friendly).
STYLE;
        }

        return <<<PROMPT
Bạn là dịch giả chuyên nghiệp. Nhiệm vụ: dịch từng đoạn dưới đây sang tiếng Việt.{$styleInstructions}

NGUYÊN TẮC QUAN TRỌNG:
1. Hiểu TOÀN BỘ nội dung bài viết trước khi dịch từng đoạn.
2. Bản dịch phải liền mạch, tự nhiên, đọc như một bài viết tiếng Việt hoàn chỉnh.
3. Giữ nguyên ý nghĩa gốc, nhưng diễn đạt tự nhiên theo cách nói tiếng Việt.
4. Đảm bảo các đoạn liên kết với nhau mạch lạc (đại từ, liên từ, ngữ cảnh nhất quán).
5. Không dịch máy móc từng từ - hãy hiểu ý rồi diễn đạt lại.
6. Giữ nguyên tên riêng, thuật ngữ chuyên ngành phổ biến.
7. Không thêm câu chào, không thêm giải thích, không thêm markdown.

NGỮ CẢNH TOÀN BÀI (để hiểu nội dung tổng thể):
---
{$contextSnippet}
---

CÁC ĐOẠN CẦN DỊCH (giữ nguyên số thứ tự):
{$numberedBlock}

TRẢ VỀ: Mỗi dòng bắt đầu bằng [số] rồi nội dung đã dịch. Ví dụ:
[1] Nội dung dịch đoạn 1
[2] Nội dung dịch đoạn 2

Chỉ trả về các dòng dịch, không giải thích thêm.
PROMPT;
    }

    private function callGeminiTranslation(string $apiKey, string $configuredModel, string $prompt, ?int $projectId, string $style = 'default'): array
    {
        $modelCandidates = array_values(array_unique(array_filter([
            ltrim($configuredModel, ' /'),
            'gemini-2.0-flash',
            'gemini-1.5-flash',
        ])));

        $response = null;
        $model = null;

        foreach ($modelCandidates as $candidateModel) {
            $candidateResponse = Http::timeout(120)->post(
                "https://generativelanguage.googleapis.com/v1beta/models/{$candidateModel}:generateContent?key={$apiKey}",
                [
                    'contents' => [
                        [
                            'role' => 'user',
                            'parts' => [['text' => $prompt]],
                        ],
                    ],
                    'generationConfig' => [
                        'temperature' => $style === 'humorous' ? 0.7 : 0.3,
                        'maxOutputTokens' => 65536,
                    ],
                ]
            );

            if ($candidateResponse->status() === 404) {
                continue;
            }

            $response = $candidateResponse;
            $model = $candidateModel;
            break;
        }

        if (!$response || !$response->successful()) {
            $statusCode = $response ? $response->status() : 404;
            Log::error('Gemini translation API error', ['status' => $statusCode]);

            ApiUsageService::logFailure(
                'Gemini',
                'translate_segments_context',
                'HTTP ' . $statusCode,
                $projectId,
                ['model' => $configuredModel]
            );

            throw new Exception('Gemini API lỗi: ' . $statusCode);
        }

        $data = $response->json();
        $finishReason = $data['candidates'][0]['finishReason'] ?? '';
        if ($finishReason === 'MAX_TOKENS') {
            Log::warning('Gemini translation truncated (MAX_TOKENS) — reduce batch size or increase maxOutputTokens', [
                'model'  => $model,
                'prompt_len' => mb_strlen($prompt, 'UTF-8'),
            ]);
        }
        $rawText = (string) ($data['candidates'][0]['content']['parts'][0]['text'] ?? '');
        $rawText = trim($rawText);

        // Remove markdown fences if present
        $rawText = preg_replace('/^```(?:text|markdown)?\s*/i', '', $rawText) ?? $rawText;
        $rawText = preg_replace('/\s*```$/', '', $rawText) ?? $rawText;

        // Parse numbered lines: [1] translated text
        $results = [];
        $lines = preg_split('/\R/', $rawText);
        $currentNum = null;
        $currentText = '';

        foreach ($lines as $line) {
            if (preg_match('/^\[(\d+)\]\s*(.*)$/', $line, $m)) {
                // Save previous
                if ($currentNum !== null) {
                    $results[$currentNum] = trim($currentText);
                }
                $currentNum = (int) $m[1];
                $currentText = $m[2];
            } elseif ($currentNum !== null) {
                // Continuation line
                $currentText .= "\n" . $line;
            }
        }

        // Save last
        if ($currentNum !== null) {
            $results[$currentNum] = trim($currentText);
        }

        return $results;
    }

    private function sanitizeUtf8(string $text): string
    {
        // Replace invalid UTF-8 sequences with empty string instead of '?'
        $cleaned = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        // Strip control characters (keep tab, newline, carriage-return)
        $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $cleaned) ?? $cleaned;
        // Remove Unicode replacement character (U+FFFD) produced by truncated responses
        $cleaned = str_replace("\u{FFFD}", '', $cleaned);
        return $cleaned;
    }

    private function isLikelyVietnamese(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return true;
        }

        // Quick signals for non-Vietnamese scripts.
        if (preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $text) === 1) {
            return false;
        }

        // Vietnamese-specific diacritics and common words.
        if (preg_match('/[ăâêôơưđáàảãạắằẳẵặấầẩẫậéèẻẽẹếềểễệóòỏõọốồổỗộớờởỡợúùủũụứừửữựíìỉĩịýỳỷỹỵ]/iu', $text) === 1) {
            return true;
        }

        $lower = mb_strtolower($text, 'UTF-8');
        $markers = [' không ', ' của ', ' những ', ' được ', ' và ', ' một ', ' trong ', ' với ', ' là '];
        foreach ($markers as $marker) {
            if (str_contains(' ' . $lower . ' ', $marker)) {
                return true;
            }
        }

        return false;
    }
}
