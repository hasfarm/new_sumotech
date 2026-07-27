<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Audio-to-text transcription, with two interchangeable providers behind the same
 * [{start,end,text}, ...] segment shape: OpenAI Whisper (specialized ASR, forced-alignment
 * timestamps) and Gemini 2.5 Flash-Lite (general multimodal model, prompted/estimated
 * timestamps, ~20-30x cheaper). See config('services.transcription.provider') to switch the
 * default without touching call sites.
 */
class TranscriptionService
{
    /**
     * @return array<int,array{start:float,end:float,text:string}>
     */
    public function transcribe(string $audioFilePath, float $maxDuration = 0, ?string $provider = null): array
    {
        $provider = $provider ?: (string) config('services.transcription.provider', 'whisper');

        return $provider === 'gemini'
            ? $this->transcribeWithGemini($audioFilePath, $maxDuration)
            : $this->transcribeWithWhisper($audioFilePath, $maxDuration);
    }

    /**
     * @return array<int,array{start:float,end:float,text:string}>
     */
    public function transcribeWithWhisper(string $audioFilePath, float $maxDuration = 0): array
    {
        $apiKey = config('services.openai.api_key');
        if (!$apiKey) {
            Log::warning('TranscriptionService (whisper): OpenAI API key not configured');
            return [];
        }

        $tmpAudio = $this->extractAudio($audioFilePath, $maxDuration, 'whisper');
        if (!$tmpAudio) {
            return [];
        }

        try {
            $client = new \GuzzleHttp\Client();
            $response = $client->post('https://api.openai.com/v1/audio/transcriptions', [
                'headers' => ['Authorization' => 'Bearer ' . $apiKey],
                'multipart' => [
                    ['name' => 'file', 'contents' => fopen($tmpAudio, 'r'), 'filename' => 'clip.mp3'],
                    ['name' => 'model', 'contents' => 'whisper-1'],
                    ['name' => 'language', 'contents' => 'vi'],
                    ['name' => 'response_format', 'contents' => 'verbose_json'],
                    ['name' => 'timestamp_granularities[]', 'contents' => 'segment'],
                ],
                'timeout' => 120,
            ]);

            $result = json_decode($response->getBody()->getContents(), true);
            $segments = $result['segments'] ?? [];

            Log::info('TranscriptionService (whisper) done', [
                'file' => basename($audioFilePath),
                'segments' => count($segments),
                'duration' => $result['duration'] ?? null,
            ]);

            $mapped = [];
            foreach ($segments as $seg) {
                $text = trim((string) ($seg['text'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $mapped[] = [
                    'start' => (float) ($seg['start'] ?? 0),
                    'end' => (float) ($seg['end'] ?? 0),
                    'text' => $text,
                ];
            }

            return $mapped;
        } catch (\Throwable $e) {
            Log::warning('TranscriptionService (whisper) failed', ['error' => $e->getMessage()]);
            return [];
        } finally {
            @unlink($tmpAudio);
        }
    }

    /**
     * @return array<int,array{start:float,end:float,text:string}>
     */
    public function transcribeWithGemini(string $audioFilePath, float $maxDuration = 0): array
    {
        $apiKey = trim((string) config('services.gemini.api_key', '')) ?: trim((string) config('services.gemini.tts_api_key', ''));
        if ($apiKey === '') {
            Log::warning('TranscriptionService (gemini): GEMINI_API_KEY not configured');
            return [];
        }

        $tmpAudio = $this->extractAudio($audioFilePath, $maxDuration, 'gemini');
        if (!$tmpAudio) {
            return [];
        }

        $model = (string) config('services.gemini.transcription_model', 'gemini-2.5-flash-lite');

        try {
            $audioData = base64_encode((string) file_get_contents($tmpAudio));

            $prompt = "Bạn là công cụ nhận dạng giọng nói (ASR). Hãy phiên âm CHÍNH XÁC NGUYÊN VĂN đoạn audio tiếng Việt đính kèm.\n"
                . "Chia thành các đoạn (segment) theo câu/cụm tự nhiên, MỖI đoạn kèm thời điểm bắt đầu (start) và kết thúc (end) TÍNH BẰNG GIÂY (số thực, ví dụ 12.5), ước lượng CÀNG SÁT THỜI ĐIỂM THỰC TẾ TRONG AUDIO CÀNG TỐT, theo đúng thứ tự xuất hiện, không bỏ sót, không thêm nội dung không có trong audio.\n"
                . "Trả về STRICT JSON: {\"segments\":[{\"start\":0.0,\"end\":2.5,\"text\":\"...\"}]}\n"
                . "Không thêm giải thích, không dùng markdown.";

            $response = Http::acceptJson()
                ->connectTimeout(15)
                ->timeout(120)
                ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}", [
                    'contents' => [[
                        'parts' => [
                            ['text' => $prompt],
                            ['inlineData' => ['mimeType' => 'audio/mp3', 'data' => $audioData]],
                        ],
                    ]],
                    'generationConfig' => [
                        'temperature' => 0.1,
                        'maxOutputTokens' => 8192,
                        'responseMimeType' => 'application/json',
                    ],
                ]);

            if (!$response->successful()) {
                Log::warning('TranscriptionService (gemini) HTTP error', [
                    'status' => $response->status(),
                    'body' => mb_substr((string) $response->body(), 0, 500),
                ]);
                return [];
            }

            $text = trim((string) data_get($response->json(), 'candidates.0.content.parts.0.text', ''));
            if ($text === '') {
                Log::warning('TranscriptionService (gemini): empty response', [
                    'finishReason' => data_get($response->json(), 'candidates.0.finishReason'),
                ]);
                return [];
            }

            $decoded = json_decode($text, true);
            if (!is_array($decoded)) {
                $cleaned = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?: $text;
                $decoded = json_decode(trim($cleaned), true);
            }

            $segments = is_array($decoded['segments'] ?? null) ? $decoded['segments'] : [];

            Log::info('TranscriptionService (gemini) done', [
                'file' => basename($audioFilePath),
                'segments' => count($segments),
                'model' => $model,
            ]);

            $mapped = [];
            foreach ($segments as $seg) {
                if (!is_array($seg)) {
                    continue;
                }
                $segText = trim((string) ($seg['text'] ?? ''));
                if ($segText === '') {
                    continue;
                }
                $mapped[] = [
                    'start' => (float) ($seg['start'] ?? 0),
                    'end' => (float) ($seg['end'] ?? 0),
                    'text' => $segText,
                ];
            }

            return $mapped;
        } catch (\Throwable $e) {
            Log::warning('TranscriptionService (gemini) failed', ['error' => $e->getMessage()]);
            return [];
        } finally {
            @unlink($tmpAudio);
        }
    }

