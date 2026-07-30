<?php

namespace App\Services;

use App\Models\AudioBook;
use App\Models\AudiobookCharacter;
use App\Models\AudiobookCharacterPhase;
use App\Models\AudiobookLocation;
use App\Models\AudiobookStoryBible;
use App\Models\AudiobookTimeline;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Builds a Story Bible (genre/tone/timeline structure/historical+cultural context/world
 * rules) and Character Bibles (identity anchor/baseline traits/phases) from an entire
 * audiobook's chapters, via a two-pass map-reduce (never sends a whole book in one LLM
 * call — see AudiobookSummaryService for the established precedent this mirrors):
 *
 *   map    (extractBatchFacts):  one cheap extraction call per ~5000-char chapter batch —
 *                                characters/locations/time-signals/synopsis only, each fact
 *                                tagged with the chapter_id + quote it came from.
 *   reduce (reduceStoryBible):   one call over ALL batches' compact extracted facts (never
 *                                raw chapter text) that produces the full claim-wrapped bible.
 *
 * Every AI-inferred field is wrapped as a "claim":
 *   {value, confidence, source_type, evidence:[{chapter_id,quote}], rationale}
 * confidence ∈ {confirmed, inferred, inferred_low_confidence, unknown} — a claim with any
 * confidence other than "unknown" MUST have non-empty evidence or a non-empty rationale;
 * normalizeClaim() enforces this defensively regardless of what the model returns, since
 * "unknown" + null + [] is always safe but a confidently-stated, unjustified fact is not.
 * text_offset_start/end are NEVER trusted from the model — always computed here via
 * mb_strpos against the real chapter content, since models are unreliable at precise
 * character counting.
 *
 * The prompt/schema content below is deliberately generic: no book title, character name,
 * era, or culture is ever hard-coded — everything book-specific is extracted from that
 * book's own chapters and persisted as data, never as code.
 */
class StoryBibleAnalysisService
{
    public const SCHEMA_VERSION = 'story_bible_v1';

    private const MAX_BATCH_CHARS = 5000;

    public function __construct(private readonly OpenAiService $openAiService) {}

    /**
     * Pure-PHP, chapter-boundary-aware batching (no AI) — mirrors
     * AudiobookSummaryService::buildBatches's shape but is a standalone implementation to
     * keep this service decoupled from the unrelated "Bước 3" summary feature.
     *
     * @param Collection<int,\App\Models\AudioBookChapter> $chapters ordered by chapter_number
     * @return array<int,array{index:int,chapter_ids:array<int,int>,segments:array<int,array{chapter_id:int,text:string}>}>
     */
    public function buildChapterBatches(Collection $chapters): array
    {
        $batches = [];
        $currentSegments = [];
        $currentChars = 0;

        $flush = function () use (&$batches, &$currentSegments, &$currentChars) {
            if (empty($currentSegments)) {
                return;
            }
            $batches[] = [
                'index' => count($batches),
                'chapter_ids' => collect($currentSegments)->pluck('chapter_id')->unique()->values()->all(),
                'segments' => $currentSegments,
            ];
            $currentSegments = [];
            $currentChars = 0;
        };

        foreach ($chapters as $chapter) {
            $paragraphs = preg_split('/\r?\n+/u', (string) $chapter->content) ?: [(string) $chapter->content];

            foreach ($paragraphs as $paragraph) {
                $paragraph = trim($paragraph);
                if ($paragraph === '') {
                    continue;
                }

                if ($currentChars > 0 && $currentChars + mb_strlen($paragraph) > self::MAX_BATCH_CHARS) {
                    $flush();
                }

                $lastIndex = count($currentSegments) - 1;
                if ($lastIndex >= 0 && $currentSegments[$lastIndex]['chapter_id'] === $chapter->id) {
                    $currentSegments[$lastIndex]['text'] .= "\n\n" . $paragraph;
                } else {
                    $currentSegments[] = ['chapter_id' => $chapter->id, 'text' => $paragraph];
                }
                $currentChars += mb_strlen($paragraph);
            }
        }
        $flush();

        return $batches;
    }

