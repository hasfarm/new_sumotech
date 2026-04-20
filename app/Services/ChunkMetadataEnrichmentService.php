<?php

namespace App\Services;

use App\Models\AudioBookChapterChunk;
use Illuminate\Support\Facades\Http;

class ChunkMetadataEnrichmentService
{
    /**
     * @return array<string,mixed>
     */
    public function enrich(AudioBookChapterChunk $chunk): array
    {
        $text = trim((string) $chunk->text_content);
        $chapter = $chunk->chapter;

        $rule = $this->buildRuleBasedMetadata($text);
        $ruleConfidence = $this->assessRuleConfidence($rule, $text);
        $llm = [];

        if ($this->shouldUseLlmFallback($ruleConfidence)) {
            $llm = $this->buildLlmMetadata($chunk, $text);
        }

        $characterTags = array_values(array_unique(array_merge(
            $rule['character_tags'],
            $llm['character_tags'] ?? []
        )));

        $topicTags = array_values(array_unique(array_merge(
            $rule['topic_tags'],
            $llm['topic_tags'] ?? []
        )));

        $sceneTypes = array_values(array_unique(array_merge(
            $rule['scene_type'],
            $llm['scene_type'] ?? []
        )));
        if (empty($sceneTypes)) {
            $sceneTypes = ['general'];
        }

        $importanceScore = (float) $rule['importance_score'];
        if (isset($llm['importance_score']) && is_numeric($llm['importance_score'])) {
            $importanceScore = round((($importanceScore * 0.6) + (((float) $llm['importance_score']) * 0.4)), 4);
        }

        return [
            'book_id' => $chapter ? (int) $chapter->audio_book_id : null,
            'chapter_id' => (int) $chunk->audiobook_chapter_id,
            'chunk_index' => (int) $chunk->chunk_number,
            'character_tags' => array_slice($characterTags, 0, 12),
            'scene_type' => array_slice($sceneTypes, 0, 8),
            'topic_tags' => array_slice($topicTags, 0, 10),
            'importance_score' => max(0.0, min(1.0, $importanceScore)),
        ];
    }

