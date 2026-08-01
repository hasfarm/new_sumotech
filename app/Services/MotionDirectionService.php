<?php

namespace App\Services;

use App\Models\AudiobookVideoShot;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AI Director for per-shot Ken Burns motion + shot-transition selection. This service ONLY
 * decides WHICH preset applies (plus focus point/intensity) — it never touches ffmpeg or
 * builds any command; MotionRenderService owns that half entirely. Every value returned here is
 * independently validated/clamped against the whitelist below before ANY caller may use it,
 * regardless of what the AI actually said — mirrors ClipScoringService::clampScore()'s "never
 * trust the raw AI response" discipline, and is the core of the "AI must never generate shell
 * commands" safety requirement: the AI can only ever select one of a handful of hardcoded enum
 * strings, never free text that reaches a command line.
 */
class MotionDirectionService
{
    private const MODEL = 'gemini-3.6-flash';

    public const MOTION_PRESETS = ['static', 'micro_zoom', 'zoom_in', 'zoom_out', 'pan_left', 'pan_right', 'push', 'pull', 'shake'];
    public const TRANSITION_PRESETS = ['cut', 'fade', 'dissolve', 'fadeblack', 'slide', 'blur'];

    /** Bump whenever the analysis prompt changes meaningfully — same staleness convention as VideoSceneAnalysisService::PROMPT_VERSION. */
    public const MOTION_PROMPT_VERSION = 'v1';

    /**
     * Shown in the UI (never left blank) whenever Gemini's response is missing motion_reason/
     * transition_reason — a fallback, NOT a retry: the whitelist/preset/focus/intensity are
     * still perfectly usable even without a reason, so re-calling the AI just to fill in one
     * text field would double the cost for no safety benefit. Every time this fallback is used,
     * analyzeShot() logs a quality warning so missing-reason frequency stays visible without
     * blocking the pipeline over it.
     */
    public const REASON_FALLBACK = 'AI không cung cấp lý do cụ thể cho lựa chọn này — cần xem xét thủ công.';

