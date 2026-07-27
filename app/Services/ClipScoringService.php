<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ClipScoringService
{
    private const MODEL = 'gemini-3.6-flash';
    public const SCORE_THRESHOLD = 75;

    /**
     * Score every candidate for one shot CONCURRENTLY (thumbnail downloads in one pooled
     * round, then Gemini vision calls in another pooled round) instead of one-by-one —
     * this is the dominant cost of "Tìm nguồn" and sequential scoring made it very slow.
     *
     * @param array<int,array<string,mixed>> $candidates normalized candidates (see StockSources adapters)
     * @param array{title:string,scene_type:string,keywords:array<int,string>} $scene
     * @return array<int,array{score_semantic:float,score_historical:float,score_resolution:float,score_mood:float,score_license:float,score_final:float}> keyed the same as $candidates
     */
    public function scoreCandidates(array $candidates, array $scene, string $contextHint = ''): array
    {
        if (empty($candidates)) {
            return [];
        }

        // Check cultural/historical consistency whenever the book has a known setting —
        // a mismatch (e.g. Roman soldiers illustrating a Chinese classic) can show up in
        // any scene depicting people/architecture/objects, not just history/map scenes.
        $checkCultural = $contextHint !== '' || in_array($scene['scene_type'] ?? '', ['history', 'map'], true);

        // Http::pool does NOT preserve array keys from how requests are added to $requests —
        // it reindexes sequentially unless each request is explicitly tagged via ->as(),
        // which IS honored for the result array's keys. Tag with the candidate index so
        // results can be matched back up after both pooled rounds.
        $thumbnailResponses = Http::pool(function (Pool $pool) use ($candidates) {
            $requests = [];
            foreach ($candidates as $i => $candidate) {
                $url = (string) ($candidate['thumbnail_url'] ?? '');
                if ($url === '') {
                    continue;
                }
                $requests[] = $pool->as((string) $i)
                    ->withHeaders(['User-Agent' => 'SumoTech-VideoPipeline/1.0 (contact@sumotech.ai)'])
                    ->timeout(15)
                    ->get($url);
            }
            return $requests;
        });

        $imageInputs = [];
        foreach ($thumbnailResponses as $i => $imgResponse) {
            if (!is_object($imgResponse) || !method_exists($imgResponse, 'successful') || !$imgResponse->successful()) {
                continue;
            }

            $mimeType = (string) $imgResponse->header('Content-Type');
            $imageInputs[$i] = [
                'bytes' => $imgResponse->body(),
                'mime_type' => str_starts_with($mimeType, 'image/') ? $mimeType : 'image/jpeg',
            ];
        }

        $visionResponses = $this->poolVisionRequests($imageInputs, $scene, $contextHint, $checkCultural);

        return $this->assembleScores($candidates, $visionResponses, $checkCultural);
    }

    /**
     * Same scoring pipeline as {@see scoreCandidates()} but for candidates whose image bytes
     * are already in hand (library candidates fetched from R2) — skips the thumbnail-download
     * pooled round entirely.
     *
     * @param array<int,array{bytes:string,mime_type:string,width?:?int,height?:?int,source?:string,license_label?:?string}> $items
     * @param array{title:string,scene_type:string,keywords:array<int,string>} $scene
     * @return array<int,array{score_semantic:float,score_historical:float,score_resolution:float,score_mood:float,score_license:float,score_final:float}>
     */
    public function scoreByBytes(array $items, array $scene, string $contextHint = ''): array
    {
        if (empty($items)) {
            return [];
        }

        $checkCultural = $contextHint !== '' || in_array($scene['scene_type'] ?? '', ['history', 'map'], true);

        $imageInputs = [];
        foreach ($items as $i => $item) {
            $imageInputs[$i] = [
                'bytes' => $item['bytes'],
                'mime_type' => $item['mime_type'] ?? 'image/jpeg',
            ];
        }

        $visionResponses = $this->poolVisionRequests($imageInputs, $scene, $contextHint, $checkCultural);

        return $this->assembleScores($items, $visionResponses, $checkCultural);
    }

    /**
     * @param array<int,array{bytes:string,mime_type:string}> $imageInputs
     * @param array{title:string,scene_type:string,keywords:array<int,string>} $scene
     * @return array<int,mixed> pooled Http responses keyed the same as $imageInputs
     */
    private function poolVisionRequests(array $imageInputs, array $scene, string $contextHint, bool $checkCultural): array
    {
        $apiKey = trim((string) config('services.gemini.api_key', '')) ?: trim((string) config('services.gemini.tts_api_key', ''));
        if ($apiKey === '' || empty($imageInputs)) {
            return [];
        }

        $prompt = $this->buildVisionPrompt($scene, $contextHint, $checkCultural);

        return Http::pool(function (Pool $pool) use ($imageInputs, $apiKey, $prompt) {
            $requests = [];
            foreach ($imageInputs as $i => $input) {
                $requests[] = $pool->as((string) $i)
                    ->acceptJson()
                    ->timeout(60)
                    ->post('https://generativelanguage.googleapis.com/v1beta/models/' . self::MODEL . ':generateContent?key=' . $apiKey, [
                        'contents' => [[
                            'parts' => [
                                ['text' => $prompt],
                                ['inlineData' => ['mimeType' => $input['mime_type'], 'data' => base64_encode($input['bytes'])]],
                            ],
                        ]],
                        'generationConfig' => [
                            'temperature' => 0.2,
                            'maxOutputTokens' => 4096,
                            'responseMimeType' => 'application/json',
                        ],
                    ]);
            }
            return $requests;
        });
    }

    /**
     * @param array<int,array<string,mixed>> $candidates
     * @param array<int,mixed> $visionResponses
     * @return array<int,array{score_semantic:float,score_historical:float,score_resolution:float,score_mood:float,score_license:float,score_final:float}>
     */
    private function assembleScores(array $candidates, array $visionResponses, bool $checkCultural): array
    {
        $results = [];
        foreach ($candidates as $i => $candidate) {
            $resolution = $this->scoreResolution($candidate['width'] ?? null, $candidate['height'] ?? null);
            $license = $this->scoreLicense($candidate);
            [$semantic, $historical, $mood] = $this->parseVisionResult($visionResponses[$i] ?? null, $checkCultural);

            $results[$i] = [
                'score_semantic' => $semantic,
                'score_historical' => $historical,
                'score_resolution' => $resolution,
                'score_mood' => $mood,
                'score_license' => $license,
                'score_final' => round(($semantic + $historical + $resolution + $mood + $license) / 5, 1),
            ];
        }

        return $results;
    }

    /**
     * Single-candidate convenience wrapper around {@see scoreCandidates()}.
     *
     * @param array<string,mixed> $candidate
     * @param array{title:string,scene_type:string,keywords:array<int,string>} $scene
     * @return array{score_semantic:float,score_historical:float,score_resolution:float,score_mood:float,score_license:float,score_final:float}
     */
    public function scoreCandidate(array $candidate, array $scene, string $contextHint = ''): array
    {
        return $this->scoreCandidates([$candidate], $scene, $contextHint)[0];
    }

    /**
     * @param mixed $visionResponse a pooled Http response, or null/exception if it failed
     * @return array{0:float,1:float,2:float} [semantic, historical, mood]
     */
    private function parseVisionResult(mixed $visionResponse, bool $checkCultural): array
    {
        $fallback = [50.0, $checkCultural ? 50.0 : 100.0, 50.0];

        if (!is_object($visionResponse) || !method_exists($visionResponse, 'successful') || !$visionResponse->successful()) {
            return $fallback;
        }

        try {
            $finishReason = (string) data_get($visionResponse->json(), 'candidates.0.finishReason', '');
            $text = trim((string) data_get($visionResponse->json(), 'candidates.0.content.parts.0.text', ''));

            if ($finishReason === 'MAX_TOKENS' || $text === '') {
                return $fallback;
            }

            $decoded = $this->decodeJsonResponse($text);

            return [
                $this->clampScore($decoded['semantic'] ?? 50),
                $checkCultural ? $this->clampScore($decoded['historical'] ?? 50) : 100.0,
                $this->clampScore($decoded['mood'] ?? 50),
            ];
        } catch (\Throwable $e) {
            Log::warning('ClipScoringService batch vision parse failed', ['error' => $e->getMessage()]);
            return $fallback;
        }
    }

    /**
     * @param array{title:string,scene_type:string,keywords:array<int,string>,shot_text?:string} $scene
     */
    private function buildVisionPrompt(array $scene, string $contextHint, bool $checkCultural): string
    {
        $contextLine = $contextHint !== '' ? "- Bối cảnh văn hóa/lịch sử của sách: {$contextHint}\n" : '';
        $shotTextLine = !empty($scene['shot_text']) ? "- Câu narration của shot này: \"{$scene['shot_text']}\"\n" : '';

        return "Bạn là biên tập viên hình ảnh khó tính, đánh giá mức độ phù hợp của ảnh đính kèm (thumbnail của một video/ảnh stock) để làm B-ROLL minh họa cho một shot trong video kể chuyện dạng tài liệu (documentary).\n"
            . "Bối cảnh cần minh họa:\n"
            . "- Tiêu đề cảnh lớn: {$scene['title']}\n"
            . "- Loại cảnh: {$scene['scene_type']}\n"
            . $shotTextLine
            . "- Từ khóa tìm kiếm: " . implode(', ', $scene['keywords'] ?? []) . "\n"
            . $contextLine . "\n"
            . "Chấm điểm 0-100 theo 3 tiêu chí:\n"
            . "- semantic: mức độ ảnh THẬT SỰ minh họa đúng NỘI DUNG/HÀNH ĐỘNG của câu narration nêu trên (0=hoàn toàn không liên quan, 100=khớp hoàn hảo).\n"
            . "  QUAN TRỌNG - phân biệt \"cùng chủ đề\" với \"minh họa đúng\": nếu câu narration mô tả một HÀNH ĐỘNG/SỰ KIỆN/KHUNG CẢNH đang diễn ra (hành quân, chiến đấu, sinh hoạt, phong cảnh...) nhưng ảnh lại là TƯỢNG/ĐIÊU KHẮC/HIỆN VẬT BẢO TÀNG/TRANH VẼ/MÔ HÌNH tĩnh (dù cùng chủ đề văn hóa, ví dụ tượng binh mã đất nung minh họa cho \"quân đội hành quân\"), hãy CHẤM semantic THẤP (dưới 35) vì đây chỉ là hiện vật trưng bày, không phải cảnh thật đang diễn ra. Chỉ chấm cao cho tượng/hiện vật nếu câu narration NÓI TRỰC TIẾP về tượng/di tích/hiện vật đó.\n"
            . ($checkCultural
                ? "- historical: mức độ chính xác/phù hợp về NỀN VĂN HÓA/DÂN TỘC/THỜI ĐẠI của ảnh so với bối cảnh sách nêu trên (0=sai hoàn toàn nền văn hóa, vd ảnh lính La Mã/hiệp sĩ châu Âu trong khi sách bối cảnh Trung Hoa cổ đại; 100=đúng nền văn hóa/thời đại). Nếu ảnh không có yếu tố con người/kiến trúc/trang phục rõ ràng để đánh giá văn hóa (vd cảnh thiên nhiên thuần túy), cho điểm cao (80-100).\n"
                : "- historical: cảnh này KHÔNG nhạy cảm về thời đại/văn hóa, LUÔN trả về 100.\n")
            . "- mood: mức độ phù hợp không khí/cảm xúc/tông màu so với tinh thần cảnh.\n"
            . "Trả về STRICT JSON: {\"semantic\": 0-100, \"historical\": 0-100, \"mood\": 0-100}\n"
            . "Không thêm giải thích, không dùng markdown.";
    }

    private function scoreResolution(?int $width, ?int $height): float
    {
        if (!$width || !$height) {
            return 50.0;
        }

        $longSide = max($width, $height);

        if ($longSide >= 1920) return 100.0;
        if ($longSide >= 1280) return 80.0;
        if ($longSide >= 854) return 60.0;
        if ($longSide >= 640) return 40.0;

        return 20.0;
    }

    /**
     * @param array<string,mixed> $candidate
     */
    private function scoreLicense(array $candidate): float
    {
        $source = (string) ($candidate['source'] ?? '');
        // pexels/pixabay: free commercial use, no attribution. ai_image/ai_video: generated
        // by us, fully owned — same "no licensing restriction" guarantee.
        if (in_array($source, ['pexels', 'pixabay', 'ai_image', 'ai_video'], true)) {
            return 100.0;
        }

        $label = mb_strtolower((string) ($candidate['license_label'] ?? ''));
        if ($label === '') {
            return 40.0;
        }

        if (str_contains($label, 'cc0') || str_contains($label, 'public domain') || str_contains($label, 'no known copyright')) {
            return 100.0;
        }

        if (str_contains($label, 'cc-by') || str_contains($label, 'cc by')) {
            if (str_contains($label, 'nc') || str_contains($label, 'non-commercial') || str_contains($label, 'noncommercial')) {
                return 20.0;
            }
            if (str_contains($label, 'nd') || str_contains($label, 'no derivatives') || str_contains($label, 'noderivatives')) {
                return 40.0;
            }
            return 85.0;
        }

        return 30.0;
    }

    private function clampScore(mixed $value): float
    {
        return max(0.0, min(100.0, (float) $value));
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJsonResponse(string $text): array
    {
        $decoded = json_decode($text, true);

        if (!is_array($decoded)) {
            $cleaned = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?: $text;
            $decoded = json_decode(trim($cleaned), true);
        }

        if (!is_array($decoded)) {
            throw new \RuntimeException('Gemini Vision trả về nội dung không phải JSON hợp lệ.');
        }

        return $decoded;
    }
}