    /**
     * Extract a small 16kHz mono mp3 (same prep for both providers) — keeps upload size and
     * processing cost minimal regardless of which provider ends up handling it.
     */
    private function extractAudio(string $audioFilePath, float $maxDuration, string $tag): ?string
    {
        if (!file_exists($audioFilePath) || !is_readable($audioFilePath)) {
            return null;
        }

        $ffmpegPath = config('services.ffmpeg.path', env('FFMPEG_PATH', 'ffmpeg'));
        $tmpAudio = sys_get_temp_dir() . "/clip_{$tag}_" . md5($audioFilePath) . '_' . time() . '.mp3';

        $extractCmd = sprintf(
            '%s -y -i %s -vn -ar 16000 -ac 1 -b:a 64k %s %s 2>&1',
            escapeshellarg($ffmpegPath),
            escapeshellarg($audioFilePath),
            $maxDuration > 0 ? sprintf('-t %.3f', $maxDuration) : '',
            escapeshellarg($tmpAudio)
        );
        exec($extractCmd, $output, $extractCode);

        if ($extractCode !== 0 || !file_exists($tmpAudio) || filesize($tmpAudio) < 100) {
            Log::warning('TranscriptionService: ffmpeg audio extraction failed', [
                'tag' => $tag,
                'code' => $extractCode,
                'output' => implode("\n", array_slice($output, -10)),
            ]);
            @unlink($tmpAudio);
            return null;
        }

        return $tmpAudio;
    }
}