    /**
     * One Gemini vision call analyzing a single shot's actual resolved image, deciding its
     * motion preset AND (when $includeTransition) its transition-in preset from the previous
     * shot. Callers must check `!$shot->isResolvedAssetVideo() && !$shot->is_avatar_segment`
     * BEFORE calling this for motion — this method doesn't re-check that itself, it just
     * analyzes whatever image bytes it's given. $previousContext is a short TEXT summary of the
     * last 1-2 shots' already-chosen presets (for variety), never re-sent as an image.
     *
     * @return array{motion_preset:string,focus_x:float,focus_y:float,motion_intensity:float,motion_reason:string,transition_preset:?string,transition_intensity:float,transition_reason:?string}
     */
    public function analyzeShot(
        AudiobookVideoShot $shot,
        string $imageBytes,
        string $mimeType,
        bool $includeTransition,
        string $previousContext = '',
        string $contextHint = ''
    ): array {
        $apiKey = trim((string) config('services.gemini.api_key', '')) ?: trim((string) config('services.gemini.tts_api_key', ''));
        if ($apiKey === '') {
            throw new \RuntimeException('Missing Gemini API key for motion analysis.');
        }

        $prompt = $this->buildPrompt($shot, $includeTransition, $previousContext, $contextHint);

        $response = Http::acceptJson()
            ->timeout(60)
            ->post('https://generativelanguage.googleapis.com/v1beta/models/' . self::MODEL . ':generateContent?key=' . $apiKey, [
                'contents' => [[
                    'parts' => [
                        ['text' => $prompt],
                        ['inlineData' => ['mimeType' => $mimeType, 'data' => base64_encode($imageBytes)]],
                    ],
                ]],
                'generationConfig' => [
                    'temperature' => 0.4,
                    'maxOutputTokens' => 1024,
                    'responseMimeType' => 'application/json',
                ],
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Gemini motion analysis thất bại: ' . $this->extractError($response));
        }

        $text = trim((string) data_get($response->json(), 'candidates.0.content.parts.0.text', ''));
        $decoded = $this->decodeJson($text);

        $result = $this->validateAndClamp($decoded, $includeTransition);

        // Quality warning only — never a retry. A missing reason doesn't make the preset/focus/
        // intensity any less safe to use; re-calling Gemini here would just double the cost of
        // every generate for a text field that's cosmetic, not safety-critical.
        if ($result['motion_reason_missing']) {
            Log::warning('MotionDirectionService: quality warning — empty motion_reason from AI', [
                'shot_id' => $shot->id,
                'motion_preset' => $result['motion_preset'],
            ]);
        }
        if ($includeTransition && $result['transition_reason_missing']) {
            Log::warning('MotionDirectionService: quality warning — empty transition_reason from AI', [
                'shot_id' => $shot->id,
                'transition_preset' => $result['transition_preset'],
            ]);
        }
        unset($result['motion_reason_missing'], $result['transition_reason_missing']);

        return $result;
    }

    /**
     * Every field independently validated/clamped here — the ONE place raw AI output is ever
     * touched before becoming data a controller may persist. An invalid/missing preset silently
     * falls back to the safest default (static/cut) rather than erroring, since a wrong-but-safe
     * motion choice is far better than blocking the whole pipeline over a parsing hiccup. Empty
     * reasons get the same treatment: fall back to REASON_FALLBACK rather than persisting "" (so
     * the UI is never blank), flagged via the *_missing keys for analyzeShot() to log — stripped
     * before the array is returned to callers.
     *
     * @return array{motion_preset:string,focus_x:float,focus_y:float,motion_intensity:float,motion_reason:string,motion_reason_missing:bool,transition_preset:?string,transition_intensity:float,transition_reason:?string,transition_reason_missing:bool}
     */
    private function validateAndClamp(array $decoded, bool $includeTransition): array
    {
        $motionPreset = (string) ($decoded['motion_preset'] ?? '');
        if (!in_array($motionPreset, self::MOTION_PRESETS, true)) {
            $motionPreset = 'static';
        }

        $clamp = fn($v, $default = 0.5) => is_numeric($v) ? max(0.0, min(1.0, (float) $v)) : $default;

        $motionReason = trim((string) ($decoded['motion_reason'] ?? ''));
        $motionReasonMissing = $motionReason === '';

        $result = [
            'motion_preset' => $motionPreset,
            'focus_x' => $clamp($decoded['focus_x'] ?? null),
            'focus_y' => $clamp($decoded['focus_y'] ?? null),
            'motion_intensity' => $clamp($decoded['motion_intensity'] ?? null, 0.3),
            'motion_reason' => $motionReasonMissing ? self::REASON_FALLBACK : $motionReason,
            'motion_reason_missing' => $motionReasonMissing,
            'transition_preset' => null,
            'transition_intensity' => 0.5,
            'transition_reason' => null,
            'transition_reason_missing' => false,
        ];

        if ($includeTransition) {
            $transitionPreset = (string) ($decoded['transition_preset'] ?? '');
            if (!in_array($transitionPreset, self::TRANSITION_PRESETS, true)) {
                $transitionPreset = 'cut';
            }
            $transitionReason = trim((string) ($decoded['transition_reason'] ?? ''));
            $transitionReasonMissing = $transitionReason === '';

            $result['transition_preset'] = $transitionPreset;
            $result['transition_intensity'] = $clamp($decoded['transition_intensity'] ?? null);
            $result['transition_reason'] = $transitionReasonMissing ? self::REASON_FALLBACK : $transitionReason;
            $result['transition_reason_missing'] = $transitionReasonMissing;
        }

        return $result;
    }

    private function buildPrompt(AudiobookVideoShot $shot, bool $includeTransition, string $previousContext, string $contextHint): string
    {
        $motionList = implode(', ', self::MOTION_PRESETS);
        $transitionList = implode(', ', self::TRANSITION_PRESETS);

        $prompt = "Bạn là đạo diễn hình ảnh, chọn chuyển động camera (Ken Burns) cho MỘT khung hình tĩnh trong video kể chuyện, dựa trên ảnh đính kèm và lời đọc.\n"
            . "Lời đọc của shot này: \"{$shot->sentence_text}\"\n"
            . ($contextHint !== '' ? "Bối cảnh sách: {$contextHint}\n" : '')
            . ($previousContext !== '' ? "Các shot LIỀN TRƯỚC đã chọn: {$previousContext}. TRÁNH lặp lại y hệt để giữ nhịp điệu đa dạng, trừ khi nội dung thực sự cần.\n" : '')
            . "\nChọn motion_preset — CHÍNH XÁC một trong: {$motionList}.\n"
            . "- static: ảnh gần như đứng yên, không có gì đáng nhấn (bản đồ, chữ, ảnh đã có nhiều chi tiết chuyển động).\n"
            . "- micro_zoom: zoom rất nhẹ gần như không nhận ra, dùng cho ảnh cần giữ điềm tĩnh nhưng không muốn 'chết' hoàn toàn.\n"
            . "- zoom_in: đẩy camera vào MỘT điểm nhấn cụ thể trong ảnh (nhân vật, chi tiết quan trọng) — dùng khi câu văn đang tập trung/nhấn mạnh vào điều gì đó.\n"
            . "- zoom_out: kéo camera ra để lộ toàn cảnh — dùng khi câu văn đang mở rộng góc nhìn/tiết lộ bối cảnh.\n"
            . "- pan_left / pan_right: lia ngang — dùng khi ảnh có bố cục ngang rõ rệt (đoàn người di chuyển, phong cảnh trải dài) và hướng lia PHẢI khớp hướng chuyển động/đọc trong ảnh.\n"
            . "- push: tiến lại gần nhẹ nhàng kèm zoom nhẹ — tạo cảm giác căng thẳng/tập trung tăng dần.\n"
            . "- pull: lùi ra xa nhẹ nhàng — tạo cảm giác cô lập/kết thúc/trầm lắng.\n"
            . "- shake: rung nhẹ — CHỈ dùng cho khoảnh khắc hỗn loạn/va chạm/chấn động mạnh, không lạm dụng.\n"
            . "focus_x, focus_y: tọa độ CHUẨN HÓA (0.0-1.0, 0.5=giữa ảnh) của điểm camera nên hướng vào/từ đó — dựa trên vị trí chủ thể/chi tiết quan trọng THẬT SỰ trong ảnh đính kèm.\n"
            . "motion_intensity: 0.0-1.0, mức độ chuyển động (0.15-0.3 cho hầu hết trường hợp, tránh chọn cao trừ khi nội dung thật sự kịch tính).\n"
            . "motion_reason: 1 câu tiếng Việt ngắn giải thích vì sao chọn preset này.\n";

        if ($includeTransition) {
            $prompt .= "\nChọn transition_preset — CHÍNH XÁC một trong: {$transitionList}, mô tả cách shot này CHUYỂN VÀO từ shot liền trước.\n"
                . "- cut: mặc định, hầu hết trường hợp — chuyển cảnh liền mạch trong cùng dòng thời gian/không khí.\n"
                . "- fade / fadeblack: dùng khi có khoảng lặng/chuyển thời gian/chuyển chương lớn.\n"
                . "- dissolve: chuyển mượt giữa hai khung hình liên quan chủ đề nhưng khác thời điểm/địa điểm.\n"
                . "- slide: chuyển động không gian rõ rệt (di chuyển địa điểm).\n"
                . "- blur: chuyển nhanh, dùng cho khoảnh khắc hồi tưởng/mơ hồ.\n"
                . "transition_intensity: 0.0-1.0 (thường 0.3-0.5).\n"
                . "transition_reason: 1 câu tiếng Việt ngắn.\n";
        }

        $prompt .= "\nTrả về JSON: {\"motion_preset\":\"...\",\"focus_x\":0.0,\"focus_y\":0.0,\"motion_intensity\":0.0,\"motion_reason\":\"...\""
            . ($includeTransition ? ',"transition_preset":"...","transition_intensity":0.0,"transition_reason":"..."' : '')
            . "}. Không thêm giải thích, không dùng markdown.";

        return $prompt;
    }

    private function decodeJson(string $text): array
    {
        $decoded = json_decode($text, true);
        if (!is_array($decoded)) {
            $cleaned = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?: $text;
            $decoded = json_decode(trim($cleaned), true);
        }
        if (!is_array($decoded)) {
            // Deliberately NOT throwing — a malformed response just falls back to safe defaults
            // via validateAndClamp(), same "never block the pipeline over motion" philosophy.
            Log::warning('MotionDirectionService: Gemini response was not valid JSON', ['text' => mb_substr($text, 0, 500)]);
            return [];
        }

        return $decoded;
    }

    private function extractError(Response $response): string
    {
        $json = $response->json();
        if (is_array($json)) {
            $message = data_get($json, 'error.message');
            if (is_string($message) && trim($message) !== '') {
                return $message . ' (HTTP ' . $response->status() . ')';
            }
        }

        return 'HTTP ' . $response->status();
    }
}