    /**
     * Map step: one AI call per batch. Returns the raw decoded facts (chapter_id/quote
     * tagged, offsets NOT yet computed — that happens once, centrally, in normalizeClaim()
     * during the reduce/validation pass).
     *
     * @param array{index:int,chapter_ids:array<int,int>,segments:array<int,array{chapter_id:int,text:string}>} $batch
     * @param array<string,mixed> $logContext
     * @return array{synopsis:string,characters:array,locations:array,time_signals:array}
     */
    public function extractBatchFacts(array $batch, array $logContext = []): array
    {
        $promptText = collect($batch['segments'])
            ->map(fn($s) => "=== CHAPTER {$s['chapter_id']} ===\n{$s['text']}")
            ->implode("\n\n");

        $prompt = "Bạn đang đọc MỘT PHẦN của một tác phẩm (đánh dấu ranh giới chương bằng '=== CHAPTER <id> ==='). "
            . "Nhiệm vụ: CHỈ trích xuất những gì THỰC SỰ có trong đoạn văn này — không suy đoán nội dung ngoài đoạn văn, "
            . "không tự đặt tên/thời kỳ/văn hóa nếu văn bản không nêu rõ, không lặp lại toàn bộ văn bản gốc.\n\n"
            . "- synopsis: tóm tắt 1-2 câu nội dung đoạn này.\n"
            . "- characters: CHỈ những NHÂN VẬT CÁ NHÂN có tên riêng hoặc vai trò cụ thể, xuất hiện trực tiếp trong câu "
            . "chuyện (vd \"Sela\", \"người lính gác cổng thành\"). TUYỆT ĐỐI KHÔNG tạo character cho một NHÓM người chung "
            . "chung (vd \"thương nhân\", \"dân làng\", \"lính triều đình\" nói chung không có cá nhân cụ thể nào được nhắc "
            . "tới) — nếu đoạn văn chỉ nhắc một nhóm người tại một địa điểm, hãy đưa mô tả nhóm đó vào note của location "
            . "tương ứng thay vì tạo character. Mỗi character — name, note (mô tả ngắn), change_signal "
            . "(nếu đoạn này cho thấy nhân vật thay đổi về tuổi/thể chất/thương tích/trang phục/địa vị/cảm xúc/nghề nghiệp thì ghi "
            . "rõ tín hiệu đó, null nếu không có), chapter_id (đúng ID chương chứa quote), quote (trích dẫn NGUYÊN VĂN ngắn làm bằng chứng, null nếu không trích được).\n"
            . "- locations: mỗi địa điểm được nhắc tới — name, note (bao gồm cả các NHÓM người/văn hóa có mặt tại đó nếu có), chapter_id, quote (như trên).\n"
            . "- time_signals: mọi dấu hiệu về thời gian/mốc thời gian (vd nhảy thời gian, hồi tưởng, khoảng cách năm tháng) — quote, note, chapter_id.\n\n"
            . "NỘI DUNG:\n" . $promptText;

        return $this->openAiService->completeJson($prompt, [
            'reasoning_effort' => 'low',
            'json_schema' => $this->batchExtractionSchema(),
            'max_tokens' => 4000,
            'purpose' => 'story_bible_extract',
            'retry' => false,
            'log_context' => $logContext,
        ]);
    }

    /**
     * Reduce step: one call over ALL batches' compact extracted facts (never raw chapter
     * text) that produces the full claim-wrapped bible. Cross-references between entities
     * (a phase's timeline/location) are by NAME/label here — the caller resolves those to
     * real IDs during persistence, since the model never sees database IDs.
     *
     * @param array<int,array{index:int,facts:array|null}> $batchResults
     * @param array<string,mixed> $logContext
     */
    /**
     * Reduce runs as TWO calls, not one — a single call producing world+timelines+locations+
     * characters together was found live, on a real multi-batch book, to make the model
     * reason until the completion budget ran out every single time regardless of
     * reasoning_effort or max_tokens (reasoning_tokens === max_tokens exactly across 4
     * live attempts: 16k/16k, 32k/32k twice more): 'medium'+16000, 'low'+32000,
     * 'minimal'+32000 all failed identically. The schema itself (nested characters × phases,
     * each field claim-wrapped) is what's expensive to satisfy in strict mode for a book with
     * many named characters — splitting world-building (small, fixed-size output) from
     * characters (the part that scales with book length) gives each call a schema it can
     * actually finish within a sane budget. Cross-references (character_phases'
     * timeline_label/current_location_name) are kept valid by feeding step 2 the exact
     * timeline/location names step 1 produced.
     */
    public function reduceStoryBible(array $batchResults, AudioBook $audioBook, array $logContext = []): array
    {
        // World call excludes CHARACTER facts entirely (its schema has no characters key) —
        // less irrelevant input for the model to weigh while satisfying that schema.
        $worldFactsText = $this->buildFactsText($batchResults, includeCharacters: false);
        $fullFactsText = $this->buildFactsText($batchResults, includeCharacters: true);

        $world = $this->reduceWorldAndSetting($worldFactsText, $logContext);
        $characters = $this->reduceCharacters($fullFactsText, $world, $logContext);

        return array_merge($world, ['characters' => $characters['characters'] ?? []]);
    }

