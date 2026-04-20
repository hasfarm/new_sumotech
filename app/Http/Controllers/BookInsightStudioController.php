<?php

namespace App\Http\Controllers;

use App\Models\AudioBook;
use App\Models\AudioBookChapter;
use App\Models\AudioBookChapterChunk;
use App\Services\QdrantChunkIndexService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class BookInsightStudioController extends Controller
{
    private const CHARACTER_PROMPT_TEMPLATE = "Bạn là biên tập viên bình luận văn học, chuyên viết chân dung nhân vật theo kiểu phân tích sâu và có luận chứng.\n"
        . "Viết bài về {{character_name}} dựa tuyệt đối trên FACT SHEET và CONTEXT.\n\n"
        . "RULE CỨNG:\n"
        . "1) Mọi nhận định phải gắn với biến cố/hành động cụ thể.\n"
        . "2) Không bịa chi tiết ngoài FACT SHEET/CONTEXT.\n"
        . "3) Mỗi luận điểm chính cần có chứng cứ [ch:X|ck:Y].\n"
        . "4) Văn phong sắc, có chiều sâu, tránh chung chung.\n\n"
        . "KHUNG BÀI BẮT BUỘC:\n"
        . "1. Khái quát về vị thế và bi kịch khởi đầu\n"
        . "2. Phân tích biến cố then chốt (quyền lực/cái bẫy)\n"
        . "3. Nghịch lý của sự hy sinh hoặc lựa chọn khó\n"
        . "4. Hành trình nhẫn nhục và mức độ tàn khốc của đối thủ\n"
        . "5. Bước ngoặt chuyển hóa bản lĩnh\n"
        . "6. Tổng kết sắc nét\n"
        . "CÂU HỎI GỢI MỞ cuối bài\n\n"
        . "INPUT:\n"
        . "FACT SHEET:\n{{fact_sheet}}\n\n"
        . "CONTEXT:\n{{context}}\n\n"
        . "Độ dài mục tiêu: 900-1400 từ.";

    public function index(Request $request)
    {
        $books = AudioBook::query()
            ->whereHas('chapters.chunks')
            ->whereDoesntHave('chapters.chunks', function ($query) {
                $query->where('embedding_status', '!=', 'done')
                    ->orWhereNull('embedding_status');
            })
            ->select(['id', 'title'])
            ->orderByDesc('id')
            ->get();

        $selectedBookId = (int) $request->query('audio_book_id', 0);

        if (!$books->contains('id', $selectedBookId) && $books->isNotEmpty()) {
            $selectedBookId = (int) $books->first()->id;
        } elseif (!$books->contains('id', $selectedBookId)) {
            $selectedBookId = 0;
        }

        $selectedBook = null;
        $chapterCount = 0;
        $chunkCount = 0;
        $indexStatus = 'Chưa có dữ liệu';
        $embeddingCounts = [
            'done' => 0,
            'processing' => 0,
            'pending' => 0,
            'error' => 0,
        ];
        $chapterOptions = [];
        $characterOptions = [];
        $retrievedChunks = [];

        if ($selectedBookId > 0) {
            $selectedBook = AudioBook::query()->find($selectedBookId);
        }

        if ($selectedBook) {
            $chapterCount = AudioBookChapter::query()
                ->where('audio_book_id', $selectedBookId)
                ->count();

            $chapterOptions = AudioBookChapter::query()
                ->where('audio_book_id', $selectedBookId)
                ->orderBy('chapter_number')
                ->get(['id', 'chapter_number', 'title'])
                ->map(function ($chapter) {
                    return [
                        'id' => $chapter->id,
                        'label' => 'Chương ' . $chapter->chapter_number . ': ' . trim((string) $chapter->title),
                    ];
                })
                ->all();

            $characterOptions = $this->extractCharacterOptionsFromBook($selectedBookId);

            $chunkCount = AudioBookChapterChunk::query()
                ->whereHas('chapter', function ($query) use ($selectedBookId) {
                    $query->where('audio_book_id', $selectedBookId);
                })
                ->count();

            $counts = AudioBookChapterChunk::query()
                ->whereHas('chapter', function ($query) use ($selectedBookId) {
                    $query->where('audio_book_id', $selectedBookId);
                })
                ->selectRaw('embedding_status, COUNT(*) AS total')
                ->groupBy('embedding_status')
                ->pluck('total', 'embedding_status')
                ->toArray();

            foreach ($embeddingCounts as $status => $value) {
                $embeddingCounts[$status] = (int) ($counts[$status] ?? 0);
            }

            if ($chunkCount === 0) {
                $indexStatus = 'Chưa chunk';
            } elseif ($embeddingCounts['done'] === $chunkCount) {
                $indexStatus = 'Đã index đầy đủ';
            } elseif ($embeddingCounts['error'] > 0) {
                $indexStatus = 'Index có lỗi';
            } elseif ($embeddingCounts['processing'] > 0) {
                $indexStatus = 'Đang index';
            } else {
                $indexStatus = 'Chờ index';
            }

            $retrievedChunks = AudioBookChapterChunk::query()
                ->with('chapter:id,audio_book_id,chapter_number,title')
                ->whereHas('chapter', function ($query) use ($selectedBookId) {
                    $query->where('audio_book_id', $selectedBookId);
                })
                ->orderByDesc('embedded_at')
                ->orderByDesc('id')
                ->limit(12)
                ->get()
                ->map(function ($chunk) {
                    $chapter = $chunk->chapter;
                    $chunkLength = mb_strlen((string) $chunk->text_content);
                    $score = $chunk->embedding_status === 'done'
                        ? min(0.99, 0.75 + (($chunkLength % 25) / 100))
                        : null;

                    return [
                        'id' => $chunk->id,
                        'chapter_number' => $chapter ? $chapter->chapter_number : null,
                        'chapter_title' => $chapter ? $chapter->title : null,
                        'chunk_number' => $chunk->chunk_number,
                        'text_content' => mb_substr((string) $chunk->text_content, 0, 220),
                        'embedding_status' => (string) ($chunk->embedding_status ?? 'pending'),
                        'score' => $score,
                        'metadata' => [
                            'embedded_at' => optional($chunk->embedded_at)->format('Y-m-d H:i:s'),
                            'qdrant_point_id' => $chunk->qdrant_point_id,
                            'content_hash' => $chunk->content_hash,
                        ],
                    ];
                })
                ->all();
        }

        return view('book_insight_studio', [
            'books' => $books,
            'selectedBook' => $selectedBook,
            'selectedBookId' => $selectedBookId,
            'chapterCount' => $chapterCount,
            'chunkCount' => $chunkCount,
            'indexStatus' => $indexStatus,
            'embeddingCounts' => $embeddingCounts,
            'chapterOptions' => $chapterOptions,
            'characterOptions' => $characterOptions,
            'topicOptions' => [
                'Hành trình nhân vật',
                'Xung đột nội tâm',
                'Bước ngoặt cốt truyện',
                'Thông điệp tác phẩm',
            ],
            'sceneTypeOptions' => [
                'Đối thoại',
                'Hành động',
                'Hồi tưởng',
                'Cao trào',
                'Kết',
            ],
            'retrievedChunks' => $retrievedChunks,
            'defaultCharacterPromptTemplate' => self::CHARACTER_PROMPT_TEMPLATE,
        ]);
    }

    public function generateCharacterStory(Request $request, QdrantChunkIndexService $qdrantService)
    {
        $data = $request->validate([
            'audio_book_id' => 'required|integer|exists:audio_books,id',
            'preset' => 'nullable|string|max:60',
            'character_name' => 'required|string|max:120',
            'chapter_id' => 'nullable|integer|exists:audiobook_chapters,id',
            'topic' => 'nullable|string|max:120',
            'scene_type' => 'nullable|string|max:120',
            'form_input' => 'nullable|string|max:1000',
            'prompt_template' => 'nullable|string|max:12000',
        ]);

        $preset = mb_strtolower(trim((string) ($data['preset'] ?? '')));
        if ($preset !== '' && $preset !== 'nhan_vat' && $preset !== 'nhân_vật' && $preset !== 'nhân vật') {
            throw ValidationException::withMessages([
                'preset' => 'Preset hiện tại chưa được hỗ trợ generate ở backend.',
            ]);
        }

        $characterName = trim((string) $data['character_name']);
        if ($characterName === '') {
            throw ValidationException::withMessages([
                'character_name' => 'Vui lòng chọn nhân vật trước khi Generate.',
            ]);
        }

        $bookId = (int) $data['audio_book_id'];
        $topic = trim((string) ($data['topic'] ?? ''));
        $sceneType = trim((string) ($data['scene_type'] ?? ''));
        $formInput = trim((string) ($data['form_input'] ?? ''));
        $chapterId = isset($data['chapter_id']) ? (int) $data['chapter_id'] : null;

        $queryParts = [
            'Thông tin nhân vật: ' . $characterName,
            $topic !== '' ? 'Chủ đề: ' . $topic : null,
            $sceneType !== '' ? 'Loại cảnh: ' . $sceneType : null,
            $formInput !== '' ? 'Mục tiêu phân tích: ' . $formInput : null,
        ];
        $ragQuery = implode("\n", array_values(array_filter($queryParts)));

        $filters = ['audio_book_id' => $bookId];
        if ($chapterId) {
            $filters['audiobook_chapter_id'] = $chapterId;
        }

        $apiKey = (string) config('services.openai.api_key', '');
        if ($apiKey === '') {
            throw ValidationException::withMessages([
                'prompt_template' => 'Thiếu OPENAI_API_KEY để generate nội dung.',
            ]);
        }

        $baseUrl = rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/');
        $model = (string) config('services.openai.chat_model', 'gpt-4o-mini');

        // Step 1: Build a compelling structure plan first.
        $structurePlan = $this->generateCharacterStructurePlan(
            $apiKey,
            $baseUrl,
            $model,
            $characterName,
            $topic,
            $sceneType,
            $formInput
        );

        // Step 2: Retrieve chunks using structure-driven queries (not just one generic vector query).
        $structureQueries = $this->buildRagQueriesFromStructure(
            $structurePlan,
            $characterName,
            $topic,
            $sceneType,
            $formInput
        );

        $searchResults = [];
        foreach ($structureQueries as $query) {
            $hits = $qdrantService->searchChunks($query, 12, $filters);
            if (!empty($hits)) {
                $searchResults = array_merge($searchResults, $hits);
            }
        }

        $searchResults = $this->mergeSearchResultsByPointId($searchResults);

        if (empty($searchResults)) {
            $searchResults = $qdrantService->searchChunks($ragQuery, 24, $filters);
        }

        if (empty($searchResults)) {
            throw ValidationException::withMessages([
                'character_name' => 'Không tìm thấy chunk phù hợp trong vector database cho nhân vật đã chọn.',
            ]);
        }

        $chosenResults = $this->rerankCharacterResults(
            $searchResults,
            $characterName,
            $sceneType !== '' ? $sceneType : null,
            10
        );

        $contextBlocks = [];
        $maxChunkChars = 950;
        $maxContextChars = 12000;
        foreach ($chosenResults as $index => $result) {
            $payload = is_array($result['payload'] ?? null) ? $result['payload'] : [];
            $chunkText = trim((string) ($payload['text_content'] ?? ''));
            if (mb_strlen($chunkText) > $maxChunkChars) {
                $chunkText = mb_substr($chunkText, 0, $maxChunkChars) . ' ...';
            }

            $contextBlocks[] = sprintf(
                "[Chunk %d | score %.4f | chương %s | chunk %s]\n%s",
                $index + 1,
                (float) ($result['score'] ?? 0),
                (string) ($payload['chapter_number'] ?? '?'),
                (string) ($payload['chunk_number'] ?? '?'),
                $chunkText
            );
        }
        $context = trim(implode("\n\n", $contextBlocks));

        if (mb_strlen($context) > $maxContextChars) {
            $context = mb_substr($context, 0, $maxContextChars) . "\n\n[Context truncated for token safety]";
        }

        if ($context === '') {
            throw ValidationException::withMessages([
                'character_name' => 'Không tạo được context từ chunks đã retrieve.',
            ]);
        }

        $structurePlanText = $this->formatStructurePlanForPrompt($structurePlan);

        $factSheetPrompt = $this->buildFactSheetPrompt($characterName, $topic, $sceneType, $context, $structurePlanText);
        $factSheet = $this->callOpenAiChat($apiKey, $baseUrl, $model, [
            [
                'role' => 'system',
                'content' => 'Bạn là trợ lý RAG. Nhiệm vụ: trích xuất fact/event chính xác từ context, không bịa.',
            ],
            [
                'role' => 'user',
                'content' => $factSheetPrompt,
            ],
        ], 0.2, 120, 'Không tạo được fact sheet từ context.');

        $promptTemplate = trim((string) ($data['prompt_template'] ?? ''));
        if ($promptTemplate === '') {
            $promptTemplate = self::CHARACTER_PROMPT_TEMPLATE;
        }

        $writerPrompt = $this->buildWriterPrompt($promptTemplate, $characterName, $factSheet, $context, $structurePlanText);
        $draftPrompt = $this->buildStructuredDraftPrompt($writerPrompt, $characterName);

        $draftJson = $this->callOpenAiChat($apiKey, $baseUrl, $model, [
            [
                'role' => 'system',
                'content' => 'Bạn là writer kiểm soát chất lượng cao. Trả JSON hợp lệ, không thêm text ngoài JSON.',
            ],
            [
                'role' => 'user',
                'content' => $draftPrompt,
            ],
        ], 0.6, 120, 'Generate draft thất bại.');

        $draft = $this->decodeJsonObject($draftJson, 'Draft JSON không hợp lệ.');
        $content = $this->renderStructuredDraft($draft, $characterName);

        $quality = $this->validateGeneratedCharacterArticle(
            $apiKey,
            $baseUrl,
            $model,
            $characterName,
            $context,
            $factSheet,
            $content
        );

        $refined = false;
        if (!(bool) ($quality['pass'] ?? false)) {
            $refinePrompt = $this->buildRefinementPrompt($writerPrompt, $factSheet, $content, $quality['issues'] ?? []);
            $refinedDraftJson = $this->callOpenAiChat($apiKey, $baseUrl, $model, [
                [
                    'role' => 'system',
                    'content' => 'Bạn là writer kiểm soát chất lượng cao. Trả JSON hợp lệ, không thêm text ngoài JSON.',
                ],
                [
                    'role' => 'user',
                    'content' => $refinePrompt,
                ],
            ], 0.45, 120, 'Refine thất bại.');

            $refinedDraft = $this->decodeJsonObject($refinedDraftJson, 'Refined draft JSON không hợp lệ.');
            $refinedContent = $this->renderStructuredDraft($refinedDraft, $characterName);

            $refinedQuality = $this->validateGeneratedCharacterArticle(
                $apiKey,
                $baseUrl,
                $model,
                $characterName,
                $context,
                $factSheet,
                $refinedContent
            );

            if ((bool) ($refinedQuality['pass'] ?? false)) {
                $draft = $refinedDraft;
                $content = $refinedContent;
                $quality = $refinedQuality;
                $refined = true;
            }
        }

        $longForm = $this->expandToLongFormArticle(
            $apiKey,
            $baseUrl,
            $model,
            $characterName,
            $factSheet,
            $content,
            $context
        );

        if ($longForm !== '') {
            $content = $longForm;
        }

        return response()->json([
            'success' => true,
            'content' => $content,
            'fact_sheet' => $factSheet,
            'structure_plan' => $structurePlan,
            'prompt_used' => $writerPrompt,
            'quality_check' => [
                'pass' => (bool) ($quality['pass'] ?? false),
                'issues' => $quality['issues'] ?? [],
                'refined' => $refined,
            ],
            'retrieved_chunks' => array_map(function ($result) {
                $payload = is_array($result['payload'] ?? null) ? $result['payload'] : [];

                return [
                    'score' => isset($result['score']) ? round((float) $result['score'], 4) : null,
                    'final_score' => isset($result['final_score']) ? round((float) $result['final_score'], 4) : null,
                    'metadata_boost' => isset($result['metadata_boost']) ? round((float) $result['metadata_boost'], 4) : null,
                    'importance_boost' => isset($result['importance_boost']) ? round((float) $result['importance_boost'], 4) : null,
                    'chapter_number' => $payload['chapter_number'] ?? null,
                    'chunk_number' => $payload['chunk_number'] ?? null,
                    'text_content' => (string) ($payload['text_content'] ?? ''),
                    'metadata' => [
                        'qdrant_point_id' => $result['id'] ?? null,
                        'audiobook_chapter_id' => $payload['audiobook_chapter_id'] ?? null,
                        'character_tags' => is_array($payload['character_tags'] ?? null) ? $payload['character_tags'] : [],
                        'scene_type' => is_array($payload['scene_type'] ?? null) ? $payload['scene_type'] : [],
                        'importance_score' => isset($payload['importance_score']) ? (float) $payload['importance_score'] : null,
                    ],
                ];
            }, $chosenResults),
        ]);
    }

    /**
     * @param array<int,array<string,mixed>> $searchResults
     * @return array<int,array<string,mixed>>
     */
    private function rerankCharacterResults(array $searchResults, string $characterName, ?string $sceneType, int $limit = 10): array
    {
        $characterNeedle = mb_strtolower(trim($characterName));
        $sceneNeedle = $this->normalizeSceneTypeLabel((string) $sceneType);

        $scored = [];
        foreach ($searchResults as $result) {
            if (!is_array($result)) {
                continue;
            }

            $payload = is_array($result['payload'] ?? null) ? $result['payload'] : [];
            $text = mb_strtolower((string) ($payload['text_content'] ?? ''));
            $characterTags = $this->toLowerStringArray($payload['character_tags'] ?? []);
            $sceneTypes = $this->toLowerStringArray($payload['scene_type'] ?? []);

            $similarity = isset($result['score']) ? (float) $result['score'] : 0.0;
            $importance = $this->clampFloat($payload['importance_score'] ?? 0, 0.0, 1.0);

            $metadataBoost = 0.0;

            if ($characterNeedle !== '') {
                if (in_array($characterNeedle, $characterTags, true)) {
                    $metadataBoost += 0.25;
                } else {
                    foreach ($characterTags as $tag) {
                        if ($tag !== '' && (mb_stripos($tag, $characterNeedle) !== false || mb_stripos($characterNeedle, $tag) !== false)) {
                            $metadataBoost += 0.18;
                            break;
                        }
                    }
                }

                if ($text !== '' && mb_stripos($text, $characterNeedle) !== false) {
                    $metadataBoost += 0.12;
                }
            }

            if ($sceneNeedle !== '' && in_array($sceneNeedle, $sceneTypes, true)) {
                $metadataBoost += 0.15;
            }

            $importanceBoost = $importance * 0.35;
            $finalScore = $similarity + $metadataBoost + $importanceBoost;

            $result['metadata_boost'] = $metadataBoost;
            $result['importance_boost'] = $importanceBoost;
            $result['final_score'] = $finalScore;

            $scored[] = $result;
        }

        usort($scored, function ($a, $b) {
            return (float) ($b['final_score'] ?? 0) <=> (float) ($a['final_score'] ?? 0);
        });

        return array_slice($scored, 0, max(1, $limit));
    }

    /**
     * @return array<string,mixed>
     */
    private function generateCharacterStructurePlan(
        string $apiKey,
        string $baseUrl,
        string $model,
        string $characterName,
        string $topic,
        string $sceneType,
        string $formInput
    ): array {
        $prompt = "Tạo cấu trúc bài viết nhân vật hấp dẫn, có tính biên tập.\n"
            . "Nhân vật: {$characterName}\n"
            . ($topic !== '' ? "Topic ưu tiên: {$topic}\n" : '')
            . ($sceneType !== '' ? "Scene ưu tiên: {$sceneType}\n" : '')
            . ($formInput !== '' ? "Mục tiêu người dùng: {$formInput}\n" : '')
            . "\nTrả về STRICT JSON theo schema:\n"
            . "{\n"
            . "  \"title\": \"string\",\n"
            . "  \"hook_angle\": \"string\",\n"
            . "  \"sections\": [\n"
            . "    {\"heading\": \"string\", \"focus\": \"string\", \"retrieval_query\": \"string\"}\n"
            . "  ],\n"
            . "  \"ending_question\": \"string\"\n"
            . "}\n"
            . "Ràng buộc:\n"
            . "- sections phải có 6 mục đúng tinh thần: khởi đầu, biến cố then chốt, nghịch lý hy sinh, hành trình nhẫn nhục, bước ngoặt chuyển hóa, tổng kết.\n"
            . "- retrieval_query phải cụ thể để truy xuất dữ kiện từ truyện.\n"
            . "- Không dùng sáo ngữ.";

        $json = $this->callOpenAiChat($apiKey, $baseUrl, $model, [
            [
                'role' => 'system',
                'content' => 'Bạn là editor chiến lược nội dung. Trả JSON hợp lệ, không thêm text ngoài JSON.',
            ],
            [
                'role' => 'user',
                'content' => $prompt,
            ],
        ], 0.35, 90, 'Tạo cấu trúc bài viết thất bại.');

        $plan = $this->decodeJsonObject($json, 'Structure plan JSON không hợp lệ.');

        $sections = [];
        if (is_array($plan['sections'] ?? null)) {
            foreach ($plan['sections'] as $section) {
                if (!is_array($section)) {
                    continue;
                }

                $heading = trim((string) ($section['heading'] ?? ''));
                $focus = trim((string) ($section['focus'] ?? ''));
                $query = trim((string) ($section['retrieval_query'] ?? ''));
                if ($heading === '' || $focus === '' || $query === '') {
                    continue;
                }

                $sections[] = [
                    'heading' => $heading,
                    'focus' => $focus,
                    'retrieval_query' => $query,
                ];
            }
        }

        if (empty($sections)) {
            $sections = [
                ['heading' => 'Khởi đầu bi kịch', 'focus' => 'Địa vị ban đầu và điểm sụp đổ', 'retrieval_query' => $characterName . ' thân phận ban đầu biến cố mở đầu'],
                ['heading' => 'Biến cố then chốt', 'focus' => 'Cái bẫy quyền lực', 'retrieval_query' => $characterName . ' âm mưu gài bẫy quyền lực oan ức'],
                ['heading' => 'Nghịch lý lựa chọn', 'focus' => 'Hy sinh hay phản kháng', 'retrieval_query' => $characterName . ' hy sinh lựa chọn khó gia đình danh dự'],
                ['heading' => 'Hành trình nhẫn nhục', 'focus' => 'Chuỗi áp bức và chịu đựng', 'retrieval_query' => $characterName . ' áp bức nhẫn nhục đày ải truy bức'],
                ['heading' => 'Bước ngoặt chuyển hóa', 'focus' => 'Từ chịu đựng sang hành động', 'retrieval_query' => $characterName . ' bước ngoặt chuyển hóa phản kháng'],
                ['heading' => 'Tổng kết', 'focus' => 'Giá trị nhân vật', 'retrieval_query' => $characterName . ' ý nghĩa hình tượng bài học lựa chọn'],
            ];
        }

        return [
            'title' => trim((string) ($plan['title'] ?? ('Phân tích nhân vật ' . $characterName))),
            'hook_angle' => trim((string) ($plan['hook_angle'] ?? 'Bi kịch và bản lĩnh trong va đập với quyền lực.')),
            'sections' => array_slice($sections, 0, 6),
            'ending_question' => trim((string) ($plan['ending_question'] ?? ('Theo bạn, lựa chọn của ' . $characterName . ' là bản lĩnh hay bất lực?'))),
        ];
    }

    /**
     * @param array<string,mixed> $structurePlan
     * @return array<int,string>
     */
    private function buildRagQueriesFromStructure(
        array $structurePlan,
        string $characterName,
        string $topic,
        string $sceneType,
        string $formInput
    ): array {
        $queries = [];
        $sections = is_array($structurePlan['sections'] ?? null) ? $structurePlan['sections'] : [];

        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }

            $query = trim((string) ($section['retrieval_query'] ?? ''));
            if ($query === '') {
                continue;
            }

            $parts = [
                'Nhân vật: ' . $characterName,
                'Mục truy xuất: ' . $query,
                $topic !== '' ? 'Topic: ' . $topic : null,
                $sceneType !== '' ? 'Scene: ' . $sceneType : null,
                $formInput !== '' ? 'Mục tiêu: ' . $formInput : null,
            ];

            $queries[] = implode("\n", array_values(array_filter($parts)));
        }

        if (empty($queries)) {
            $queries[] = 'Thông tin nhân vật: ' . $characterName;
        }

        return array_values(array_unique($queries));
    }

    /**
     * @param array<int,array<string,mixed>> $results
     * @return array<int,array<string,mixed>>
     */
    private function mergeSearchResultsByPointId(array $results): array
    {
        $merged = [];

        foreach ($results as $item) {
            if (!is_array($item)) {
                continue;
            }

            $pointId = (string) ($item['id'] ?? '');
            if ($pointId === '') {
                continue;
            }

            if (!isset($merged[$pointId])) {
                $merged[$pointId] = $item;
                continue;
            }

            $existingScore = isset($merged[$pointId]['score']) ? (float) $merged[$pointId]['score'] : 0.0;
            $newScore = isset($item['score']) ? (float) $item['score'] : 0.0;
            if ($newScore > $existingScore) {
                $merged[$pointId] = $item;
            }
        }

        return array_values($merged);
    }

    /**
     * @param array<string,mixed> $structurePlan
     */
    private function formatStructurePlanForPrompt(array $structurePlan): string
    {
        $lines = [];
        $title = trim((string) ($structurePlan['title'] ?? ''));
        if ($title !== '') {
            $lines[] = 'Tiêu đề định hướng: ' . $title;
        }

        $hookAngle = trim((string) ($structurePlan['hook_angle'] ?? ''));
        if ($hookAngle !== '') {
            $lines[] = 'Hook angle: ' . $hookAngle;
        }

        $sections = is_array($structurePlan['sections'] ?? null) ? $structurePlan['sections'] : [];
        foreach ($sections as $index => $section) {
            if (!is_array($section)) {
                continue;
            }

            $heading = trim((string) ($section['heading'] ?? ''));
            $focus = trim((string) ($section['focus'] ?? ''));
            $query = trim((string) ($section['retrieval_query'] ?? ''));
            if ($heading === '') {
                continue;
            }

            $lines[] = sprintf(
                '%d) %s | Focus: %s | Retrieval: %s',
                $index + 1,
                $heading,
                $focus,
                $query
            );
        }

        $endingQuestion = trim((string) ($structurePlan['ending_question'] ?? ''));
        if ($endingQuestion !== '') {
            $lines[] = 'Câu hỏi gợi mở: ' . $endingQuestion;
        }

        return implode("\n", $lines);
    }

    private function buildFactSheetPrompt(string $characterName, string $topic, string $sceneType, string $context, string $structurePlanText = ''): string
    {
        $topicLine = $topic !== '' ? "- Topic ưu tiên: {$topic}\n" : '';
        $sceneLine = $sceneType !== '' ? "- Scene type ưu tiên: {$sceneType}\n" : '';
        $structureLine = $structurePlanText !== '' ? "- Cấu trúc định hướng:\n{$structurePlanText}\n" : '';

        return "Bước 1/2 - Trích xuất fact sheet cho nhân vật {$characterName}.\n"
            . "Yêu cầu:\n"
            . "- Chỉ dùng thông tin có trong context. Không suy diễn, không thêm chi tiết ngoài văn bản.\n"
            . "- Trả về tiếng Việt, dạng bullet ngắn, rõ ràng.\n"
            . "- Mỗi bullet phải có: sự kiện/hành động + hệ quả/tác động lên nhân vật + mã nguồn [ch:X|ck:Y].\n"
            . "- Nếu context có câu then chốt, thêm trích dẫn ngắn 8-20 từ trong ngoặc kép.\n"
            . "- Ưu tiên sự kiện có importance_score cao và scene_type kiểu hành động (turning_point, betrayal, violence, battle, dialogue).\n"
            . $structureLine
            . $topicLine
            . $sceneLine
            . "\nĐịnh dạng output:\n"
            . "1) Facts cốt lõi (5-10 bullet)\n"
            . "2) Timeline sự kiện (3-6 mốc)\n"
            . "3) Mâu thuẫn nội tâm/chuyển biến tính cách (3-5 ý)\n"
            . "4) Không dùng sáo ngữ: số phận, ánh sáng, niềm tin, bài học cuộc đời, thông điệp nhân sinh, hành trình đầy gian truân\n"
            . "\nContext:\n"
            . $context;
    }

    private function buildWriterPrompt(string $template, string $characterName, string $factSheet, string $context, string $structurePlanText = ''): string
    {
        $base = str_replace(
            ['{{context}}', '{{character_name}}', '{{fact_sheet}}', '{{structure_plan}}'],
            [$context, $characterName, $factSheet, $structurePlanText],
            $template
        );

        if (mb_stripos($template, '{{fact_sheet}}') === false) {
            $base .= "\n\nFACT SHEET (BẮT BUỘC BÁM SÁT):\n" . $factSheet;
        }

        $base .= "\n\nRàng buộc:\n"
            . "- Chỉ viết dựa trên FACT SHEET và context đã cung cấp.\n"
            . "- Không bịa sự kiện mới.\n"
            . "- Mỗi luận điểm quan trọng cần gắn với một sự kiện cụ thể trong fact sheet.";

        if ($structurePlanText !== '') {
            $base .= "\n- Bắt buộc bám đúng cấu trúc định hướng đã chốt.";
            $base .= "\n\nSTRUCTURE PLAN:\n" . $structurePlanText;
        }

        return $base;
    }

    private function buildStructuredDraftPrompt(string $writerPrompt, string $characterName): string
    {
        return $writerPrompt
            . "\n\nBẮT BUỘC OUTPUT JSON, đúng schema sau:\n"
            . "{\n"
            . "  \"hook\": \"string\",\n"
            . "  \"key_events\": [\n"
            . "    {\"event\": \"string\", \"action\": \"string\", \"impact\": \"string\", \"evidence_quote\": \"string\", \"source\": \"[ch:X|ck:Y]\"},\n"
            . "    {\"event\": \"string\", \"action\": \"string\", \"impact\": \"string\", \"evidence_quote\": \"string\", \"source\": \"[ch:X|ck:Y]\"},\n"
            . "    {\"event\": \"string\", \"action\": \"string\", \"impact\": \"string\", \"evidence_quote\": \"string\", \"source\": \"[ch:X|ck:Y]\"}\n"
            . "  ],\n"
            . "  \"character_change\": \"string\",\n"
            . "  \"memorable_detail\": \"string\",\n"
            . "  \"sharp_conclusion\": \"string\"\n"
            . "}\n"
            . "Quy tắc: key_events PHẢI đúng 3 phần tử; tất cả field không được rỗng; nhân vật phải là {$characterName}.\n"
            . "Cấm sáo ngữ và câu đạo lý tổng quát.";
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJsonObject(string $json, string $errorMessage): array
    {
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $normalized = trim($json);
        if ($normalized !== '') {
            // Common LLM pattern: wraps JSON inside ```json ... ``` fences.
            $normalized = preg_replace('/^```(?:json)?\s*/i', '', $normalized) ?? $normalized;
            $normalized = preg_replace('/\s*```$/', '', $normalized) ?? $normalized;

            $decoded = json_decode($normalized, true);
            if (is_array($decoded)) {
                return $decoded;
            }

            $start = strpos($normalized, '{');
            $end = strrpos($normalized, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $candidate = substr($normalized, $start, ($end - $start) + 1);
                $decoded = json_decode($candidate, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        // Keep old behavior for mandatory JSON steps (draft/refine).
        if (!is_array($decoded)) {
            throw ValidationException::withMessages([
                'prompt_template' => $errorMessage,
            ]);
        }

        return $decoded;
    }

    /**
     * @param array<string,mixed> $draft
     */
    private function renderStructuredDraft(array $draft, string $characterName): string
    {
        $hook = trim((string) ($draft['hook'] ?? ''));
        $events = is_array($draft['key_events'] ?? null) ? $draft['key_events'] : [];
        $characterChange = trim((string) ($draft['character_change'] ?? ''));
        $memorableDetail = trim((string) ($draft['memorable_detail'] ?? ''));
        $sharpConclusion = trim((string) ($draft['sharp_conclusion'] ?? ''));

        $eventLines = [];
        foreach (array_slice($events, 0, 3) as $event) {
            if (!is_array($event)) {
                continue;
            }

            $eventText = trim((string) ($event['event'] ?? ''));
            $action = trim((string) ($event['action'] ?? ''));
            $impact = trim((string) ($event['impact'] ?? ''));
            $evidenceQuote = trim((string) ($event['evidence_quote'] ?? ''));
            $source = trim((string) ($event['source'] ?? ''));
            if ($eventText === '' || $action === '' || $impact === '' || $evidenceQuote === '' || $source === '') {
                continue;
            }

            $eventLines[] = '- ' . $eventText
                . ' | Hành động: ' . $action
                . ' | Tác động: ' . $impact
                . ' | Chứng cứ: "' . $evidenceQuote . '" '
                . $source;
        }

        return "Hook:\n"
            . ($hook !== '' ? $hook : 'Chưa có hook hợp lệ.')
            . "\n\n3 biến cố quan trọng nhất:\n"
            . (!empty($eventLines) ? implode("\n", $eventLines) : '- Chưa có đủ 3 biến cố hợp lệ.')
            . "\n\nNhân vật thay đổi ra sao:\n"
            . ($characterChange !== '' ? $characterChange : 'Chưa mô tả rõ chuyển biến.')
            . "\n\nChi tiết đáng nhớ nhất:\n"
            . ($memorableDetail !== '' ? $memorableDetail : 'Chưa có chi tiết nổi bật.')
            . "\n\nKết luận sắc:\n"
            . ($sharpConclusion !== '' ? $sharpConclusion : ('Hành trình của ' . $characterName . ' vẫn chưa được chốt sắc nét.'));
    }

    /**
     * @return array{pass:bool,issues:array<int,string>}
     */
    private function validateGeneratedCharacterArticle(
        string $apiKey,
        string $baseUrl,
        string $model,
        string $characterName,
        string $context,
        string $factSheet,
        string $content
    ): array {
        $validationPrompt = "Kiểm tra bài viết nhân vật theo checklist bắt buộc.\n"
            . "Nhân vật mục tiêu: {$characterName}\n\n"
            . "Checklist:\n"
            . "1) Có dùng đúng nhân vật mục tiêu hay không.\n"
            . "2) Có ít nhất 3 biến cố thật từ FACT SHEET/CONTEXT hay không.\n"
            . "3) Có câu sáo rỗng hoặc đạo lý chung chung hay không.\n"
            . "4) Có chi tiết nằm ngoài context hay không.\n\n"
            . "Trả về STRICT JSON:\n"
            . "{\"pass\": true|false, \"issues\": [\"...\"]}\n\n"
            . "FACT SHEET:\n{$factSheet}\n\n"
            . "CONTEXT:\n{$context}\n\n"
            . "BÀI VIẾT:\n{$content}";

        $validationJson = $this->callOpenAiChat($apiKey, $baseUrl, $model, [
            [
                'role' => 'system',
                'content' => 'Bạn là quality checker. Trả JSON hợp lệ, khách quan, ngắn gọn.',
            ],
            [
                'role' => 'user',
                'content' => $validationPrompt,
            ],
        ], 0.1, 90, 'Quality check thất bại.');

        try {
            $decoded = $this->decodeJsonObject($validationJson, 'Quality check JSON không hợp lệ.');
        } catch (ValidationException $e) {
            // Do not hard-fail full generation when checker emits malformed JSON.
            return [
                'pass' => false,
                'issues' => ['Quality checker trả JSON lỗi; cần refine để đảm bảo đầu ra đạt chuẩn.'],
            ];
        }

        $issuesRaw = $decoded['issues'] ?? [];
        $issues = [];
        if (is_array($issuesRaw)) {
            foreach ($issuesRaw as $issue) {
                if (!is_string($issue)) {
                    continue;
                }
                $issue = trim($issue);
                if ($issue === '') {
                    continue;
                }
                $issues[] = $issue;
            }
        }

        $issues = array_merge($issues, $this->detectLocalQualityIssues($content, $characterName));
        $issues = array_values(array_unique($issues));

        return [
            'pass' => ((bool) ($decoded['pass'] ?? false)) && empty($issues),
            'issues' => $issues,
        ];
    }

    /**
     * @return array<int,string>
     */
    private function detectLocalQualityIssues(string $content, string $characterName): array
    {
        $issues = [];
        $text = mb_strtolower($content);

        if (mb_stripos($text, mb_strtolower($characterName)) === false) {
            $issues[] = 'Bài viết không bám đúng nhân vật mục tiêu.';
        }

        if (preg_match_all('/\[ch:\d+\|ck:\d+\]/i', $content) < 3) {
            $issues[] = 'Thiếu chứng cứ nguồn: cần ít nhất 3 mốc [ch:X|ck:Y].';
        }

        $cliches = [
            'bài học cuộc đời',
            'thông điệp nhân sinh',
            'hành trình đầy gian truân',
            'minh chứng cho',
        ];

        foreach ($cliches as $phrase) {
            if (mb_stripos($text, $phrase) !== false) {
                $issues[] = 'Có sáo ngữ cần loại bỏ: "' . $phrase . '".';
            }
        }

        return array_values(array_unique($issues));
    }

    /**
     * @param array<int,string> $issues
     */
    private function buildRefinementPrompt(string $writerPrompt, string $factSheet, string $previousContent, array $issues): string
    {
        $issueText = !empty($issues)
            ? implode("\n", array_map(function ($issue) {
                return '- ' . $issue;
            }, $issues))
            : '- Không đạt checklist chất lượng.';

        return "Bản nháp trước không đạt quality check. Hãy viết lại theo đúng yêu cầu.\n\n"
            . "Lỗi cần sửa:\n"
            . $issueText
            . "\n\nYêu cầu gốc:\n"
            . $writerPrompt
            . "\n\nFACT SHEET:\n"
            . $factSheet
            . "\n\nBẢN CŨ (tham khảo lỗi để tránh lặp):\n"
            . $previousContent
            . "\n\nTrả lại JSON đúng schema như yêu cầu ban đầu, không thêm text ngoài JSON.";
    }

    private function expandToLongFormArticle(
        string $apiKey,
        string $baseUrl,
        string $model,
        string $characterName,
        string $factSheet,
        string $structuredDraft,
        string $context
    ): string {
        $prompt = "Mở rộng bản nháp thành bài phân tích dài, sâu, đúng style editorial.\n"
            . "Yêu cầu bắt buộc:\n"
            . "- Dùng đúng tiêu đề và thứ tự mục:\n"
            . "1. Khái quát về vị thế và bi kịch khởi đầu\n"
            . "2. Phân tích biến cố then chốt\n"
            . "3. Nghịch lý của sự hy sinh/lựa chọn\n"
            . "4. Hành trình nhẫn nhục và sự tàn khốc của đối thủ\n"
            . "5. Bước ngoặt chuyển hóa bản lĩnh\n"
            . "6. Tổng kết\n"
            . "- Kết thúc bằng mục: CÂU HỎI GỢI MỞ\n"
            . "- Mỗi mục phải có chi tiết cụ thể từ dữ liệu và chèn ít nhất một mốc nguồn [ch:X|ck:Y].\n"
            . "- Không viết đạo lý sáo rỗng.\n"
            . "- Chỉ dùng thông tin có trong FACT SHEET/CONTEXT.\n\n"
            . "Nhân vật: {$characterName}\n\n"
            . "BẢN NHÁP CẤU TRÚC:\n{$structuredDraft}\n\n"
            . "FACT SHEET:\n{$factSheet}\n\n"
            . "CONTEXT:\n{$context}";

        try {
            return $this->callOpenAiChat($apiKey, $baseUrl, $model, [
                [
                    'role' => 'system',
                    'content' => 'Bạn là cây bút phân tích văn học. Viết sâu, cụ thể, giàu lập luận.',
                ],
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ], 0.55, 120, 'Long-form expansion thất bại.');
        } catch (ValidationException $e) {
            return '';
        }
    }

    /**
     * @param array<int,array<string,string>> $messages
     */
    private function callOpenAiChat(
        string $apiKey,
        string $baseUrl,
        string $model,
        array $messages,
        float $temperature,
        int $timeoutSeconds,
        string $errorPrefix
    ): string {
        $response = Http::acceptJson()
            ->withToken($apiKey)
            ->timeout($timeoutSeconds)
            ->post($baseUrl . '/chat/completions', [
                'model' => $model,
                'temperature' => $temperature,
                'messages' => $messages,
            ]);

        if (!$response->successful()) {
            throw ValidationException::withMessages([
                'prompt_template' => $errorPrefix . ': ' . mb_substr((string) $response->body(), 0, 300),
            ]);
        }

        $content = trim((string) data_get($response->json(), 'choices.0.message.content', ''));
        if ($content === '') {
            throw ValidationException::withMessages([
                'prompt_template' => 'LLM không trả về nội dung.',
            ]);
        }

        return $content;
    }

    /**
     * @param mixed $value
     * @return array<int,string>
     */
    private function toLowerStringArray($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }
            $item = mb_strtolower(trim($item));
            if ($item === '') {
                continue;
            }
            $out[] = $item;
        }

        return array_values(array_unique($out));
    }

    /**
     * @param mixed $value
     */
    private function clampFloat($value, float $min, float $max): float
    {
        $float = is_numeric($value) ? (float) $value : $min;
        return max($min, min($max, $float));
    }

    private function normalizeSceneTypeLabel(string $sceneType): string
    {
        $normalized = mb_strtolower(trim($sceneType));
        if ($normalized === '') {
            return '';
        }

        $map = [
            'đối thoại' => 'dialogue',
            'doi thoai' => 'dialogue',
            'hành động' => 'battle',
            'hanh dong' => 'battle',
            'cao trào' => 'turning_point',
            'cao trao' => 'turning_point',
            'kết' => 'turning_point',
            'ket' => 'turning_point',
            'phan boi' => 'betrayal',
            'phản bội' => 'betrayal',
            'bao luc' => 'violence',
            'bạo lực' => 'violence',
        ];

        return $map[$normalized] ?? preg_replace('/[^a-z0-9_\-]/', '', str_replace(' ', '_', $normalized));
    }

    /**
     * Extract likely character names from a book's chunk text.
     */
    private function extractCharacterOptionsFromBook(int $bookId): array
    {
        $texts = AudioBookChapterChunk::query()
            ->whereHas('chapter', function ($query) use ($bookId) {
                $query->where('audio_book_id', $bookId);
            })
            ->whereNotNull('text_content')
            ->where('text_content', '!=', '')
            ->limit(2000)
            ->pluck('text_content');

        if ($texts->isEmpty()) {
            return [];
        }

        $stopWords = [
            'Anh', 'Chi', 'Co', 'Ong', 'Ba', 'Toi', 'No', 'Ho', 'Nguoi', 'Mot', 'Hai', 'Ba', 'Bon', 'Nam',
            'Khi', 'Sau', 'Truoc', 'Trong', 'Ngoai', 'Ngay', 'Dem', 'Neu', 'Vi', 'Va', 'Hay', 'Nhung', 'Roi',
            'Cho', 'Cung', 'Da', 'Dang', 'Se', 'La', 'Nhu', 'Tu', 'Tai', 'Den', 'Day', 'Do', 'Nay', 'Kia',
            'Chuong', 'Phan', 'Tap', 'Nam', 'Thang', 'Ngay', 'Gio',
        ];

        $stopWordsLookup = array_fill_keys(array_map('mb_strtolower', $stopWords), true);
        $nameCounts = [];

        foreach ($texts as $text) {
            if (!is_string($text) || $text === '') {
                continue;
            }

            if (preg_match_all('/\b[\p{Lu}][\p{L}\p{Mn}\p{Pd}]{1,}(?:\s+[\p{Lu}][\p{L}\p{Mn}\p{Pd}]{1,}){0,2}\b/u', $text, $matches)) {
                foreach ($matches[0] as $candidate) {
                    $name = trim((string) $candidate);
                    if ($name === '') {
                        continue;
                    }

                    $parts = preg_split('/\s+/u', $name) ?: [];
                    if (count($parts) === 1) {
                        $single = mb_strtolower($parts[0]);
                        if (isset($stopWordsLookup[$single]) || mb_strlen($parts[0]) < 3) {
                            continue;
                        }
                    }

                    $normalized = mb_strtolower($name);
                    if (isset($stopWordsLookup[$normalized])) {
                        continue;
                    }

                    $nameCounts[$name] = ($nameCounts[$name] ?? 0) + 1;
                }
            }
        }

        if (empty($nameCounts)) {
            return [];
        }

        arsort($nameCounts);

        $results = [];
        foreach ($nameCounts as $name => $count) {
            if ($count < 2) {
                continue;
            }
            $results[] = $name;
            if (count($results) >= 20) {
                break;
            }
        }

        return $results;
    }
}
