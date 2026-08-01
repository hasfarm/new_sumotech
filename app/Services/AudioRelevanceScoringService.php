<?php

namespace App\Services;

/**
 * Text-only relevance scorer for reusable audio assets (SFX/ambience/music) — audio can't be
 * "seen" the way ClipScoringService vision-scores image/video candidates, so this compares a
 * candidate's stored prompt/keywords against a new request via a single cheap LLM call.
 * Deliberately an HONEST APPROXIMATION: it never listens to the candidate's actual audio, only
 * reasons about whether the stored text description plausibly matches. Real acoustic
 * similarity (fingerprint/chromaprint-style matching) is intentionally out of scope — see the
 * Audio Direction Pipeline plan's architecture notes.
 */
class AudioRelevanceScoringService
{
    public const SCORE_THRESHOLD = 75;

    public function __construct(private readonly OpenAiService $openAiService) {}

    /**
     * @param array<int,array{prompt:string,keywords:array<int,string>}> $candidates
     * @param array{prompt:string,keywords:array<int,string>,audio_category:string} $target
     * @return array<int,array{score_content:float,score_mood:float,score_final:float}> keyed the same as $candidates
     */
    public function scoreCandidates(array $candidates, array $target, string $contextHint = ''): array
    {
        if (empty($candidates)) {
            return [];
        }

        $targetKeywords = implode(', ', $target['keywords'] ?? []);
        $targetLine = "- Yêu cầu CẦN khớp: \"{$target['prompt']}\" (loại: {$target['audio_category']}, từ khóa: {$targetKeywords})\n";
        $contextLine = $contextHint !== '' ? "- Bối cảnh sách: {$contextHint}\n" : '';

        $candidateLines = collect($candidates)
            ->map(fn($c, $i) => "[{$i}] \"{$c['prompt']}\" (từ khóa: " . implode(', ', $c['keywords'] ?? []) . ')')
            ->implode("\n");

        $prompt = "Bạn là biên tập âm thanh, đánh giá xem một CLIP ÂM THANH ĐÃ CÓ SẴN trong thư viện nội bộ (chỉ biết qua MÔ TẢ VĂN BẢN của nó, KHÔNG được nghe trực tiếp) có đủ phù hợp để TÁI SỬ DỤNG cho một yêu cầu MỚI hay không, thay vì phải tạo âm thanh mới từ đầu.\n"
            . $targetLine . $contextLine . "\n"
            . "CÁC CLIP CÓ SẴN (chỉ so khớp Ý NGHĨA/NỘI DUNG mô tả):\n{$candidateLines}\n\n"
            . "Chấm điểm 0-100 cho MỖI clip theo 2 tiêu chí:\n"
            . "- content: mức độ LOẠI ÂM THANH/SỰ KIỆN âm thanh mô tả khớp với yêu cầu (vd cùng là tiếng kiếm chạm nhau, hay một cái là tiếng kiếm còn cái kia là tiếng búa đập — khác loại thì điểm thấp dù cùng chủ đề \"kim loại va chạm\").\n"
            . "- mood: mức độ KHÔNG KHÍ/CẢM XÚC/CƯỜNG ĐỘ phù hợp (căng thẳng/nhẹ nhàng/dữ dội...).\n"
            . "Chấm KHẮT KHE — chỉ điểm cao khi THỰC SỰ tương đồng, không phải chỉ cùng chủ đề chung chung. Trả về JSON đủ điểm cho TẤT CẢ clip theo đúng index đã cho.";

        $schema = [
            'name' => 'audio_relevance_scores',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'scores' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'index' => ['type' => 'integer'],
                                'score_content' => ['type' => 'number'],
                                'score_mood' => ['type' => 'number'],
                            ],
                            'required' => ['index', 'score_content', 'score_mood'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['scores'],
                'additionalProperties' => false,
            ],
        ];

        $decoded = $this->openAiService->completeJson($prompt, [
            'reasoning_effort' => 'minimal',
            'json_schema' => $schema,
            'max_tokens' => 2000,
            'purpose' => 'audio_relevance_scoring',
        ]);

        $results = [];
        foreach (array_keys($candidates) as $i) {
            $results[$i] = ['score_content' => 0.0, 'score_mood' => 0.0, 'score_final' => 0.0];
        }

        foreach ((is_array($decoded['scores'] ?? null) ? $decoded['scores'] : []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $idx = (int) ($row['index'] ?? -1);
            if (!array_key_exists($idx, $results)) {
                continue;
            }
            $content = max(0.0, min(100.0, (float) ($row['score_content'] ?? 0)));
            $mood = max(0.0, min(100.0, (float) ($row['score_mood'] ?? 0)));
            $results[$idx] = [
                'score_content' => $content,
                'score_mood' => $mood,
                'score_final' => round(($content + $mood) / 2, 1),
            ];
        }

        return $results;
    }
}