    private function buildFactsText(array $batchResults, bool $includeCharacters): string
    {
        return collect($batchResults)
            ->filter(fn($b) => !empty($b['facts']))
            ->map(function ($b) use ($includeCharacters) {
                $f = $b['facts'];
                $lines = ["[Batch {$b['index']}] " . ($f['synopsis'] ?? '')];
                if ($includeCharacters) {
                    foreach (($f['characters'] ?? []) as $c) {
                        $lines[] = "  CHARACTER: {$c['name']} — {$c['note']}"
                            . (!empty($c['change_signal']) ? " | change_signal: {$c['change_signal']}" : '')
                            . " | chapter_id={$this->fmtNullableInt($c['chapter_id'] ?? null)} | quote=" . json_encode($c['quote'] ?? null, JSON_UNESCAPED_UNICODE);
                    }
                }
                foreach (($f['locations'] ?? []) as $l) {
                    $lines[] = "  LOCATION: {$l['name']} — {$l['note']}"
                        . " | chapter_id={$this->fmtNullableInt($l['chapter_id'] ?? null)} | quote=" . json_encode($l['quote'] ?? null, JSON_UNESCAPED_UNICODE);
                }
                foreach (($f['time_signals'] ?? []) as $t) {
                    $lines[] = "  TIME_SIGNAL: {$t['note']}"
                        . " | chapter_id={$this->fmtNullableInt($t['chapter_id'] ?? null)} | quote=" . json_encode($t['quote'] ?? null, JSON_UNESCAPED_UNICODE);
                }
                return implode("\n", $lines);
            })
            ->implode("\n\n");
    }

    private function reduceWorldAndSetting(string $factsText, array $logContext = []): array
    {
        $prompt = "Bạn là biên tập viên phân tích tổng thể một tác phẩm, dựa TRÊN DUY NHẤT các dữ kiện đã được trích xuất "
            . "sẵn dưới đây (không phải toàn văn) từ từng phần của tác phẩm. Nhiệm vụ: tổng hợp genre/tone/timeline "
            . "structure/bối cảnh lịch sử-văn hóa/director treatment, và danh sách các timeline + location xuất hiện "
            . "trong tác phẩm. KHÔNG xử lý nhân vật ở bước này.\n\n"
            . "QUY TẮC BẮT BUỘC:\n"
            . "- Mọi kết luận PHẢI có confidence + (evidence hoặc rationale). Nếu dữ liệu không đủ để kết luận, dùng "
            . "confidence=\"unknown\", value=null, evidence=[], rationale=null — TUYỆT ĐỐI KHÔNG bịa thông tin.\n"
            . "- evidence.quote và evidence.chapter_id PHẢI copy CHÍNH XÁC từ dữ kiện được cung cấp bên dưới, không tự viết quote mới.\n"
            . "- source_type: \"explicit_text\" nếu văn bản nói thẳng, \"inferred_from_text\" nếu suy luận hợp lý từ văn bản, "
            . "\"director_choice\" cho các quyết định sáng tạo (visual_style/palette/lighting/camera_language/pacing) không "
            . "phải sự thật của tác phẩm, \"unknown\" nếu không xác định được.\n"
            . "- KHÔNG dùng nhãn chung chung như \"Eastern\"/\"Western\" nếu dữ kiện cho phép xác định cụ thể hơn (tên vùng/"
            . "nền văn hóa/thời kỳ cụ thể); nếu thực sự không đủ căn cứ để cụ thể hơn thì mới dùng mô tả chung + confidence thấp.\n"
            . "- timeline_structure: chọn đúng linear/non_linear/flashback/time_skip/multi_timeline dựa trên các TIME_SIGNAL "
            . "đã cho — KHÔNG mặc định là linear.\n"
            . "- cultural_groups_present tại một địa điểm có thể liệt kê NHIỀU nhóm văn hóa cùng lúc (local và visiting), "
            . "kể cả các nhóm người chỉ được nhắc chung chung (vd \"thương nhân miền núi\") — KHÔNG tạo character cho các nhóm này.\n"
            . "- Nếu một địa điểm được nhắc dưới nhiều tên khác nhau ở các dữ kiện khác nhau nhưng rõ ràng là CÙNG một nơi, "
            . "hợp nhất thành MỘT entry với các tên khác trong aliases.\n\n"
            . "DỮ KIỆN ĐÃ TRÍCH XUẤT:\n" . $factsText;

        return $this->openAiService->completeJson($prompt, [
            'reasoning_effort' => 'minimal',
            'json_schema' => $this->worldReduceSchema(),
            'max_tokens' => 48000,
            'timeout' => 900,
            'purpose' => 'story_bible_reduce_world',
            'retry' => false,
            'log_context' => $logContext,
        ]);
    }

