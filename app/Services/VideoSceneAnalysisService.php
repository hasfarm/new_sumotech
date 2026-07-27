<?php

namespace App\Services;

use App\Models\AudioBook;
use App\Models\AudiobookSummary;
use Illuminate\Support\Facades\Http;

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
     * Stage B of the split pipeline: enrich a SMALL chunk of shots (≤~15, caller decides
     * the exact chunk boundaries) with is_real_world/keywords/image_request. Same analysis
     * rules as the old monolithic analyzeScene(), just scoped to a bounded chunk instead of
     * an entire scene's shot list — this is the actual fix for the request-size-driven
     * timeouts (a real book produced 80 shots in one old-style call).
     *
     * @param array<int,array{index:int,text:string}> $shotChunk global 1-based shot index + text
     * @return array<int,array{index:int,is_real_world:bool,keywords:array<int,string>,image_request:string}> keyed by global index
     */
    public function enrichShotsChunk(array $shotChunk, AudioBook $audioBook, string $sceneTitle, string $sceneType, string $contextHint = ''): array
    {
        $bookLine = 'Sách: "' . $audioBook->title . '"' . ($audioBook->author ? ' - Tác giả: ' . $audioBook->author : '');
        $contextBlock = $contextHint !== ''
            ? "\nBỐI CẢNH VĂN HÓA/LỊCH SỬ/ĐỊA LÝ CỦA SÁCH (bắt buộc tuân thủ):\n{$contextHint}\n"
            : '';

        $shotListText = collect($shotChunk)
            ->map(fn($s) => '[' . $s['index'] . '] ' . $s['text'])
            ->implode("\n");

        $prompt = "Bạn là đạo diễn hình ảnh cho video kể chuyện. Dưới đây là một số SHOT (mỗi shot ứng với 1 clip minh họa duy nhất, khoảng 5-10 giây) trích từ cảnh \"{$sceneTitle}\" (loại: {$sceneType}) của video.\n"
            . $bookLine . "\n"
            . $contextBlock . "\n"
            . "Với MỖI shot (giữ ĐÚNG số index đã cho, không đổi số):\n"
            . "a) is_real_world: cảnh vật/sự vật được mô tả trong shot đó CÓ THẬT NGOÀI ĐỜI, CÓ THỂ TÌM THẤY được dưới dạng ảnh/video quay thật (stock footage) hay không?\n"
            . "b) keywords: sinh từ khóa tiếng Anh để tìm ảnh/video minh họa CHO ĐÚNG nội dung câu của shot đó.\n"
            . "c) image_request: viết MỘT CÂU MÔ TẢ hình ảnh đầy đủ, chi tiết bằng tiếng Anh (như brief cho một art director/photographer), dùng để tìm kiếm ngữ nghĩa (semantic search) trong thư viện ảnh có sẵn — câu này cần giàu thông tin hơn nhiều so với keywords.\n"
            . "Yêu cầu:\n"
            . "- is_real_world: PHÉP THỬ BẮT BUỘC trước khi trả lời — hỏi: \"Nếu đưa câu này cho một người quay phim tài liệu, họ có xác định được MỘT cảnh/hành động/địa điểm CỤ THỂ, DUY NHẤT để quay không?\" Chỉ trả TRUE nếu câu MÔ TẢ trực tiếp một cảnh vật/con người/địa điểm/hành động CỤ THỂ có thật, có thể quay được ngoài đời (vd: núi rừng, thành quách, người đi bộ, chiến trường, quân đội hành quân, bản đồ, tài liệu lịch sử...). Trả FALSE trong CẢ HAI trường hợp sau: (1) nội dung HƯ CẤU/siêu nhiên/kỳ ảo (rồng, thần linh, phép thuật, linh hồn); (2) câu là lời BÌNH LUẬN/NHẬN ĐỊNH/KẾT LUẬN/KHÁI QUÁT TRỪU TƯỢNG của người kể chuyện — dù có nhắc tên địa danh/sự kiện/khái niệm lịch sử có thật, câu KHÔNG mô tả một cảnh cụ thể nào đang diễn ra thì vẫn là FALSE. Ví dụ PHẢI là FALSE: \"Những tư tưởng sắc bén trong cuốn sách này không chỉ định hình nghệ thuật chiến tranh thời cổ đại mà còn giữ nguyên giá trị triết lý\" (bình luận về giá trị tư tưởng, không phải cảnh cụ thể); \"liên quan trực tiếp đến sự sống chết của nhân dân và sự tồn vong của đất nước\" (câu khái quát hậu quả trừu tượng, không mô tả cảnh cụ thể nào).\n"
            . "- keywords: 3-5 từ khóa tiếng Anh NGẮN GỌN (1-3 từ/keyword) mô tả hình ảnh trực quan CỤ THỂ cho đúng nội dung của shot đó (ví dụ: \"ancient castle gate\", \"stormy ocean waves\"). Nếu is_real_world=false do câu TRỪU TƯỢNG/bình luận/khái quát (không phải hư cấu), KHÔNG suy diễn ra một cảnh lịch sử cụ thể không có trong câu — thay vào đó dùng từ khóa MANG TÍNH BIỂU TƯỢNG/ẩn dụ trung tính phù hợp để tạo ảnh AI minh họa khái niệm (vd: \"ancient Chinese scroll close-up\", \"calligraphy brush ink\", \"candlelight old book pages\", \"silhouette contemplating war map\"). Nếu is_real_world=false do hư cấu/siêu nhiên, keywords mô tả hình ảnh kỳ ảo mong muốn để làm prompt tạo ảnh AI.\n"
            . "- BẮT BUỘC: mọi keyword liên quan tới con người/trang phục/kiến trúc/vũ khí PHẢI ghi rõ đúng nền văn hóa/dân tộc/thời đại theo bối cảnh sách ở trên (vd sách bối cảnh Trung Hoa cổ đại thì phải viết \"ancient Chinese warrior\", \"Chinese imperial palace\"... TUYỆT ĐỐI không viết chung chung \"ancient soldier\", \"medieval knight\", \"ancient warrior\" vì kho ảnh stock sẽ trả về sai nền văn hóa.\n"
            . "- Không dùng tên riêng không có trong kho ảnh, không lặp y hệt keyword giữa các shot nếu nội dung shot khác nhau.\n"
            . "- image_request: 1 câu tiếng Anh đầy đủ (15-30 từ), mô tả CỤ THỂ chủ thể, hành động, bối cảnh, nền văn hóa/thời đại, không khí/ánh sáng — vd \"A formation of ancient Chinese infantry soldiers in lacquered leather armor marching through a misty mountain pass at dawn, spears raised, banners flowing\". Áp dụng CÙNG quy tắc văn hóa/thời đại như keywords. Nếu is_real_world=false, mô tả hình ảnh biểu tượng/ẩn dụ hoặc kỳ ảo tương ứng.\n"
            . "- BẮT BUỘC trả về đủ " . count($shotChunk) . " shot, đúng số index đã cho, không bỏ sót.\n\n"
            . "DANH SÁCH SHOT:\n" . $shotListText;

        $schema = [
            'name' => 'shot_chunk_enrichment',
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'shots' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'index' => ['type' => 'integer'],
                                'is_real_world' => ['type' => 'boolean'],
                                'keywords' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'image_request' => ['type' => 'string'],
                            ],
                            'required' => ['index', 'is_real_world', 'keywords', 'image_request'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['shots'],
                'additionalProperties' => false,
            ],
        ];

        $decoded = $this->openAiService->completeJson($prompt, [
            'reasoning_effort' => 'low',
            'json_schema' => $schema,
            'max_tokens' => 8000,
            'purpose' => 'video_shot_enrich',
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
            ];
        }

        return $result;
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
