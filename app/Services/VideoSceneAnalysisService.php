<?php

namespace App\Services;

use App\Models\AudioBook;
use App\Models\AudiobookCharacter;
use App\Models\AudiobookContinuityIssue;
use App\Models\AudiobookStoryBible;
use App\Models\AudiobookSummary;
use App\Models\AudiobookVideoScene;
use App\Models\AudiobookVideoSceneCharacter;
use App\Models\AudiobookVideoShot;
use App\Models\AudiobookVideoShotCharacter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VideoSceneAnalysisService
{
    private const MODEL = 'gemini-3.6-flash';

    public function __construct(
        private readonly ClaudeService $claudeService,
        private readonly OpenAiService $openAiService
    ) {}

    /** Target narration pace used to estimate spoken duration from character count. */
    private const NARRATION_CHARS_PER_MINUTE = 800;

    private const MIN_SCENE_CHARS = 4000;
    private const MAX_SCENE_CHARS = 8000;

    /** Target/max narration length per shot — a shot is the unit that gets ONE resolved
     *  clip/image, so it must match what AI video-generation tools actually support
     *  (~5-8s target, 10s hard cap — they don't generate longer single clips). */
    private const SHOT_TARGET_CHARS = 100; // ≈7.5s at 800 chars/min
    private const SHOT_MAX_CHARS = 133; // ≈10s hard cap

    public const SCENE_TYPES = ['nature', 'city', 'history', 'character', 'map', 'philosophy'];

    /**
     * Bump whenever enrichShotsChunk()'s prompt changes meaningfully — EnrichVideoShotsJob
     * compares this against each shot's stored `prompt_version` to decide whether a
     * previously-"done" shot is actually stale and needs re-enrichment.
     */
    public const PROMPT_VERSION = 'v1';

    /**
     * Bump whenever assignSceneContext()'s prompt/logic changes meaningfully — independent
     * of the Story Bible's own bible_version. Two separate staleness triggers: the bible's
     * CONTENT changed, or the ASSIGNMENT LOGIC changed.
     */
    public const SCENE_DIRECTION_VERSION = 'v1';

    /**
     * Resolve the finished narration script to build scenes from: a saved version
     * if $versionId is given, otherwise the summary's current (in-progress) retells.
     *
     * @return array<int,array{cluster_index:int|null,text:string,is_outro:bool}>
     */
    public function resolveScriptSegments(AudiobookSummary $summary, ?string $versionId): array
    {
        $retells = $summary->retells ?? [];
        $outro = $summary->outro;

        if ($versionId !== null) {
            $version = collect($summary->versions ?? [])->firstWhere('id', $versionId);
            if ($version) {
                $retells = $version['retells'] ?? [];
                $outro = $version['outro'] ?? null;
            }
        }

        $clusterOrder = collect($summary->clusters ?? [])
            ->pluck('index')
            ->map(fn($i) => (int) $i)
            ->sort()
            ->values()
            ->all();

        $segments = [];
        foreach ($clusterOrder as $clusterIndex) {
            $text = trim((string) ($retells[(string) $clusterIndex] ?? ''));
            if ($text === '') {
                continue;
            }
            $segments[] = ['cluster_index' => $clusterIndex, 'text' => $text, 'is_outro' => false];
        }

        $outro = trim((string) ($outro ?? ''));
        if ($outro !== '') {
            $segments[] = ['cluster_index' => null, 'text' => $outro, 'is_outro' => true];
        }

        return $segments;
    }

    /**
     * Group the ordered script segments into scene-sized batches (~5-10 min of narration
     * each), splitting on paragraph boundaries so no single scene is wildly oversized,
     * while keeping the original text verbatim (this pipeline never re-summarizes text
     * that Bước 3 already finalized).
     *
     * @param array<int,array{cluster_index:int|null,text:string,is_outro:bool}> $segments
     * @return array<int,array{cluster_index:int|null,text:string,is_outro:bool}>
     */
    public function buildSceneBatches(array $segments): array
    {
        $batches = [];
        $currentText = '';
        $currentClusterIndex = null;
        $currentIsOutro = false;

        foreach ($segments as $segment) {
            $paragraphs = preg_split('/\r?\n+/u', $segment['text']) ?: [$segment['text']];

            foreach ($paragraphs as $paragraph) {
                $paragraph = trim($paragraph);
                if ($paragraph === '') {
                    continue;
                }

                if ($currentText !== '' && mb_strlen($currentText . "\n\n" . $paragraph) > self::MAX_SCENE_CHARS) {
                    $batches[] = ['cluster_index' => $currentClusterIndex, 'text' => trim($currentText), 'is_outro' => $currentIsOutro];
                    $currentText = '';
                    $currentClusterIndex = null;
                    $currentIsOutro = false;
                }

                if ($currentText === '') {
                    $currentClusterIndex = $segment['cluster_index'];
                    $currentIsOutro = $segment['is_outro'];
                }

                $currentText .= ($currentText === '' ? '' : "\n\n") . $paragraph;
            }
        }

        if (trim($currentText) !== '') {
            $batches[] = ['cluster_index' => $currentClusterIndex, 'text' => trim($currentText), 'is_outro' => $currentIsOutro];
        }

        return $batches;
    }

    /**
     * Split a scene's narration into individual sentences (kept 100% verbatim — this
     * pipeline never re-summarizes text that Bước 3 already finalized).
     *
     * @return array<int,string>
     */
    public function splitIntoSentences(string $text): array
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $text));
        if ($normalized === '') {
            return [];
        }

        $parts = preg_split('/(?<=[.!?…])\s+/u', $normalized) ?: [$normalized];
        $parts = array_values(array_filter(array_map('trim', $parts), fn($s) => $s !== ''));

        // Vietnamese narration often has long compound sentences (lists joined by ";"/":")
        // that end in a single "." — those must be broken down further, otherwise one
        // "sentence" alone can blow past a shot's max length (a real bug seen in practice:
        // a 5-item ";"-separated list became one 24s shot against a 10s cap).
        $result = [];
        foreach ($parts as $part) {
            foreach ($this->splitOversizedClause($part) as $piece) {
                $result[] = $piece;
            }
        }

        return $result;
    }

    /**
     * Break an overly long clause down further on progressively weaker punctuation
     * (semicolon/colon, then comma, then a hard word-boundary cut) so no single narration
     * chunk ever exceeds a shot's max length.
     *
     * @return array<int,string>
     */
    private function splitOversizedClause(string $sentence): array
    {
        if (mb_strlen($sentence) <= self::SHOT_MAX_CHARS) {
            return [$sentence];
        }

        foreach (['/(?<=[;:])\s+/u', '/(?<=,)\s+/u'] as $pattern) {
            $parts = preg_split($pattern, $sentence) ?: [$sentence];
            if (count($parts) > 1) {
                $result = [];
                foreach ($parts as $part) {
                    $part = trim($part);
                    if ($part === '') {
                        continue;
                    }
                    $result = array_merge($result, $this->splitOversizedClause($part));
                }
                return $result;
            }
        }

        // Last resort: no punctuation to split on — hard-cut at word boundaries.
        $words = preg_split('/\s+/u', $sentence) ?: [$sentence];
        $chunks = [];
        $current = '';
        foreach ($words as $word) {
            if ($current !== '' && mb_strlen($current . ' ' . $word) > self::SHOT_MAX_CHARS) {
                $chunks[] = trim($current);
                $current = '';
            }
            $current .= ($current === '' ? '' : ' ') . $word;
        }
        if (trim($current) !== '') {
            $chunks[] = trim($current);
        }

        return $chunks;
    }

    /**
     * Group sentences into shot-sized chunks (~15-20s of narration each — a shot is the
     * unit that gets exactly ONE resolved clip/image, so it must match real b-roll length,
     * not a whole 5-10 min scene).
     *
     * @param array<int,string> $sentences
     * @return array<int,string>
     */
    public function groupSentencesIntoShots(array $sentences): array
    {
        $shots = [];
        $current = '';

        foreach ($sentences as $sentence) {
            if ($current !== '' && mb_strlen($current . ' ' . $sentence) > self::SHOT_MAX_CHARS) {
                $shots[] = trim($current);
                $current = '';
            }

            $current .= ($current === '' ? '' : ' ') . $sentence;

            if (mb_strlen($current) >= self::SHOT_TARGET_CHARS) {
                $shots[] = trim($current);
                $current = '';
            }
        }

        if (trim($current) !== '') {
            $shots[] = trim($current);
        }

        return $shots;
    }

    /**
     * Determine the book's cultural/historical/geographic setting ONCE per pipeline run,
     * so every scene's keyword generation stays consistent (prevents e.g. a Chinese classic
     * like Tôn Tử Binh Pháp from pulling Roman/European "ancient soldier" stock footage).
     */
    public function determineContext(AudioBook $audioBook): string
    {
        $lines = ['Tiêu đề: "' . $audioBook->title . '"'];
        if ($audioBook->author) {
            $lines[] = 'Tác giả: ' . $audioBook->author;
        }
        if ($audioBook->category) {
            $lines[] = 'Thể loại: ' . $audioBook->category;
        }
        if ($audioBook->description) {
            $lines[] = 'Mô tả: ' . mb_substr((string) $audioBook->description, 0, 800);
        }

        $prompt = "Dựa trên thông tin sách dưới đây, hãy xác định NGẮN GỌN bối cảnh văn hóa/lịch sử/địa lý của nội dung sách, để dùng làm ràng buộc khi tìm ảnh/video minh họa.\n"
            . "Trả về STRICT JSON: {\"context\": \"...\"}\n"
            . "Yêu cầu cho trường context (viết bằng tiếng Việt, 2-4 câu):\n"
            . "- Nêu rõ nền văn hóa/dân tộc (vd: Trung Hoa cổ đại, La Mã cổ đại, Việt Nam thời phong kiến, châu Âu trung cổ, hiện đại phương Tây...).\n"
            . "- Nêu rõ giai đoạn/thời đại lịch sử nếu xác định được.\n"
            . "- Nếu sách không có bối cảnh lịch sử/văn hóa cụ thể (vd sách kỹ năng hiện đại, khoa học...), ghi rõ \"bối cảnh hiện đại, không giới hạn văn hóa cụ thể\".\n"
            . "- Ghi thêm 1 câu cảnh báo tránh nhầm lẫn sang nền văn hóa khác dễ gây sai khi tìm ảnh (nếu có).\n"
            . "Không thêm giải thích, không dùng markdown.\n\n"
            . "THÔNG TIN SÁCH:\n" . implode("\n", $lines);

        try {
            $text = $this->callGemini($prompt, ['temperature' => 0.2, 'json' => true, 'max_output_tokens' => 4096]);
            $decoded = $this->decodeJsonResponse($text);
            return trim((string) ($decoded['context'] ?? ''));
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Stage A of the split pipeline: classify ONE scene batch — title/scene_type/climax
     * flag ONLY, no shot enumeration at all. Deliberately tiny structured output so
     * reasoning_effort=minimal + JSON Schema keep this fast and cheap regardless of how
     * many shots the scene will eventually contain (that's Stage B's problem, chunked
     * separately in enrichShotsChunk()).
     *
     * @return array{title:string,scene_type:string,is_emotional_climax:bool}
     */
    public function analyzeSceneMeta(string $sceneText, AudioBook $audioBook): array
    {
        $bookLine = 'Sách: "' . $audioBook->title . '"' . ($audioBook->author ? ' - Tác giả: ' . $audioBook->author : '');
        $typesList = implode(', ', self::SCENE_TYPES);

        $prompt = "Bạn là đạo diễn hình ảnh cho video kể chuyện. Dưới đây là kịch bản narration (lời đọc) của MỘT CẢNH (scene) trong video.\n"
            . $bookLine . "\n\n"
            . "Phân tích TỔNG QUAN cả cảnh: tiêu đề, loại cảnh, có phải cao trào cảm xúc không.\n"
            . "- title: tiêu đề ngắn gọn tiếng Việt (dưới 12 từ) mô tả cảnh này.\n"
            . "- scene_type: CHỌN ĐÚNG 1 giá trị trong danh sách: {$typesList}. Ý nghĩa: nature=thiên nhiên/phong cảnh, city=thành phố/kiến trúc/nội thất hiện đại hoặc cổ, history=bối cảnh/sự kiện lịch sử, character=cận cảnh nhân vật/hành động con người, map=địa lý/bản đồ/di chuyển địa điểm, philosophy=suy ngẫm/triết lý/khái niệm trừu tượng.\n"
            . "- is_emotional_climax: true nếu đây là cao trào cảm xúc mạnh (mất mát, phản bội, chiến thắng nghẹt thở, khoảnh khắc xúc động...) KHÔNG nên bị cắt ngang bởi cảnh người dẫn chương trình xuất hiện; false nếu là đoạn trần thuật/giải thích/chuyển cảnh bình thường.\n\n"
            . "NỘI DUNG CẢNH:\n" . $sceneText;

        $schema = [
            'name' => 'scene_meta',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string'],
                    'scene_type' => ['type' => 'string', 'enum' => self::SCENE_TYPES],
                    'is_emotional_climax' => ['type' => 'boolean'],
                ],
                'required' => ['title', 'scene_type', 'is_emotional_climax'],
                'additionalProperties' => false,
            ],
        ];

        $decoded = $this->openAiService->completeJson($prompt, [
            'reasoning_effort' => 'minimal',
            'json_schema' => $schema,
            'max_tokens' => 4000,
            'purpose' => 'video_scene_split',
        ]);

        $sceneType = trim((string) ($decoded['scene_type'] ?? ''));
        if (!in_array($sceneType, self::SCENE_TYPES, true)) {
            $sceneType = 'city';
        }

        return [
            'title' => trim((string) ($decoded['title'] ?? 'Cảnh')) ?: 'Cảnh',
            'scene_type' => $sceneType,
            'is_emotional_climax' => (bool) ($decoded['is_emotional_climax'] ?? false),
        ];
    }

    /**
     * Compact, claim-metadata-free roster of everything in an active Story Bible — fed to
     * assignSceneContext() as the FIXED list a scene's timeline/location/characters must be
     * chosen from (never invented). Confidence/evidence/rationale are deliberately stripped
     * here — that provenance belongs to the bible's own claims, not to this classification
     * step.
     *
     * @return array{timelines:array,locations:array,characters:array}
     */
    public function buildCanonicalRosterSummary(AudiobookStoryBible $bible): array
    {
        $timelines = $bible->timelines()->orderBy('chronological_order')->get()->map(fn($t) => [
            'label' => $t->label,
            'story_time_marker' => data_get($t->profile, 'value.story_time_marker'),
        ])->all();

        $locations = $bible->locations()->get()->map(fn($l) => [
            'name' => $l->canonical_name,
            'aliases' => $l->aliases ?? [],
            'summary' => data_get($l->cultural_context, 'region.value') ?: data_get($l->cultural_context, 'historical_polity.value'),
        ])->all();

        $characters = $bible->characters()->with('phases')->get()->map(fn($c) => [
            'name' => $c->canonical_name,
            'role' => data_get($c->role, 'value'),
            'phases' => $c->phases->map(fn($p) => [
                'label' => $p->label,
                'story_time_marker' => data_get($p->profile, 'value.story_time_marker'),
            ])->all(),
        ])->all();

        return ['timelines' => $timelines, 'locations' => $locations, 'characters' => $characters];
    }

    /**
     * Determines which timeline/location/characters(+phases) apply to ONE scene, chosen
     * strictly from the bible's existing roster (a classification task, never entity
     * invention) based on the scene's OWN text — this is what lets a non-linear book assign
     * a character's phase by story content instead of scene/shot order.
     *
     * @param array<string,mixed> $logContext
     * @return array{timeline:array,location:array,story_phase:?string,characters_present:array}
     */
    public function assignSceneContext(AudiobookVideoScene $scene, AudiobookStoryBible $bible, array $logContext = []): array
    {
        $roster = $this->buildCanonicalRosterSummary($bible);

        $timelinesText = collect($roster['timelines'])
            ->map(fn($t) => '- ' . $t['label'] . ($t['story_time_marker'] ? " ({$t['story_time_marker']})" : ''))
            ->implode("\n");
        $locationsText = collect($roster['locations'])
            ->map(fn($l) => '- ' . $l['name'] . (!empty($l['aliases']) ? ' (aka ' . implode(', ', $l['aliases']) . ')' : '') . ($l['summary'] ? ": {$l['summary']}" : ''))
            ->implode("\n");
        $charactersText = collect($roster['characters'])
            ->map(function ($c) {
                $phases = collect($c['phases'])->map(fn($p) => $p['label'] . ($p['story_time_marker'] ? " ({$p['story_time_marker']})" : ''))->implode('; ');
                return '- ' . $c['name'] . ($c['role'] ? " ({$c['role']})" : '') . ($phases !== '' ? " | phases: {$phases}" : ' | không có phase (không đổi)');
            })
            ->implode("\n");

        $prompt = "Bạn đang xác định BỐI CẢNH của MỘT CẢNH (scene) trong video, dựa TRÊN danh sách CỐ ĐỊNH các timeline/địa "
            . "điểm/nhân vật đã có sẵn của cả tác phẩm bên dưới — CHỈ được chọn trong danh sách này, TUYỆT ĐỐI KHÔNG tự "
            . "tạo mới bất kỳ timeline/địa điểm/nhân vật nào không có trong danh sách.\n\n"
            . "TIMELINES:\n{$timelinesText}\n\nLOCATIONS:\n{$locationsText}\n\nCHARACTERS:\n{$charactersText}\n\n"
            . "NỘI DUNG CẢNH CẦN XÁC ĐỊNH:\n{$scene->script_text}\n\n"
            . "Yêu cầu:\n"
            . "- timeline/location: chọn ĐÚNG MỘT mục trong danh sách khớp với NỘI DUNG cảnh này (dựa vào nội dung, không "
            . "dựa vào thứ tự cảnh trong sách). Nếu không đủ căn cứ, name=null, confidence=\"unknown\". QUAN TRỌNG: "
            . "timeline.name/location.name PHẢI là bản sao NGUYÊN VĂN, CHÍNH XÁC phần tên đứng trước dấu ngoặc đơn trong "
            . "danh sách (không kèm phần mô tả thời gian/alias trong ngoặc đơn phía sau tên).\n"
            . "- location.relevant_cultural_groups: nếu địa điểm được chọn có nhiều nhóm văn hóa, CHỈ liệt kê nhóm THỰC SỰ "
            . "xuất hiện/liên quan trong nội dung cảnh này (không liệt kê hết mọi nhóm từng có mặt tại địa điểm đó).\n"
            . "- story_phase: một nhãn ngắn mô tả giai đoạn câu chuyện tại cảnh này, null nếu không xác định được.\n"
            . "- characters_present: CHỈ liệt kê nhân vật trong danh sách THỰC SỰ xuất hiện/được nhắc trong nội dung cảnh "
            . "này. Với mỗi nhân vật, chọn ĐÚNG MỘT phase_label trong danh sách phase của nhân vật đó KHỚP với thời điểm "
            . "câu chuyện tại cảnh này (dựa vào NỘI DUNG cảnh, không phải thứ tự cảnh) — nếu nhân vật không có phase nào, "
            . "hoặc không đủ căn cứ để chọn phase, để phase_label=null (mặc định dùng baseline traits).\n"
            . "- evidence.quote: trích dẫn ngắn NGUYÊN VĂN từ CHÍNH nội dung cảnh này làm bằng chứng.\n";

        return $this->openAiService->completeJson($prompt, [
            'reasoning_effort' => 'low',
            'json_schema' => $this->sceneContextSchema(),
            'max_tokens' => 3000,
            'purpose' => 'scene_direction_assign',
            'retry' => false,
            'log_context' => $logContext,
        ]);
    }

    /**
     * Resolves assignSceneContext()'s name-based output to real IDs (case-insensitive,
     * canonical-name-or-alias match against the SAME bible entities the roster was built
     * from) and persists the scene's bindings + its scene-character pivot rows. A name that
     * doesn't match anything is never guessed — the binding is marked unresolved instead.
     */
    public function persistSceneContext(AudiobookVideoScene $scene, AudiobookStoryBible $bible, array $assignment): void
    {
        $timelines = $bible->timelines;
        $locations = $bible->locations;
        $characters = $bible->characters()->with('phases')->get();

        $timelineName = data_get($assignment, 'timeline.name');
        $timelineConfidence = data_get($assignment, 'timeline.confidence', 'unknown');
        $timelineEvidence = (array) data_get($assignment, 'timeline.evidence', []);
        ['match' => $timelineMatch, 'reason' => $timelineReason] = $this->resolveByNameOrAliasWithReason($timelineName, $timelines, fn($t) => $t->label, fn($t) => $t->aliases ?? []);
        $timelineBinding = [
            'timeline_id' => $timelineMatch?->id,
            'raw_name' => $timelineName,
            'confidence' => $timelineConfidence,
            'source_type' => data_get($assignment, 'timeline.source_type', 'unknown'),
            'evidence' => $timelineEvidence,
            'status' => ($timelineName === null || trim((string) $timelineName) === '') ? 'not_applicable' : ($timelineMatch ? 'resolved' : 'unresolved'),
            // Deterministic data-quality flag (Phase 4's runDeterministicChecks reads this
            // directly) — a non-unknown confidence with no supporting evidence is itself a
            // finding, independent of whether the name resolved.
            'unresolved_reason' => $timelineMatch
                ? (($timelineConfidence !== 'unknown' && empty($timelineEvidence)) ? AudiobookContinuityIssue::REASON_NO_EVIDENCE : null)
                : $timelineReason,
        ];

        $locationName = data_get($assignment, 'location.name');
        $locationConfidence = data_get($assignment, 'location.confidence', 'unknown');
        $locationEvidence = (array) data_get($assignment, 'location.evidence', []);
        ['match' => $locationMatch, 'reason' => $locationReason] = $this->resolveByNameOrAliasWithReason($locationName, $locations, fn($l) => $l->canonical_name, fn($l) => $l->aliases ?? []);
        $locationBinding = [
            'location_id' => $locationMatch?->id,
            'raw_name' => $locationName,
            'confidence' => $locationConfidence,
            'source_type' => data_get($assignment, 'location.source_type', 'unknown'),
            'evidence' => $locationEvidence,
            'status' => ($locationName === null || trim((string) $locationName) === '') ? 'not_applicable' : ($locationMatch ? 'resolved' : 'unresolved'),
            'relevant_cultural_groups' => data_get($assignment, 'location.relevant_cultural_groups', []),
            'unresolved_reason' => $locationMatch
                ? (($locationConfidence !== 'unknown' && empty($locationEvidence)) ? AudiobookContinuityIssue::REASON_NO_EVIDENCE : null)
                : $locationReason,
        ];

        $scene->update([
            'story_bible_id' => $bible->id,
            'story_bible_version_used' => $bible->bible_version,
            'scene_direction_version' => self::SCENE_DIRECTION_VERSION,
            'timeline_binding' => $timelineBinding,
            'location_binding' => $locationBinding,
            'story_phase' => data_get($assignment, 'story_phase'),
        ]);

        // Fresh assignment each time this runs — matches SplitVideoScenesJob's "fresh run
        // clears previous data" convention rather than trying to diff/merge old bindings.
        AudiobookVideoSceneCharacter::where('video_scene_id', $scene->id)->delete();

        foreach ((array) data_get($assignment, 'characters_present', []) as $entry) {
            $character = $this->resolveByNameOrAlias($entry['name'] ?? null, $characters, fn($c) => $c->canonical_name, fn($c) => $c->aliases ?? []);
            if (!$character) {
                Log::warning('VideoSceneAnalysisService: scene assignment referenced an unknown character, skipping', [
                    'scene_id' => $scene->id,
                    'name' => $entry['name'] ?? null,
                ]);
                continue;
            }

            $phaseLabel = $entry['phase_label'] ?? null;
            $phase = $phaseLabel
                ? $character->phases->first(fn($p) => mb_strtolower(trim($p->label)) === mb_strtolower(trim((string) $phaseLabel)))
                : null;

            $resolutionStatus = 'baseline_fallback';
            if ($phase) {
                $resolutionStatus = 'resolved';
            } elseif ($phaseLabel && $character->phases->isNotEmpty()) {
                $resolutionStatus = 'unresolved_phase'; // AI named a phase that doesn't match anything — a real mismatch
            }

            AudiobookVideoSceneCharacter::create([
                'video_scene_id' => $scene->id,
                'character_id' => $character->id,
                'character_phase_id' => $phase?->id,
                'confidence' => $entry['confidence'] ?? 'unknown',
                'source_type' => $entry['source_type'] ?? 'unknown',
                'evidence' => $entry['evidence'] ?? [],
                'resolution_status' => $resolutionStatus,
            ]);
        }
    }

    /**
     * Case-insensitive canonical-name-or-alias lookup — the AI references entities by name,
     * never by database ID, so every name-based cross-reference in this pipeline resolves
     * the same way.
     *
     * @param iterable<mixed> $candidates
     */
    private function resolveByNameOrAlias(?string $name, iterable $candidates, callable $nameGetter, callable $aliasesGetter)
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }
        $needles = $this->candidateNeedles($name);

        foreach ($candidates as $candidate) {
            $names = array_merge([$nameGetter($candidate)], (array) $aliasesGetter($candidate));
            foreach ($names as $n) {
                if (in_array(mb_strtolower(trim((string) $n)), $needles, true)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Roster prompts (assignSceneContext) display each timeline/location's name WITH extra
     * context appended in parens for the model's own reading (a story_time_marker, an alias
     * hint) — observed live: the model sometimes echoes that whole decorated line back as
     * the "name" instead of just the bare canonical form, e.g. returning "Main pilgrimage
     * timeline (7th century CE; ...)" when the bible's actual label is just "Main pilgrimage
     * timeline". Rather than trust prompt wording alone to prevent this (unreliable, per
     * other cases this session), also try matching after stripping ONE trailing "(...)"
     * group — deterministic normalization, not fuzzy/approximate matching, so it doesn't
     * weaken the "never guess" resolution guarantee.
     *
     * @return array<int,string>
     */
    private function candidateNeedles(string $name): array
    {
        $needles = [mb_strtolower($name)];
        $stripped = trim((string) preg_replace('/\s*\([^()]*\)\s*$/u', '', $name));
        if ($stripped !== '' && mb_strtolower($stripped) !== $needles[0]) {
            $needles[] = mb_strtolower($stripped);
        }
        return $needles;
    }

    /**
     * Same case-insensitive canonical-name-or-alias lookup as resolveByNameOrAlias(), but
     * also classifies WHY a lookup failed (used for timeline/location bindings, where Phase
     * 4's continuity validator needs to distinguish "nothing like this exists" from "two
     * things matched" from "something close exists but isn't registered as an alias") —
     * this is itself a deterministic, non-AI classification since it's just introspecting
     * the candidate list.
     *
     * @param iterable<mixed> $candidates
     * @return array{match:mixed,reason:?string}
     */
    private function resolveByNameOrAliasWithReason(?string $name, iterable $candidates, callable $nameGetter, callable $aliasesGetter): array
    {
        $name = trim((string) $name);
        if ($name === '') {
            return ['match' => null, 'reason' => null]; // not_applicable — no name was given at all, not a resolution failure
        }
        $needles = $this->candidateNeedles($name);

        $exactMatches = [];
        foreach ($candidates as $candidate) {
            $names = array_merge([$nameGetter($candidate)], (array) $aliasesGetter($candidate));
            foreach ($names as $n) {
                if (in_array(mb_strtolower(trim((string) $n)), $needles, true)) {
                    $exactMatches[] = $candidate;
                    break;
                }
            }
        }

        if (count($exactMatches) === 1) {
            return ['match' => $exactMatches[0], 'reason' => null];
        }
        if (count($exactMatches) > 1) {
            return ['match' => null, 'reason' => AudiobookContinuityIssue::REASON_AMBIGUOUS_MATCH];
        }

        // No exact match (even after stripping a possible trailing "(...)" annotation the AI
        // echoed back from the roster display) — a close/fuzzy match implies a registered
        // alias is simply missing, rather than the entity not existing in the bible at all.
        $needle = $needles[0];
        foreach ($candidates as $candidate) {
            $names = array_merge([$nameGetter($candidate)], (array) $aliasesGetter($candidate));
            foreach ($names as $n) {
                $n = mb_strtolower(trim((string) $n));
                if ($n === '') {
                    continue;
                }
                if (str_contains($n, $needle) || str_contains($needle, $n) || levenshtein($n, $needle) <= 2) {
                    return ['match' => null, 'reason' => AudiobookContinuityIssue::REASON_ALIAS_NOT_FOUND];
                }
            }
        }

        return ['match' => null, 'reason' => AudiobookContinuityIssue::REASON_ENTITY_MISSING];
    }

    /**
     * Assembles the fixed "stable context" block enrichShotsChunk() prepends to its prompt —
     * VALUE ONLY from every claim (never confidence/evidence/rationale, which is audit data
     * for humans/Phase 4, not generation input), and skipping anything the bible marked
     * "unknown" rather than rendering a hollow placeholder.
     *
     * A scene can span many different times/places (e.g. a 79-shot scene covering an entire
     * 17-year journey), so one binding per scene is too coarse — pass $shot to use ITS OWN
     * chunk-level timeline/location/story_phase/characters (set by persistChunkContext())
     * when available, falling back to the scene-wide binding only when the shot has none
     * (legacy data, or a shot never re-enriched since this was added). Passing null keeps the
     * old scene-wide-only behavior, still used where no specific shot is in scope.
     *
     * When the shot is host/narrator address-to-camera (not in-story action), the story's
     * historical/cultural/character context does not apply to it at all — a channel host
     * shot must never be pushed toward "looking historically accurate," so this returns a
     * short neutral block instead of the story context.
     */
    public function buildStableContextBlock(AudiobookVideoScene $scene, ?AudiobookVideoShot $shot = null): string
    {
        if ($shot && $shot->narrative_mode === 'host_narration') {
            return 'Đây là shot NGƯỜI DẪN CHƯƠNG TRÌNH (host) nói trực tiếp với khán giả (giới thiệu/dẫn dắt kênh) — '
                . 'KHÔNG PHẢI cảnh trong câu chuyện. KHÔNG áp dụng bối cảnh lịch sử/văn hóa/nhân vật của tác phẩm cho '
                . 'shot này; mô tả một người dẫn chương trình hiện đại, trang phục/bối cảnh studio hiện đại, trung tính.';
        }

        $lines = [];

        $bible = $scene->storyBible;
        if ($bible) {
            foreach (['visual_style', 'palette', 'lighting', 'camera_language', 'pacing'] as $field) {
                $value = data_get($bible->director_treatment, "{$field}.value");
                if ($value) {
                    $lines[] = ucfirst(str_replace('_', ' ', $field)) . ": {$value}";
                }
            }
        }

        $hasShotTimeline = $shot && data_get($shot->timeline_binding, 'timeline_id');
        $timeline = $hasShotTimeline ? $shot->resolvedTimeline() : $scene->resolvedTimeline();
        if ($timeline) {
            $marker = data_get($timeline->profile, 'value.story_time_marker');
            $desc = data_get($timeline->profile, 'value.description');
            $lines[] = 'Timeline: ' . $timeline->label . ($marker ? " ({$marker})" : '') . ($desc ? " — {$desc}" : '');
        }

        $storyPhase = ($shot && $shot->shot_story_phase) ? $shot->shot_story_phase : $scene->story_phase;
        if ($storyPhase) {
            $lines[] = 'Story phase: ' . $storyPhase;
        }

        $hasShotLocation = $shot && data_get($shot->location_binding, 'location_id');
        $location = $hasShotLocation ? $shot->resolvedLocation() : $scene->resolvedLocation();
        $locationBindingSource = $hasShotLocation ? $shot->location_binding : $scene->location_binding;
        if ($location) {
            $lines[] = 'Location: ' . $location->canonical_name;
            $cc = $location->cultural_context ?? [];
            foreach (['region', 'historical_polity', 'architecture', 'clothing', 'transportation', 'religion', 'material_culture', 'environment'] as $field) {
                $value = data_get($cc, "{$field}.value");
                if ($value) {
                    $lines[] = '  - ' . ucfirst(str_replace('_', ' ', $field)) . ": {$value}";
                }
            }

            $relevantGroups = array_filter((array) data_get($locationBindingSource, 'relevant_cultural_groups', []));
            $allGroups = collect($cc['cultural_groups_present'] ?? [])->map(fn($g) => data_get($g, 'value.name'))->filter()->values()->all();
            $groupsToShow = !empty($relevantGroups) ? $relevantGroups : $allGroups;
            if (!empty($groupsToShow)) {
                $lines[] = '  - Cultural groups present: ' . implode(', ', $groupsToShow);
            }
        }

        // Shot-level character presence (chunk-resolved) takes priority over the scene-wide
        // roster when it exists — this is what lets a character named once and referred to
        // by pronoun in the very next shot (same enrichment chunk) get a consistent
        // identity/wardrobe anchor instead of each shot inventing its own guess.
        $shotCharacters = $shot ? $shot->shotCharacters()->with(['character', 'phase'])->get() : collect();
        $characterRows = $shotCharacters->isNotEmpty() ? $shotCharacters : $scene->sceneCharacters()->with(['character', 'phase'])->get();

        foreach ($characterRows as $sc) {
            $character = $sc->character;
            if (!$character) {
                continue;
            }
            $identity = data_get($character->identity_anchor, 'value', []) ?: [];
            $traits = $sc->phase
                ? (data_get($sc->phase->mutable_traits, 'value', []) ?: [])
                : (data_get($character->baseline_traits, 'value', []) ?: []);

            $descParts = array_values(array_filter([
                $identity['gender'] ?? null,
                $identity['ethnicity_notes'] ?? null,
                $identity['base_face'] ?? null,
                $identity['defining_marks'] ?? null,
                $traits['physique'] ?? null,
                $traits['hairstyle'] ?? null,
                $traits['wardrobe'] ?? null,
                $traits['injuries'] ?? null,
                $traits['emotional_state'] ?? null,
                $traits['social_status'] ?? null,
                $traits['occupation'] ?? null,
            ]));

            $lines[] = 'Character ' . $character->canonical_name
                . ($sc->phase ? " (phase: {$sc->phase->label})" : ' (baseline)')
                . ': ' . implode(', ', $descParts);
        }

        return implode("\n", $lines);
    }

    private function confidenceEnumSchema(): array
    {
        return ['type' => 'string', 'enum' => ['confirmed', 'inferred', 'inferred_low_confidence', 'unknown']];
    }

    private function sourceTypeEnumSchema(): array
    {
        return ['type' => 'string', 'enum' => ['explicit_text', 'inferred_from_text', 'director_choice', 'user_override', 'unknown']];
    }

    private function evidenceArraySchema(): array
    {
        return [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => ['quote' => ['type' => 'string']],
                'required' => ['quote'],
                'additionalProperties' => false,
            ],
        ];
    }

    private function sceneContextSchema(): array
    {
        $confidenceEnum = $this->confidenceEnumSchema();
        $sourceTypeEnum = $this->sourceTypeEnumSchema();
        $evidenceSchema = $this->evidenceArraySchema();

        $bindingSchema = fn(array $extraProps = []) => [
            'type' => 'object',
            'properties' => array_merge([
                'name' => ['type' => ['string', 'null']],
                'confidence' => $confidenceEnum,
                'source_type' => $sourceTypeEnum,
                'evidence' => $evidenceSchema,
            ], $extraProps),
            'required' => array_merge(['name', 'confidence', 'source_type', 'evidence'], array_keys($extraProps)),
            'additionalProperties' => false,
        ];

        return [
            'name' => 'scene_direction_assignment',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'timeline' => $bindingSchema(),
                    'location' => $bindingSchema(['relevant_cultural_groups' => ['type' => 'array', 'items' => ['type' => 'string']]]),
                    'story_phase' => ['type' => ['string', 'null']],
                    'characters_present' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string'],
                                'phase_label' => ['type' => ['string', 'null']],
                                'confidence' => $confidenceEnum,
                                'source_type' => $sourceTypeEnum,
                                'evidence' => $evidenceSchema,
                            ],
                            'required' => ['name', 'phase_label', 'confidence', 'source_type', 'evidence'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['timeline', 'location', 'story_phase', 'characters_present'],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * Stage B of the split pipeline: enrich a SMALL chunk of shots (≤~15, caller decides
     * the exact chunk boundaries) with is_real_world/keywords/image_request. Same analysis
     * rules as the old monolithic analyzeScene(), just scoped to a bounded chunk instead of
     * an entire scene's shot list — this is the actual fix for the request-size-driven
     * timeouts (a real book produced 80 shots in one old-style call).
     *
     * ALSO resolves LOCAL (chunk-scoped) timeline/location/story_phase/characters_present in
     * the SAME call, when $bible is given — a single scene-wide binding is too coarse for a
     * scene spanning many times/places (e.g. a 79-shot scene covering an entire 17-year
     * journey), and this chunk's own ~15-shot window is small enough that a character named
     * once and referred to by pronoun in the very next shot both fall inside the SAME call,
     * where the model can actually connect them (verified live: this is what a whole-SCENE
     * text window sometimes misses for a minor, single-mention character). $previousContext
     * carries a short summary of the immediately preceding chunk's resolved local context —
     * without it, a continuous event split across a chunk boundary (this shot chunk starting
     * mid-event) has the model inventing a brand-new setting/character wardrobe from nothing,
     * since it has zero visibility into what the previous chunk already established.
     *
     * @param array<int,array{index:int,text:string}> $shotChunk global 1-based shot index + text
     * @param string $stableContext output of buildStableContextBlock() for this shot's scene — computed ONCE per
     *   scene by the caller (not per chunk) and passed in as-is; already resolved Story Bible/Character Bible
     *   data (values only), so chunks don't need to re-derive character/setting on their own.
     * @param array{book_id?:int,scene_id?:int,chunk_index?:int,job_attempt?:int} $logContext forwarded to OpenAiService for api_usages/log observability
     * @param ?AudiobookStoryBible $bible active bible to classify chunk_context against — omitted (null) skips chunk_context resolution entirely (legacy/no-bible books)
     * @param string $previousContext short text summary of the immediately preceding chunk's resolved local context, '' if this is the scene's first chunk
     * @return array{shots:array<int,array{index:int,is_real_world:bool,keywords:array<int,string>,image_request:string,is_host_narration:bool}>,chunk_context:?array} shots keyed by global index
     */
    public function enrichShotsChunk(array $shotChunk, AudioBook $audioBook, string $sceneTitle, string $sceneType, string $contextHint = '', array $logContext = [], string $stableContext = '', ?AudiobookStoryBible $bible = null, string $previousContext = ''): array
    {
        $bookLine = 'Sách: "' . $audioBook->title . '"' . ($audioBook->author ? ' - Tác giả: ' . $audioBook->author : '');
        $contextBlock = $contextHint !== ''
            ? "\nBỐI CẢNH VĂN HÓA/LỊCH SỬ/ĐỊA LÝ CỦA SÁCH (bắt buộc tuân thủ):\n{$contextHint}\n"
            : '';
        // Fixed per-scene context resolved from the Story Bible/Character Bible (identity
        // anchor, current phase traits, location/cultural context, director treatment) — a
        // chunk describes only what's SPECIFIC to its own shots, it does not re-derive who a
        // character is or what a location looks like from scratch.
        $stableContextBlock = trim($stableContext) !== ''
            ? "\nBỐI CẢNH CỐ ĐỊNH CỦA CẢNH NÀY (dùng làm CĂN CỨ, không tự mô tả lại nhân vật/bối cảnh khác đi):\n{$stableContext}\n"
            : '';

        $previousContextBlock = trim($previousContext) !== ''
            ? "\nBỐI CẢNH NGAY TRƯỚC ĐÓ (từ nhóm shot liền trước, thuộc cùng cảnh này):\n{$previousContext}\n"
            . "QUAN TRỌNG: nếu shot ĐẦU TIÊN trong DANH SÁCH SHOT dưới đây KHÔNG có từ ngữ chỉ chuyển cảnh/thời gian/địa "
            . "điểm rõ ràng (vd \"sau đó\", \"nhiều năm sau\", \"tại một nơi khác\", \"trở về\", \"ít lâu sau\"), coi TOÀN "
            . "BỘ chunk này là TIẾP NỐI TRỰC TIẾP, KHÔNG NGẮT QUÃNG của bối cảnh ngay trước — chunk_context.timeline/"
            . "location/story_phase/characters_present PHẢI GIỮ NGUYÊN như bối cảnh ngay trước, continues_previous=true, "
            . "KHÔNG tự đổi sang bối cảnh/nhân vật khác dù danh sách shot dưới đây không tự nhắc lại rõ ràng.\n"
            : '';

        $shotListText = collect($shotChunk)
            ->map(fn($s) => '[' . $s['index'] . '] ' . $s['text'])
            ->implode("\n");

        $chunkContextBlock = '';
        if ($bible) {
            $roster = $this->buildCanonicalRosterSummary($bible);
            $timelinesText = collect($roster['timelines'])
                ->map(fn($t) => '- ' . $t['label'] . ($t['story_time_marker'] ? " ({$t['story_time_marker']})" : ''))
                ->implode("\n");
            $locationsText = collect($roster['locations'])
                ->map(fn($l) => '- ' . $l['name'] . (!empty($l['aliases']) ? ' (aka ' . implode(', ', $l['aliases']) . ')' : '') . ($l['summary'] ? ": {$l['summary']}" : ''))
                ->implode("\n");
            $charactersText = collect($roster['characters'])
                ->map(function ($c) {
                    $phases = collect($c['phases'])->map(fn($p) => $p['label'] . ($p['story_time_marker'] ? " ({$p['story_time_marker']})" : ''))->implode('; ');
                    return '- ' . $c['name'] . ($c['role'] ? " ({$c['role']})" : '') . ($phases !== '' ? " | phases: {$phases}" : ' | không có phase (không đổi)');
                })
                ->implode("\n");

            $chunkContextBlock = "\nNGOÀI RA, xác định BỐI CẢNH CỤC BỘ (chunk_context) áp dụng CHỈ CHO NHÓM SHOT NÀY (không phải cả "
                . "cảnh/cả sách), chọn CHÍNH XÁC từ danh sách cố định sau — TUYỆT ĐỐI KHÔNG tự tạo mới:\n"
                . "TIMELINES:\n{$timelinesText}\n\nLOCATIONS:\n{$locationsText}\n\nCHARACTERS:\n{$charactersText}\n\n"
                . $previousContextBlock
                . "- timeline.name/location.name PHẢI là bản sao NGUYÊN VĂN phần tên đứng trước dấu ngoặc đơn trong danh "
                . "sách trên (không kèm mô tả thời gian/alias trong ngoặc đơn). Nếu không đủ căn cứ, name=null, "
                . "confidence=\"unknown\".\n"
                . "- story_phase: nhãn ngắn mô tả giai đoạn câu chuyện tại NHÓM SHOT NÀY cụ thể (không phải cả cảnh).\n"
                . "- characters_present: CHỈ liệt kê nhân vật trong danh sách THỰC SỰ xuất hiện/được nhắc (kể cả bằng ĐẠI "
                . "TỪ như \"gã này\", \"kẻ đó\", \"ông ta\" — nếu đại từ đó rõ ràng chỉ một nhân vật ĐÃ ĐƯỢC NÊU TÊN ở một "
                . "shot khác TRONG CÙNG danh sách shot này, hãy resolve về đúng nhân vật đó, đặt pronoun_only=true và "
                . "confidence tương ứng mức độ chắc chắn — KHÔNG resolve nếu không có tên nào được nêu trong CHÍNH nhóm "
                . "shot này). Với mỗi nhân vật, chọn ĐÚNG MỘT phase_label khớp thời điểm câu chuyện ở nhóm shot này, hoặc "
                . "null nếu không có phase/không đủ căn cứ.\n"
                . "- Nếu một người được nhắc đến (kể cả bằng đại từ) nhưng KHÔNG resolve được về nhân vật nào trong danh "
                . "sách CHARACTERS, TUYỆT ĐỐI KHÔNG tự bịa vai trò/trang phục cụ thể (như quân lính, quan chức, thầy tu) "
                . "cho người đó trong image_request/keywords của shot liên quan — chỉ mô tả trung tính (vd \"a local "
                . "figure\", \"a companion\", \"an unnamed man\") trừ khi CHÍNH câu narration của shot đó tự mô tả rõ "
                . "trang phục/vai trò.\n";
        }

        $prompt = "Bạn là đạo diễn hình ảnh cho video kể chuyện. Dưới đây là một số SHOT (mỗi shot ứng với 1 clip minh họa duy nhất, khoảng 5-10 giây) trích từ cảnh \"{$sceneTitle}\" (loại: {$sceneType}) của video.\n"
            . $bookLine . "\n"
            . $contextBlock
            . $stableContextBlock . "\n"
            . "Với MỖI shot (giữ ĐÚNG số index đã cho, không đổi số):\n"
            . "a) is_real_world: cảnh vật/sự vật được mô tả trong shot đó CÓ THẬT NGOÀI ĐỜI, CÓ THỂ TÌM THẤY được dưới dạng ảnh/video quay thật (stock footage) hay không?\n"
            . "b) keywords: sinh từ khóa tiếng Anh để tìm ảnh/video minh họa CHO ĐÚNG nội dung câu của shot đó.\n"
            . "c) image_request: viết MỘT CÂU MÔ TẢ hình ảnh đầy đủ, chi tiết bằng tiếng Anh (như brief cho một art director/photographer), dùng để tìm kiếm ngữ nghĩa (semantic search) trong thư viện ảnh có sẵn — câu này cần giàu thông tin hơn nhiều so với keywords.\n"
            . "d) is_host_narration: shot này có phải là NGƯỜI DẪN CHƯƠNG TRÌNH/KÊNH nói TRỰC TIẾP với khán giả (chào mừng, giới thiệu sách/kênh, dẫn dắt, kêu gọi theo dõi...) hay không — TRUE nếu đúng vậy, FALSE nếu shot mô tả một cảnh/hành động TRONG câu chuyện của sách.\n"
            . "Yêu cầu:\n"
            . "- is_real_world: PHÉP THỬ BẮT BUỘC trước khi trả lời — hỏi: \"Nếu đưa câu này cho một người quay phim tài liệu, họ có xác định được MỘT cảnh/hành động/địa điểm CỤ THỂ, DUY NHẤT để quay không?\" Chỉ trả TRUE nếu câu MÔ TẢ trực tiếp một cảnh vật/con người/địa điểm/hành động CỤ THỂ có thật, có thể quay được ngoài đời (vd: núi rừng, thành quách, người đi bộ, chiến trường, quân đội hành quân, bản đồ, tài liệu lịch sử...). Trả FALSE trong CẢ HAI trường hợp sau: (1) nội dung HƯ CẤU/siêu nhiên/kỳ ảo (rồng, thần linh, phép thuật, linh hồn); (2) câu là lời BÌNH LUẬN/NHẬN ĐỊNH/KẾT LUẬN/KHÁI QUÁT TRỪU TƯỢNG của người kể chuyện — dù có nhắc tên địa danh/sự kiện/khái niệm lịch sử có thật, câu KHÔNG mô tả một cảnh cụ thể nào đang diễn ra thì vẫn là FALSE. Ví dụ PHẢI là FALSE: \"Những tư tưởng sắc bén trong cuốn sách này không chỉ định hình nghệ thuật chiến tranh thời cổ đại mà còn giữ nguyên giá trị triết lý\" (bình luận về giá trị tư tưởng, không phải cảnh cụ thể); \"liên quan trực tiếp đến sự sống chết của nhân dân và sự tồn vong của đất nước\" (câu khái quát hậu quả trừu tượng, không mô tả cảnh cụ thể nào).\n"
            . "- keywords: 3-5 từ khóa tiếng Anh NGẮN GỌN (1-3 từ/keyword) mô tả hình ảnh trực quan CỤ THỂ cho đúng nội dung của shot đó (ví dụ: \"ancient castle gate\", \"stormy ocean waves\"). Nếu is_real_world=false do câu TRỪU TƯỢNG/bình luận/khái quát (không phải hư cấu), KHÔNG suy diễn ra một cảnh lịch sử cụ thể không có trong câu — thay vào đó dùng từ khóa MANG TÍNH BIỂU TƯỢNG/ẩn dụ trung tính phù hợp để tạo ảnh AI minh họa khái niệm (vd: \"ancient Chinese scroll close-up\", \"calligraphy brush ink\", \"candlelight old book pages\", \"silhouette contemplating war map\"). Nếu is_real_world=false do hư cấu/siêu nhiên, keywords mô tả hình ảnh kỳ ảo mong muốn để làm prompt tạo ảnh AI.\n"
            . "- BẮT BUỘC: mọi keyword liên quan tới con người/trang phục/kiến trúc/vũ khí PHẢI ghi rõ đúng nền văn hóa/dân tộc/thời đại theo bối cảnh sách ở trên (vd sách bối cảnh Trung Hoa cổ đại thì phải viết \"ancient Chinese warrior\", \"Chinese imperial palace\"... TUYỆT ĐỐI không viết chung chung \"ancient soldier\", \"medieval knight\", \"ancient warrior\" vì kho ảnh stock sẽ trả về sai nền văn hóa.\n"
            . "- Không dùng tên riêng không có trong kho ảnh, không lặp y hệt keyword giữa các shot nếu nội dung shot khác nhau.\n"
            . "- image_request: 1 câu tiếng Anh đầy đủ (15-30 từ), mô tả CỤ THỂ chủ thể, hành động, bối cảnh, nền văn hóa/thời đại, không khí/ánh sáng — vd \"A formation of ancient Chinese infantry soldiers in lacquered leather armor marching through a misty mountain pass at dawn, spears raised, banners flowing\". Áp dụng CÙNG quy tắc văn hóa/thời đại như keywords. Nếu is_real_world=false, mô tả hình ảnh biểu tượng/ẩn dụ hoặc kỳ ảo tương ứng.\n"
            . "- Nếu is_host_narration=true, image_request/keywords mô tả một người dẫn chương trình hiện đại (studio/hiện đại), KHÔNG áp dụng bối cảnh lịch sử/văn hóa của sách cho shot đó.\n"
            . "- BẮT BUỘC trả về đủ " . count($shotChunk) . " shot, đúng số index đã cho, không bỏ sót.\n"
            . $chunkContextBlock . "\n"
            . "DANH SÁCH SHOT:\n" . $shotListText;

        $schema = $this->chunkEnrichmentSchema($bible !== null);

        $decoded = $this->openAiService->completeJson($prompt, [
            'reasoning_effort' => 'low',
            'json_schema' => $schema,
            'max_tokens' => 8000,
            'purpose' => 'video_shot_enrich',
            // EnrichVideoShotsJob owns retry/backoff for this call (5s/15s/45s across job
            // attempts) — disabling the client-level retry here keeps each job attempt to
            // exactly one HTTP request instead of nesting two retry mechanisms.
            'retry' => false,
            'log_context' => $logContext,
        ]);

        $result = [];
        foreach ((is_array($decoded['shots'] ?? null) ? $decoded['shots'] : []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $idx = (int) ($row['index'] ?? 0);
            if ($idx < 1) {
                continue;
            }
            $kw = is_array($row['keywords'] ?? null) ? $row['keywords'] : [];
            $kw = array_values(array_filter(array_map(fn($k) => trim((string) $k), $kw), fn($k) => $k !== ''));

            $result[$idx] = [
                'index' => $idx,
                'is_real_world' => array_key_exists('is_real_world', $row) ? (bool) $row['is_real_world'] : true,
                'keywords' => !empty($kw) ? array_slice($kw, 0, 5) : [$sceneType],
                'image_request' => trim((string) ($row['image_request'] ?? '')) ?: implode(', ', $kw),
                'is_host_narration' => (bool) ($row['is_host_narration'] ?? false),
            ];
        }

        return ['shots' => $result, 'chunk_context' => $bible ? ($decoded['chunk_context'] ?? null) : null];
    }

    /**
     * @return array<string,mixed>
     */
    private function chunkEnrichmentSchema(bool $includeChunkContext): array
    {
        $shotItemSchema = [
            'type' => 'object',
            'properties' => [
                'index' => ['type' => 'integer'],
                'is_real_world' => ['type' => 'boolean'],
                'keywords' => ['type' => 'array', 'items' => ['type' => 'string']],
                'image_request' => ['type' => 'string'],
                'is_host_narration' => ['type' => 'boolean'],
            ],
            'required' => ['index', 'is_real_world', 'keywords', 'image_request', 'is_host_narration'],
            'additionalProperties' => false,
        ];

        $properties = ['shots' => ['type' => 'array', 'items' => $shotItemSchema]];
        $required = ['shots'];

        if ($includeChunkContext) {
            $confidenceEnum = $this->confidenceEnumSchema();
            $sourceTypeEnum = $this->sourceTypeEnumSchema();
            $evidenceSchema = $this->evidenceArraySchema();

            $bindingSchema = fn(array $extraProps = []) => [
                'type' => 'object',
                'properties' => array_merge([
                    'name' => ['type' => ['string', 'null']],
                    'confidence' => $confidenceEnum,
                    'source_type' => $sourceTypeEnum,
                    'evidence' => $evidenceSchema,
                ], $extraProps),
                'required' => array_merge(['name', 'confidence', 'source_type', 'evidence'], array_keys($extraProps)),
                'additionalProperties' => false,
            ];

            $properties['chunk_context'] = [
                'type' => 'object',
                'properties' => [
                    'continues_previous' => ['type' => 'boolean'],
                    'timeline' => $bindingSchema(),
                    'location' => $bindingSchema(['relevant_cultural_groups' => ['type' => 'array', 'items' => ['type' => 'string']]]),
                    'story_phase' => ['type' => ['string', 'null']],
                    'characters_present' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string'],
                                'phase_label' => ['type' => ['string', 'null']],
                                'pronoun_only' => ['type' => 'boolean'],
                                'confidence' => $confidenceEnum,
                                'source_type' => $sourceTypeEnum,
                                'evidence' => $evidenceSchema,
                            ],
                            'required' => ['name', 'phase_label', 'pronoun_only', 'confidence', 'source_type', 'evidence'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['continues_previous', 'timeline', 'location', 'story_phase', 'characters_present'],
                'additionalProperties' => false,
            ];
            $required[] = 'chunk_context';
        }

        return [
            'name' => 'shot_chunk_enrichment',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'properties' => $properties,
                'required' => $required,
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * Resolves enrichShotsChunk()'s chunk_context (name-based, unresolved) to real IDs and
     * persists it onto EVERY shot in this chunk — mirrors persistSceneContext()'s resolution
     * logic exactly, just scoped to a shot list instead of a whole scene, and writing to
     * AudiobookVideoShotCharacter instead of AudiobookVideoSceneCharacter. A shot chunk with
     * no bible or no chunk_context is left untouched (falls back to the scene-level binding
     * at consumption time, per buildStableContextBlock()).
     *
     * @param array<int,\App\Models\AudiobookVideoShot> $shots the chunk's shot models
     * @param array<string,mixed> $chunkContext raw (unresolved) chunk_context from enrichShotsChunk()
     */
    public function persistChunkContext(array $shots, AudiobookStoryBible $bible, ?array $chunkContext, array $shotEnrichment): void
    {
        foreach ($shots as $shot) {
            $shot->update(['narrative_mode' => ($shotEnrichment[$shot->shot_index]['is_host_narration'] ?? false) ? 'host_narration' : 'story']);
        }

        if (!$chunkContext) {
            return;
        }

        $timelines = $bible->timelines;
        $locations = $bible->locations;
        $characters = $bible->characters()->with('phases')->get();

        $timelineName = data_get($chunkContext, 'timeline.name');
        $timelineConfidence = data_get($chunkContext, 'timeline.confidence', 'unknown');
        $timelineEvidence = (array) data_get($chunkContext, 'timeline.evidence', []);
        ['match' => $timelineMatch, 'reason' => $timelineReason] = $this->resolveByNameOrAliasWithReason($timelineName, $timelines, fn($t) => $t->label, fn($t) => $t->aliases ?? []);
        $timelineBinding = [
            'timeline_id' => $timelineMatch?->id,
            'raw_name' => $timelineName,
            'confidence' => $timelineConfidence,
            'source_type' => data_get($chunkContext, 'timeline.source_type', 'unknown'),
            'evidence' => $timelineEvidence,
            'status' => ($timelineName === null || trim((string) $timelineName) === '') ? 'not_applicable' : ($timelineMatch ? 'resolved' : 'unresolved'),
            'unresolved_reason' => $timelineMatch
                ? (($timelineConfidence !== 'unknown' && empty($timelineEvidence)) ? AudiobookContinuityIssue::REASON_NO_EVIDENCE : null)
                : $timelineReason,
        ];

        $locationName = data_get($chunkContext, 'location.name');
        $locationConfidence = data_get($chunkContext, 'location.confidence', 'unknown');
        $locationEvidence = (array) data_get($chunkContext, 'location.evidence', []);
        ['match' => $locationMatch, 'reason' => $locationReason] = $this->resolveByNameOrAliasWithReason($locationName, $locations, fn($l) => $l->canonical_name, fn($l) => $l->aliases ?? []);
        $locationBinding = [
            'location_id' => $locationMatch?->id,
            'raw_name' => $locationName,
            'confidence' => $locationConfidence,
            'source_type' => data_get($chunkContext, 'location.source_type', 'unknown'),
            'evidence' => $locationEvidence,
            'status' => ($locationName === null || trim((string) $locationName) === '') ? 'not_applicable' : ($locationMatch ? 'resolved' : 'unresolved'),
            'relevant_cultural_groups' => data_get($chunkContext, 'location.relevant_cultural_groups', []),
            'unresolved_reason' => $locationMatch
                ? (($locationConfidence !== 'unknown' && empty($locationEvidence)) ? AudiobookContinuityIssue::REASON_NO_EVIDENCE : null)
                : $locationReason,
        ];

        $storyPhase = data_get($chunkContext, 'story_phase');

        foreach ($shots as $shot) {
            $shot->update([
                'timeline_binding' => $timelineBinding,
                'location_binding' => $locationBinding,
                'shot_story_phase' => $storyPhase,
            ]);

            AudiobookVideoShotCharacter::where('video_shot_id', $shot->id)->delete();

            foreach ((array) data_get($chunkContext, 'characters_present', []) as $entry) {
                $character = $this->resolveByNameOrAlias($entry['name'] ?? null, $characters, fn($c) => $c->canonical_name, fn($c) => $c->aliases ?? []);
                if (!$character) {
                    Log::warning('VideoSceneAnalysisService: chunk context referenced an unknown character, skipping', [
                        'shot_id' => $shot->id,
                        'name' => $entry['name'] ?? null,
                    ]);
                    continue;
                }

                $phaseLabel = $entry['phase_label'] ?? null;
                $phase = $phaseLabel
                    ? $character->phases->first(fn($p) => mb_strtolower(trim($p->label)) === mb_strtolower(trim((string) $phaseLabel)))
                    : null;

                $resolutionStatus = 'baseline_fallback';
                if (!empty($entry['pronoun_only'])) {
                    $resolutionStatus = 'pronoun_inferred';
                } elseif ($phase) {
                    $resolutionStatus = 'resolved';
                } elseif ($phaseLabel && $character->phases->isNotEmpty()) {
                    $resolutionStatus = 'unresolved_phase';
                }

                AudiobookVideoShotCharacter::create([
                    'video_shot_id' => $shot->id,
                    'character_id' => $character->id,
                    'character_phase_id' => $phase?->id,
                    'confidence' => $entry['confidence'] ?? 'unknown',
                    'source_type' => $entry['source_type'] ?? 'unknown',
                    'evidence' => $entry['evidence'] ?? [],
                    'resolution_status' => $resolutionStatus,
                ]);
            }
        }
    }

    /**
     * Short text summary of a chunk's resolved local context — fed into the NEXT chunk's
     * enrichShotsChunk() call as $previousContext, so a continuous event split across a
     * chunk boundary doesn't lose its established setting/characters.
     */
    public function summarizeChunkContextForCarryOver(AudiobookVideoShot $lastShotOfChunk): string
    {
        if ($lastShotOfChunk->narrative_mode === 'host_narration') {
            return 'Shot ngay trước là host/người dẫn chương trình nói với khán giả (không thuộc bối cảnh câu chuyện).';
        }

        $parts = [];
        $timeline = $lastShotOfChunk->resolvedTimeline();
        if ($timeline) {
            $parts[] = 'Timeline: ' . $timeline->label;
        }
        if ($lastShotOfChunk->shot_story_phase) {
            $parts[] = 'Story phase: ' . $lastShotOfChunk->shot_story_phase;
        }
        $location = $lastShotOfChunk->resolvedLocation();
        if ($location) {
            $parts[] = 'Location: ' . $location->canonical_name;
        }
        $characterNames = $lastShotOfChunk->shotCharacters()->with('character')->get()
            ->map(fn($sc) => $sc->character?->canonical_name)->filter()->values()->implode(', ');
        if ($characterNames !== '') {
            $parts[] = 'Characters: ' . $characterNames;
        }
        if ($lastShotOfChunk->image_request) {
            $parts[] = 'Shot cuối cùng của nhóm trước mô tả: ' . $lastShotOfChunk->image_request;
        }

        return implode('; ', $parts);
    }

    public function estimateDurationSeconds(string $text, int $minSeconds = 60): int
    {
        $chars = mb_strlen($text);
        return max($minSeconds, (int) round($chars / self::NARRATION_CHARS_PER_MINUTE * 60));
    }

    /**
     * Decide which scenes should be real avatar cut-ins instead of illustrated b-roll,
     * following the user's stated ratio: ~9 x 15s (2 min) per 30 min of video, always
     * including the first (intro) and last (summary/outro) scene, never landing on an
     * emotional-climax scene (except that the very first/last slot always wins even if
     * flagged as climax, since intro/outro are structurally required).
     *
     * @param array<int,array{is_emotional_climax:bool}> $scenes zero-indexed, in scene order
     * @return array<int,bool> map of scene array-index => is_avatar_segment
     */
    public function computeAvatarPlacement(array $scenes, int $totalDurationSeconds): array
    {
        $count = count($scenes);
        $flags = array_fill(0, $count, false);

        if ($count === 0) {
            return $flags;
        }

        $totalMinutes = max(1, $totalDurationSeconds / 60);
        $targetSlots = max(2, (int) round($totalMinutes / 30 * 9));
        $targetSlots = min($targetSlots, $count);

        $flags[0] = true;
        $lastIndex = $count - 1;
        $flags[$lastIndex] = true;
        $chosen = ($lastIndex === 0) ? 1 : 2;

        if ($chosen >= $targetSlots || $count <= 2) {
            return $flags;
        }

        $remainingSlots = $targetSlots - $chosen;
        $allowedIndexes = [];
        for ($i = 1; $i < $lastIndex; $i++) {
            if (empty($scenes[$i]['is_emotional_climax'])) {
                $allowedIndexes[] = $i;
            }
        }

        if (empty($allowedIndexes)) {
            return $flags;
        }

        $step = count($allowedIndexes) / ($remainingSlots + 1);
        $picked = [];
        for ($slot = 1; $slot <= $remainingSlots; $slot++) {
            $pos = (int) round($slot * $step) - 1;
            $pos = max(0, min($pos, count($allowedIndexes) - 1));
            $picked[$allowedIndexes[$pos]] = true;
        }

        foreach (array_keys($picked) as $idx) {
            $flags[$idx] = true;
        }

        return $flags;
    }

    /**
     * @param array<string,mixed> $options
     */
    private function callGemini(string $prompt, array $options = []): string
    {
        $apiKey = trim((string) config('services.gemini.api_key', '')) ?: trim((string) config('services.gemini.tts_api_key', ''));
        if ($apiKey === '') {
            throw new \RuntimeException('Thiếu GEMINI_API_KEY / GEMINI_TTS_API_KEY trong cấu hình runtime.');
        }

        $model = $options['model'] ?? self::MODEL;

        $generationConfig = [
            'temperature' => $options['temperature'] ?? 0.3,
            'maxOutputTokens' => $options['max_output_tokens'] ?? 8192,
        ];

        if (!empty($options['json'])) {
            $generationConfig['responseMimeType'] = 'application/json';
        }

        $response = Http::acceptJson()
            ->connectTimeout(15)
            ->timeout($options['timeout'] ?? 90)
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}", [
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => $generationConfig,
            ]);

        if (!$response->successful()) {
            $shortBody = mb_substr(trim((string) $response->body()), 0, 500);
            throw new \RuntimeException('Gemini API lỗi HTTP ' . $response->status() . ($shortBody !== '' ? (': ' . $shortBody) : ''));
        }

        $finishReason = (string) data_get($response->json(), 'candidates.0.finishReason', '');
        $text = trim((string) data_get($response->json(), 'candidates.0.content.parts.0.text', ''));

        if ($finishReason === 'MAX_TOKENS') {
            throw new \RuntimeException('Gemini bị cắt nội dung do vượt giới hạn token đầu ra (finishReason: MAX_TOKENS).');
        }

        if ($text === '') {
            throw new \RuntimeException('Gemini không trả về nội dung.' . ($finishReason ? " (finishReason: {$finishReason})" : ''));
        }

        return $text;
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
            throw new \RuntimeException('Gemini trả về nội dung không phải JSON hợp lệ.');
        }

        return $decoded;
    }
}