    private function reduceCharacters(string $factsText, array $world, array $logContext = []): array
    {
        $timelineLabels = collect($world['timelines'] ?? [])->pluck('label')->filter()->values()->implode(', ');
        $locationNames = collect($world['locations'] ?? [])->pluck('name')->filter()->values()->implode(', ');

        $prompt = "Bạn là biên tập viên phân tích NHÂN VẬT của một tác phẩm, dựa TRÊN DUY NHẤT các dữ kiện đã được trích "
            . "xuất sẵn dưới đây (không phải toàn văn). Nhiệm vụ: tổng hợp Character Bible đầy đủ cho từng nhân vật CÁ NHÂN.\n\n"
            . "QUY TẮC BẮT BUỘC:\n"
            . "- Mọi kết luận PHẢI có confidence + (evidence hoặc rationale). Nếu dữ liệu không đủ để kết luận, dùng "
            . "confidence=\"unknown\", value=null, evidence=[], rationale=null — TUYỆT ĐỐI KHÔNG bịa thông tin.\n"
            . "- evidence.quote và evidence.chapter_id PHẢI copy CHÍNH XÁC từ dữ kiện được cung cấp bên dưới, không tự viết quote mới.\n"
            . "- source_type: \"explicit_text\" nếu văn bản nói thẳng, \"inferred_from_text\" nếu suy luận hợp lý từ văn bản, \"unknown\" nếu không xác định được.\n"
            . "- character_phases: CHỈ tạo phase cho một nhân vật khi có change_signal rõ ràng gắn với một mốc thời gian cụ "
            . "thể trong dữ kiện; nhân vật không có change_signal thì character_phases để mảng RỖNG — KHÔNG mặc định mọi "
            . "nhân vật đều già đi hay thay đổi.\n"
            . "- characters CHỈ gồm nhân vật CÁ NHÂN có tên riêng hoặc vai trò cụ thể (vd \"Sela\", \"người lính gác cổng "
            . "thành\"). TUYỆT ĐỐI KHÔNG tạo character cho một NHÓM văn hóa/nghề nghiệp chung chung (vd \"thương nhân miền "
            . "núi\", \"cư dân ven sông\") dù dữ kiện có liệt kê chúng dưới mục CHARACTER.\n"
            . "- current_location_name/timeline_label trong character_phases PHẢI khớp CHÍNH XÁC với một trong các tên sau "
            . "(không tự tạo tên mới): TIMELINE có sẵn: [{$timelineLabels}]. LOCATION có sẵn: [{$locationNames}]. Nếu không "
            . "khớp với cái nào, để null.\n\n"
            . "DỮ KIỆN ĐÃ TRÍCH XUẤT:\n" . $factsText;

        return $this->openAiService->completeJson($prompt, [
            'reasoning_effort' => 'minimal',
            'json_schema' => $this->charactersReduceSchema(),
            // The world call (smaller, fixed-size output) needed 48000 to succeed with room
            // to spare (used ~19000). Characters scales with the book's cast size and hit the
            // ceiling at 32000 on a real book with many named characters — give it the same
            // generous headroom.
            'max_tokens' => 64000,
            'timeout' => 900,
            'purpose' => 'story_bible_reduce_characters',
            'retry' => false,
            'log_context' => $logContext,
        ]);
    }

    private function fmtNullableInt(?int $v): string
    {
        return $v === null ? 'null' : (string) $v;
    }

    /**
     * Normalizes one claim into its final, safe-to-persist shape: fills in missing keys,
     * computes evidence text offsets against the real chapter content, and enforces the
     * "non-unknown needs evidence or rationale" invariant regardless of what the model
     * actually returned.
     *
     * @param array<int,\App\Models\AudioBookChapter> $chaptersById keyed by chapter id
     */
    public function normalizeClaim(?array $claim, array $chaptersById): array
    {
        $value = $claim['value'] ?? null;
        $confidence = $claim['confidence'] ?? 'unknown';
        $sourceType = $claim['source_type'] ?? 'unknown';
        $rationale = $claim['rationale'] ?? null;
        $rationale = (is_string($rationale) && trim($rationale) !== '') ? $rationale : null;

        $evidence = collect($claim['evidence'] ?? [])
            ->map(function ($e) use ($chaptersById) {
                $chapterId = isset($e['chapter_id']) ? (int) $e['chapter_id'] : null;
                $quote = trim((string) ($e['quote'] ?? ''));
                if ($quote === '') {
                    return null;
                }
                [$start, $end] = $this->computeOffsets($chapterId, $quote, $chaptersById);
                return [
                    'chapter_id' => $chapterId,
                    'quote' => $quote,
                    'text_offset_start' => $start,
                    'text_offset_end' => $end,
                ];
            })
            ->filter()
            ->values()
            ->all();

        if (!in_array($confidence, ['confirmed', 'inferred', 'inferred_low_confidence', 'unknown'], true)) {
            $confidence = 'unknown';
        }
        if (!in_array($sourceType, ['explicit_text', 'inferred_from_text', 'director_choice', 'user_override', 'unknown'], true)) {
            $sourceType = 'unknown';
        }

        // The core data-quality invariant: a claim can only assert confidence above
        // "unknown" if it's backed by evidence or a stated rationale. A model that skips
        // this gets defensively downgraded rather than trusted — never persist an
        // unjustified confident claim.
        if ($confidence !== 'unknown' && empty($evidence) && $rationale === null) {
            Log::warning('StoryBibleAnalysisService: claim missing evidence/rationale, downgrading to unknown', [
                'original_confidence' => $confidence,
                'original_source_type' => $sourceType,
            ]);
            return ['value' => null, 'confidence' => 'unknown', 'source_type' => 'unknown', 'evidence' => [], 'rationale' => null];
        }

        if ($confidence === 'unknown') {
            $value = null;
            $evidence = [];
        }

        return ['value' => $value, 'confidence' => $confidence, 'source_type' => $sourceType, 'evidence' => $evidence, 'rationale' => $rationale];
    }