    /**
     * @return array{character_tags:array<int,string>,scene_type:array<int,string>,topic_tags:array<int,string>,importance_score:float}
     */
    private function buildRuleBasedMetadata(string $text): array
    {
        $characterTags = $this->extractCharacterTags($text);
        $sceneType = $this->inferSceneTypes($text);
        $topicTags = $this->extractTopicTags($text);
        $importanceScore = $this->estimateImportanceScore($text, $sceneType, $characterTags, $topicTags);

        return [
            'character_tags' => $characterTags,
            'scene_type' => $sceneType,
            'topic_tags' => $topicTags,
            'importance_score' => $importanceScore,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildLlmMetadata(AudioBookChapterChunk $chunk, string $text): array
    {
        if (!(bool) config('services.qdrant.enrich_with_llm', false)) {
            return [];
        }

        $apiKey = (string) config('services.openai.api_key', '');
        if ($apiKey === '' || $text === '') {
            return [];
        }

        $baseUrl = rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/');
        $model = (string) config('services.openai.chat_model', 'gpt-4o-mini');

        $prompt = "Analyze this story chunk and return STRICT JSON with keys: character_tags(array of strings), scene_type(array of strings), topic_tags(array of strings), importance_score(number 0..1).\n"
            . "Chunk:\n" . mb_substr($text, 0, 2200) . "\n"
            . "Current chapter id: " . (int) $chunk->audiobook_chapter_id . ", chunk index: " . (int) $chunk->chunk_number . ".\n"
            . "Use short snake_case labels for scene_type and topic_tags. scene_type can include values like betrayal, exile, battle, turning_point, dialogue.";

        try {
            $response = Http::acceptJson()
                ->withToken($apiKey)
                ->timeout(60)
                ->post($baseUrl . '/chat/completions', [
                    'model' => $model,
                    'temperature' => 0.2,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You extract metadata for retrieval. Return valid JSON only.',
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt,
                        ],
                    ],
                ]);

            if (!$response->successful()) {
                return [];
            }

            $content = (string) data_get($response->json(), 'choices.0.message.content', '');
            if ($content === '') {
                return [];
            }

            $decoded = json_decode($content, true);
            if (!is_array($decoded)) {
                return [];
            }

            return [
                'character_tags' => $this->sanitizeStringArray($decoded['character_tags'] ?? []),
                'scene_type' => $this->sanitizeSceneTypes($decoded['scene_type'] ?? []),
                'topic_tags' => $this->sanitizeStringArray($decoded['topic_tags'] ?? []),
                'importance_score' => $this->sanitizeImportanceScore($decoded['importance_score'] ?? null),
            ];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
    * @param array{character_tags:array<int,string>,scene_type:array<int,string>,topic_tags:array<int,string>,importance_score:float} $rule
     * @return array{score:float,signals:int,reasons:array<int,string>}
     */
    private function assessRuleConfidence(array $rule, string $text): array
    {
        $minCharacterTags = (int) config('services.qdrant.enrich_low_confidence_min_character_tags', 2);
        $minImportanceScore = (float) config('services.qdrant.enrich_low_confidence_min_importance_score', 0.55);
        $minTextLength = (int) config('services.qdrant.enrich_low_confidence_min_text_length', 120);

        $signals = 0;
        $reasons = [];

        if (count($rule['character_tags']) < max(0, $minCharacterTags)) {
            $signals++;
            $reasons[] = 'few_character_tags';
        }

        $sceneTypes = $rule['scene_type'] ?? [];
        if (empty($sceneTypes) || (count($sceneTypes) === 1 && ($sceneTypes[0] ?? '') === 'general')) {
            $signals++;
            $reasons[] = 'generic_scene_type';
        }

        if (empty($rule['topic_tags'])) {
            $signals++;
            $reasons[] = 'empty_topic_tags';
        }

        if ((float) ($rule['importance_score'] ?? 0) < $minImportanceScore) {
            $signals++;
            $reasons[] = 'low_importance_score';
        }

        if (mb_strlen($text) < max(0, $minTextLength)) {
            $signals++;
            $reasons[] = 'short_text';
        }

        $score = 1 - min(1, ($signals / 5));

        return [
            'score' => round($score, 4),
            'signals' => $signals,
            'reasons' => $reasons,
        ];
    }

    /**
     * @param array{score:float,signals:int,reasons:array<int,string>} $confidence
     */
    private function shouldUseLlmFallback(array $confidence): bool
    {
        if (!(bool) config('services.qdrant.enrich_with_llm', false)) {
            return false;
        }

        $requiredSignals = (int) config('services.qdrant.enrich_low_confidence_required_signals', 2);

        return (int) ($confidence['signals'] ?? 0) >= max(1, $requiredSignals);
    }

    /**
     * @return array<int,string>
     */
    private function extractCharacterTags(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $counts = [];
        if (preg_match_all('/\b[\p{Lu}][\p{L}\p{Mn}\p{Pd}]{1,}(?:\s+[\p{Lu}][\p{L}\p{Mn}\p{Pd}]{1,}){0,2}\b/u', $text, $matches)) {
            foreach ($matches[0] as $candidate) {
                $candidate = trim((string) $candidate);
                if ($candidate === '' || mb_strlen($candidate) < 2) {
                    continue;
                }
                $counts[$candidate] = ($counts[$candidate] ?? 0) + 1;
            }
        }

        if (empty($counts)) {
            return [];
        }

        arsort($counts);
        return array_slice(array_keys($counts), 0, 8);
    }

    /**
     * @return array<int,string>
     */
    private function inferSceneTypes(string $text): array
    {
        $content = mb_strtolower($text);
        $map = [
            'dialogue' => ['noi', 'hoi', 'dap', 'tra loi', 'doi thoai'],
            'battle' => ['danh', 'chem', 'tan cong', 'giao tranh', 'duoi'],
            'betrayal' => ['phan boi', 'tro mat', 'lua doi', 'phu bac'],
            'exile' => ['luu day', 'truat xuat', 'bi duoi', 'xa xu'],
            'turning_point' => ['buoc ngoat', 'bien co', 'bat ngo', 'thay doi'],
        ];

        $matched = [];
        foreach ($map as $type => $keywords) {
            foreach ($keywords as $kw) {
                if (mb_stripos($content, $kw) !== false) {
                    $matched[] = $type;
                    break;
                }
            }
        }

        $matched = array_values(array_unique($matched));
        return !empty($matched) ? $matched : ['general'];
    }

    /**
     * @return array<int,string>
     */
    private function extractTopicTags(string $text): array
    {
        $content = mb_strtolower($text);
        $topics = [
            'hanh_trinh_nhan_vat' => ['hanh trinh', 'len duong', 'truong thanh'],
            'xung_dot_noi_tam' => ['noi tam', 'do du', 'day dut', 'phan van'],
            'buoc_ngoat' => ['buoc ngoat', 'bien co', 'thay doi', 'bat ngo'],
            'thong_diep' => ['thong diep', 'bai hoc', 'gia tri', 'y nghia'],
            'quan_he_nhan_vat' => ['ban be', 'ke thu', 'gia dinh', 'dong doi', 'su phu'],
        ];

        $result = [];
        foreach ($topics as $key => $keywords) {
            foreach ($keywords as $kw) {
                if (mb_stripos($content, $kw) !== false) {
                    $result[] = $key;
                    break;
                }
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * @param array<int,string> $sceneTypes
     * @param array<int,string> $characterTags
     * @param array<int,string> $topicTags
     */
    private function estimateImportanceScore(string $text, array $sceneTypes, array $characterTags, array $topicTags): float
    {
        $lengthScore = min(1.0, mb_strlen($text) / 1200);
        $characterScore = min(1.0, count($characterTags) / 5);
        $topicScore = min(1.0, count($topicTags) / 4);
        $sceneBoost = (!empty($sceneTypes) && !(count($sceneTypes) === 1 && ($sceneTypes[0] ?? '') === 'general')) ? 0.15 : 0.0;

        $score = ($lengthScore * 0.45) + ($characterScore * 0.25) + ($topicScore * 0.15) + $sceneBoost;
        return round(max(0.0, min(1.0, $score)), 4);
    }

    /**
     * @param mixed $value
     * @return array<int,string>
     */
    private function sanitizeStringArray($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $items[] = $item;
        }

        return array_values(array_unique($items));
    }

    /**
     * @param mixed $value
     */
    /**
     * @param mixed $value
     * @return array<int,string>
     */
    private function sanitizeSceneTypes($value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }

        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }

            $normalized = trim(mb_strtolower($item));
            if ($normalized === '') {
                continue;
            }

            $normalized = preg_replace('/[^a-z0-9_\-]/', '', $normalized) ?: '';
            if ($normalized === '') {
                continue;
            }

            $items[] = $normalized;
        }

        return array_values(array_unique($items));
    }

    /**
     * @param mixed $value
     */
    private function sanitizeImportanceScore($value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }
        $score = (float) $value;
        return max(0.0, min(1.0, $score));
    }
}