    /**
     * @param array<int,\App\Models\AudioBookChapter> $chaptersById
     * @return array{0:?int,1:?int}
     */
    private function computeOffsets(?int $chapterId, string $quote, array $chaptersById): array
    {
        if ($chapterId === null || $quote === '' || !isset($chaptersById[$chapterId])) {
            return [null, null];
        }

        $content = (string) $chaptersById[$chapterId]->content;
        $pos = mb_strpos($content, $quote);
        if ($pos === false) {
            return [null, null];
        }

        return [$pos, $pos + mb_strlen($quote)];
    }

    /**
     * Walks the full reduce output, normalizing every claim-shaped field. Structure is
     * fixed/known (not dynamic), so this is an explicit field-by-field walk rather than a
     * generic recursive claim-detector.
     *
     * @param array<int,\App\Models\AudioBookChapter> $chaptersById
     */
    public function normalizeReducedBible(array $reduced, array $chaptersById): array
    {
        $textClaimFields = ['genre', 'tone', 'timeline_structure', 'overall_time_span', 'historical_context', 'geography', 'culture'];
        $listClaimFields = ['world_rules', 'forbidden_elements'];

        $sourceFacts = [];
        foreach ($textClaimFields as $f) {
            $sourceFacts[$f] = $this->normalizeClaim($reduced[$f] ?? null, $chaptersById);
        }
        foreach ($listClaimFields as $f) {
            $sourceFacts[$f] = $this->normalizeClaim($reduced[$f] ?? null, $chaptersById);
        }

        $directorTreatment = [];
        foreach (['visual_style', 'palette', 'lighting', 'camera_language', 'pacing'] as $f) {
            $directorTreatment[$f] = $this->normalizeClaim($reduced[$f] ?? null, $chaptersById);
        }

        $timelines = collect($reduced['timelines'] ?? [])->map(function ($t) use ($chaptersById) {
            return [
                'label' => (string) ($t['label'] ?? ''),
                'timeline_type' => in_array($t['timeline_type'] ?? '', ['main', 'flashback', 'parallel', 'frame_story', 'other'], true) ? $t['timeline_type'] : 'other',
                'chronological_order' => (int) ($t['chronological_order'] ?? 0),
                'narrative_introduction_order' => isset($t['narrative_introduction_order']) ? (int) $t['narrative_introduction_order'] : null,
                'profile' => $this->normalizeClaim($t['profile'] ?? null, $chaptersById),
            ];
        })->values()->all();

        $locations = collect($reduced['locations'] ?? [])->map(function ($l) use ($chaptersById) {
            $cc = $l['cultural_context'] ?? [];
            return [
                'name' => (string) ($l['name'] ?? ''),
                'aliases' => array_values(array_filter((array) ($l['aliases'] ?? []))),
                'cultural_context' => [
                    'region' => $this->normalizeClaim($cc['region'] ?? null, $chaptersById),
                    'historical_polity' => $this->normalizeClaim($cc['historical_polity'] ?? null, $chaptersById),
                    'cultural_groups_present' => collect($cc['cultural_groups_present'] ?? [])
                        ->map(fn($g) => $this->normalizeClaim($g, $chaptersById))
                        ->values()->all(),
                    'architecture' => $this->normalizeClaim($cc['architecture'] ?? null, $chaptersById),
                    'clothing' => $this->normalizeClaim($cc['clothing'] ?? null, $chaptersById),
                    'transportation' => $this->normalizeClaim($cc['transportation'] ?? null, $chaptersById),
                    'religion' => $this->normalizeClaim($cc['religion'] ?? null, $chaptersById),
                    'material_culture' => $this->normalizeClaim($cc['material_culture'] ?? null, $chaptersById),
                    'environment' => $this->normalizeClaim($cc['environment'] ?? null, $chaptersById),
                    'anachronism_constraints' => $this->normalizeClaim($cc['anachronism_constraints'] ?? null, $chaptersById),
                ],
                'visual_notes' => $this->normalizeClaim($l['visual_notes'] ?? null, $chaptersById),
            ];
        })->values()->all();

        // Defensive net for a real failure mode observed in live smoke testing: even with an
        // explicit prompt instruction not to, the model sometimes still emits a "character"
        // entry that's actually a generic cultural group already captured properly under a
        // location's cultural_groups_present (e.g. "visiting traders"). Rather than trust
        // prompt wording alone, cross-check every character name against every location's
        // group names and drop the duplicate — the group's data already lives on the
        // location, so nothing is lost by excluding it as a character.
        $groupNames = collect($locations)
            ->flatMap(fn($l) => collect($l['cultural_context']['cultural_groups_present'])->pluck('value.name'))
            ->filter()
            ->map(fn($n) => mb_strtolower(trim((string) $n)))
            ->unique();

        $characters = collect($reduced['characters'] ?? [])
            ->reject(function ($c) use ($groupNames) {
                $isGroupDuplicate = $groupNames->contains(mb_strtolower(trim((string) ($c['name'] ?? ''))));
                if ($isGroupDuplicate) {
                    Log::warning('StoryBibleAnalysisService: dropping character that duplicates a location cultural group', [
                        'name' => $c['name'] ?? null,
                    ]);
                }
                return $isGroupDuplicate;
            })
            ->map(function ($c) use ($chaptersById) {
                return [
                    'name' => (string) ($c['name'] ?? ''),
                    'aliases' => array_values(array_filter((array) ($c['aliases'] ?? []))),
                    'role' => $this->normalizeClaim($c['role'] ?? null, $chaptersById),
                    'cultural_origin' => $this->normalizeClaim($c['cultural_origin'] ?? null, $chaptersById),
                    'identity_anchor' => $this->normalizeClaim($c['identity_anchor'] ?? null, $chaptersById),
                    'baseline_traits' => $this->normalizeClaim($c['baseline_traits'] ?? null, $chaptersById),
                    'character_phases' => collect($c['character_phases'] ?? [])->map(function ($p) use ($chaptersById) {
                        return [
                            'label' => (string) ($p['label'] ?? ''),
                            'timeline_label' => $p['timeline_label'] ?? null,
                            'chronological_order' => (int) ($p['chronological_order'] ?? 0),
                            'current_location_name' => $p['current_location_name'] ?? null,
                            'mutable_traits' => $this->normalizeClaim($p['mutable_traits'] ?? null, $chaptersById),
                            'profile' => $this->normalizeClaim($p['profile'] ?? null, $chaptersById),
                        ];
                    })->values()->all(),
            ];
        })->values()->all();

        return [
            'source_facts' => $sourceFacts,
            'director_treatment' => $directorTreatment,
            'timelines' => $timelines,
            'locations' => $locations,
            'characters' => $characters,
        ];
    }

    /**
     * Persists the normalized/validated bible as child rows scoped to $storyBible->id.
     * Name-based cross-references (timeline_label, current_location_name) are resolved
     * here via case-insensitive canonical-name-or-alias matching — the model never sees or
     * invents database IDs.
     */
    public function persistBibleChildren(AudiobookStoryBible $storyBible, array $validated): void
    {
        $usedKeys = [];
        $timelinesById = [];
        foreach ($validated['timelines'] as $t) {
            $key = $this->uniqueSlug($t['label'], $usedKeys);
            $timeline = AudiobookTimeline::create([
                'story_bible_id' => $storyBible->id,
                'canonical_key' => $key,
                'label' => $t['label'],
                'aliases' => [],
                'timeline_type' => $t['timeline_type'],
                'chronological_order' => $t['chronological_order'],
                'narrative_introduction_order' => $t['narrative_introduction_order'],
                'profile' => $t['profile'],
            ]);
            $timelinesById[$this->normalizeNameKey($t['label'])] = $timeline;
        }

        $locationsByNameKey = [];
        foreach ($validated['locations'] as $l) {
            if (trim($l['name']) === '') {
                continue;
            }
            $location = AudiobookLocation::create([
                'story_bible_id' => $storyBible->id,
                'canonical_name' => $l['name'],
                'aliases' => $l['aliases'],
                'cultural_context' => $l['cultural_context'],
                'visual_notes' => $l['visual_notes'],
            ]);
            $locationsByNameKey[$this->normalizeNameKey($l['name'])] = $location;
            foreach ($l['aliases'] as $alias) {
                $locationsByNameKey[$this->normalizeNameKey($alias)] = $location;
            }
        }

        foreach ($validated['characters'] as $c) {
            if (trim($c['name']) === '') {
                continue;
            }
            $character = AudiobookCharacter::create([
                'story_bible_id' => $storyBible->id,
                'canonical_name' => $c['name'],
                'aliases' => $c['aliases'],
                'role' => $c['role'],
                'cultural_origin' => $c['cultural_origin'],
                'identity_anchor' => $c['identity_anchor'],
                'baseline_traits' => $c['baseline_traits'],
            ]);

            foreach ($c['character_phases'] as $p) {
                $timeline = $p['timeline_label'] ? ($timelinesById[$this->normalizeNameKey($p['timeline_label'])] ?? null) : null;
                $location = $p['current_location_name'] ? ($locationsByNameKey[$this->normalizeNameKey($p['current_location_name'])] ?? null) : null;

                AudiobookCharacterPhase::create([
                    'character_id' => $character->id,
                    'timeline_id' => $timeline?->id,
                    'current_location_id' => $location?->id,
                    'label' => $p['label'],
                    'chronological_order' => $p['chronological_order'],
                    'mutable_traits' => $p['mutable_traits'],
                    'profile' => $p['profile'],
                ]);
            }
        }
    }

    private function normalizeNameKey(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    /**
     * @param array<string,bool> $usedKeys mutated as keys are claimed
     */
    private function uniqueSlug(string $label, array &$usedKeys): string
    {
        $base = Str::slug($label) ?: 'timeline';
        $key = $base;
        $i = 2;
        while (isset($usedKeys[$key])) {
            $key = $base . '-' . $i;
            $i++;
        }
        $usedKeys[$key] = true;
        return $key;
    }

    private function claimSchema(array $valueSchema): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'value' => $valueSchema,
                'confidence' => ['type' => 'string', 'enum' => ['confirmed', 'inferred', 'inferred_low_confidence', 'unknown']],
                'source_type' => ['type' => 'string', 'enum' => ['explicit_text', 'inferred_from_text', 'director_choice', 'user_override', 'unknown']],
                'evidence' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'chapter_id' => ['type' => ['integer', 'null']],
                            'quote' => ['type' => 'string'],
                        ],
                        'required' => ['chapter_id', 'quote'],
                        'additionalProperties' => false,
                    ],
                ],
                'rationale' => ['type' => ['string', 'null']],
            ],
            'required' => ['value', 'confidence', 'source_type', 'evidence', 'rationale'],
            'additionalProperties' => false,
        ];
    }

    private function textClaimSchema(): array
    {
        return $this->claimSchema(['type' => ['string', 'null']]);
    }

    private function listClaimSchema(): array
    {
        return $this->claimSchema(['type' => 'array', 'items' => ['type' => 'string']]);
    }

    private function batchExtractionSchema(): array
    {
        $factItem = fn(array $extra = []) => [
            'type' => 'object',
            'properties' => array_merge([
                'note' => ['type' => 'string'],
                'chapter_id' => ['type' => ['integer', 'null']],
                'quote' => ['type' => ['string', 'null']],
            ], $extra),
            'required' => array_merge(['note', 'chapter_id', 'quote'], array_keys($extra)),
            'additionalProperties' => false,
        ];

        return [
            'name' => 'story_bible_batch_extraction',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'synopsis' => ['type' => 'string'],
                    'characters' => [
                        'type' => 'array',
                        'items' => $factItem(['name' => ['type' => 'string'], 'change_signal' => ['type' => ['string', 'null']]]),
                    ],
                    'locations' => [
                        'type' => 'array',
                        'items' => $factItem(['name' => ['type' => 'string']]),
                    ],
                    'time_signals' => [
                        'type' => 'array',
                        'items' => $factItem(),
                    ],
                ],
                'required' => ['synopsis', 'characters', 'locations', 'time_signals'],
                'additionalProperties' => false,
            ],
        ];
    }

    private function culturalGroupSchema(): array
    {
        return $this->claimSchema([
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'presence' => ['type' => 'string', 'enum' => ['local', 'visiting', 'ambiguous']],
            ],
            'required' => ['name', 'presence'],
            'additionalProperties' => false,
        ]);
    }

    private function timelineItemSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'label' => ['type' => 'string'],
                'timeline_type' => ['type' => 'string', 'enum' => ['main', 'flashback', 'parallel', 'frame_story', 'other']],
                'chronological_order' => ['type' => 'integer'],
                'narrative_introduction_order' => ['type' => ['integer', 'null']],
                'profile' => $this->claimSchema([
                    'type' => 'object',
                    'properties' => [
                        'story_time_marker' => ['type' => ['string', 'null']],
                        'description' => ['type' => ['string', 'null']],
                    ],
                    'required' => ['story_time_marker', 'description'],
                    'additionalProperties' => false,
                ]),
            ],
            'required' => ['label', 'timeline_type', 'chronological_order', 'narrative_introduction_order', 'profile'],
            'additionalProperties' => false,
        ];
    }

    private function locationItemSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'aliases' => ['type' => 'array', 'items' => ['type' => 'string']],
                'cultural_context' => [
                    'type' => 'object',
                    'properties' => [
                        'region' => $this->textClaimSchema(),
                        'historical_polity' => $this->textClaimSchema(),
                        'cultural_groups_present' => ['type' => 'array', 'items' => $this->culturalGroupSchema()],
                        'architecture' => $this->textClaimSchema(),
                        'clothing' => $this->textClaimSchema(),
                        'transportation' => $this->textClaimSchema(),
                        'religion' => $this->textClaimSchema(),
                        'material_culture' => $this->textClaimSchema(),
                        'environment' => $this->textClaimSchema(),
                        'anachronism_constraints' => $this->listClaimSchema(),
                    ],
                    'required' => ['region', 'historical_polity', 'cultural_groups_present', 'architecture', 'clothing', 'transportation', 'religion', 'material_culture', 'environment', 'anachronism_constraints'],
                    'additionalProperties' => false,
                ],
                'visual_notes' => $this->textClaimSchema(),
            ],
            'required' => ['name', 'aliases', 'cultural_context', 'visual_notes'],
            'additionalProperties' => false,
        ];
    }

    private function identityAnchorValueSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'gender' => ['type' => ['string', 'null']],
                'ethnicity_notes' => ['type' => ['string', 'null']],
                'base_face' => ['type' => ['string', 'null']],
                'defining_marks' => ['type' => ['string', 'null']],
            ],
            'required' => ['gender', 'ethnicity_notes', 'base_face', 'defining_marks'],
            'additionalProperties' => false,
        ];
    }

    private function baselineTraitsValueSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'physique' => ['type' => ['string', 'null']],
                'hairstyle' => ['type' => ['string', 'null']],
                'wardrobe' => ['type' => ['string', 'null']],
                'emotional_state' => ['type' => ['string', 'null']],
                'social_status' => ['type' => ['string', 'null']],
                'occupation' => ['type' => ['string', 'null']],
            ],
            'required' => ['physique', 'hairstyle', 'wardrobe', 'emotional_state', 'social_status', 'occupation'],
            'additionalProperties' => false,
        ];
    }

    private function mutableTraitsValueSchema(): array
    {
        $baselineTraitsValue = $this->baselineTraitsValueSchema();

        return [
            'type' => 'object',
            'properties' => array_merge($baselineTraitsValue['properties'], [
                'injuries' => ['type' => ['string', 'null']],
                'identity_overrides' => ['anyOf' => [['type' => 'null'], $this->identityAnchorValueSchema()]],
            ]),
            'required' => array_merge($baselineTraitsValue['required'], ['injuries', 'identity_overrides']),
            'additionalProperties' => false,
        ];
    }

    private function characterItemSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'aliases' => ['type' => 'array', 'items' => ['type' => 'string']],
                'role' => $this->textClaimSchema(),
                'cultural_origin' => $this->claimSchema([
                    'anyOf' => [
                        ['type' => 'null'],
                        [
                            'type' => 'object',
                            'properties' => [
                                'region' => ['type' => ['string', 'null']],
                                'culture' => ['type' => ['string', 'null']],
                                'notes' => ['type' => ['string', 'null']],
                            ],
                            'required' => ['region', 'culture', 'notes'],
                            'additionalProperties' => false,
                        ],
                    ],
                ]),
                'identity_anchor' => $this->claimSchema(['anyOf' => [['type' => 'null'], $this->identityAnchorValueSchema()]]),
                'baseline_traits' => $this->claimSchema(['anyOf' => [['type' => 'null'], $this->baselineTraitsValueSchema()]]),
                'character_phases' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'label' => ['type' => 'string'],
                            'timeline_label' => ['type' => ['string', 'null']],
                            'chronological_order' => ['type' => 'integer'],
                            'current_location_name' => ['type' => ['string', 'null']],
                            'mutable_traits' => $this->claimSchema(['anyOf' => [['type' => 'null'], $this->mutableTraitsValueSchema()]]),
                            'profile' => $this->claimSchema([
                                'type' => 'object',
                                'properties' => [
                                    'story_time_marker' => ['type' => ['string', 'null']],
                                    'trigger_reason' => ['type' => ['string', 'null']],
                                ],
                                'required' => ['story_time_marker', 'trigger_reason'],
                                'additionalProperties' => false,
                            ]),
                        ],
                        'required' => ['label', 'timeline_label', 'chronological_order', 'current_location_name', 'mutable_traits', 'profile'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['name', 'aliases', 'role', 'cultural_origin', 'identity_anchor', 'baseline_traits', 'character_phases'],
            'additionalProperties' => false,
        ];
    }

    private function worldReduceSchema(): array
    {
        return [
            'name' => 'story_bible_world',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'genre' => $this->textClaimSchema(),
                    'tone' => $this->textClaimSchema(),
                    'timeline_structure' => $this->claimSchema(['type' => 'string', 'enum' => ['linear', 'non_linear', 'flashback', 'time_skip', 'multi_timeline']]),
                    'overall_time_span' => $this->textClaimSchema(),
                    'historical_context' => $this->textClaimSchema(),
                    'geography' => $this->textClaimSchema(),
                    'culture' => $this->textClaimSchema(),
                    'world_rules' => $this->listClaimSchema(),
                    'forbidden_elements' => $this->listClaimSchema(),
                    'visual_style' => $this->textClaimSchema(),
                    'palette' => $this->textClaimSchema(),
                    'lighting' => $this->textClaimSchema(),
                    'camera_language' => $this->textClaimSchema(),
                    'pacing' => $this->textClaimSchema(),
                    'timelines' => ['type' => 'array', 'items' => $this->timelineItemSchema()],
                    'locations' => ['type' => 'array', 'items' => $this->locationItemSchema()],
                ],
                'required' => [
                    'genre', 'tone', 'timeline_structure', 'overall_time_span', 'historical_context', 'geography', 'culture',
                    'world_rules', 'forbidden_elements', 'visual_style', 'palette', 'lighting', 'camera_language', 'pacing',
                    'timelines', 'locations',
                ],
                'additionalProperties' => false,
            ],
        ];
    }

    private function charactersReduceSchema(): array
    {
        return [
            'name' => 'story_bible_characters',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'characters' => ['type' => 'array', 'items' => $this->characterItemSchema()],
                ],
                'required' => ['characters'],
                'additionalProperties' => false,
            ],
        ];
    }
}
