<?php

namespace App\Services;

use App\Models\AudioBook;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class AudiobookSummaryService
{
    private const MODEL = 'gemini-3.6-flash';
    private const MAX_BATCH_CHARS = 5000;
    // How many Bước 1 cluster-split calls to fire concurrently per pooled round — bounded
    // (not "all at once") to stay safely under Gemini's SPEND-based rate limit on very large
    // books. Lowered from 20 after a real 452-batch run tripped a 429 on the very next
    // (unrelated) Gemini call in Bước 2 — the account's tier caps $ spent per unit time, not
    // just request count, so a smaller burst spreads the same total spend more gently.
    private const POOL_CONCURRENCY = 10;

    public function __construct(private readonly ClaudeService $claudeService) {}

    /**
     * Preset compression levels the user can pick for Bước 3 (kể lại), expressed as a
     * ratio range of the retell's target length relative to the original cluster length.
     *
     * @return array<string,array{label:string,min:float,max:float}>
     */
    public static function levels(): array
    {
        return [
            'very_short' => ['label' => 'Tóm tắt rất ngắn', 'min' => 0.05, 'max' => 0.10],
            'overview' => ['label' => 'Tóm tắt tổng quan', 'min' => 0.10, 'max' => 0.20],
            'detailed' => ['label' => 'Tóm tắt chi tiết', 'min' => 0.20, 'max' => 0.35],
            'study' => ['label' => 'Bản rút gọn để học/nghiên cứu', 'min' => 0.35, 'max' => 0.50],
            'full' => ['label' => 'Bản giữ gần đầy đủ nội dung', 'min' => 0.50, 'max' => 0.70],
        ];
    }

    /**
     * Group chapters into text batches small enough for Gemini to echo back safely.
     * Splits on paragraph boundaries (not just chapter boundaries) so a single very
     * long chapter cannot blow past the model's output token budget in one call.
     *
     * @return array<int,array{chapter_ids:array<int,int>,text:string}>
     */
    public function buildBatches(Collection $chapters): array
    {
        $batches = [];
        $currentChapterIds = [];
        $currentText = '';

        foreach ($chapters as $chapter) {
            $content = trim((string) $chapter->content);
            if ($content === '') {
                continue;
            }

            $header = "=== Chương {$chapter->chapter_number}"
                . ($chapter->title ? ": {$chapter->title}" : '')
                . " ===";

            $paragraphs = preg_split('/\r?\n+/u', $content) ?: [$content];
            $isChapterStart = true;

            foreach ($paragraphs as $paragraph) {
                $paragraph = trim($paragraph);
                if ($paragraph === '') {
                    continue;
                }

                $prefix = $isChapterStart ? ($header . "\n\n") : '';
                $piece = "\n\n" . $prefix . $paragraph;

                if ($currentText !== '' && mb_strlen($currentText . $piece) > self::MAX_BATCH_CHARS) {
                    $batches[] = ['chapter_ids' => array_values(array_unique($currentChapterIds)), 'text' => trim($currentText)];
                    $currentChapterIds = [];
                    $currentText = '';
                    $prefix = $header . " (tiếp theo)\n\n";
                    $piece = "\n\n" . $prefix . $paragraph;
                }

                $currentChapterIds[] = $chapter->id;
                $currentText .= $piece;
                $isChapterStart = false;
            }
        }

        if (trim($currentText) !== '') {
            $batches[] = ['chapter_ids' => array_values(array_unique($currentChapterIds)), 'text' => trim($currentText)];
        }

        return $batches;
    }

    /**
     * Step 1: split one batch of transcript text into ordered event clusters.
     * The model must only SPLIT the text (verbatim), never summarize it.
     *
     * @return array<int,array{index:int,title:string,content:string}>
     */
    public function splitBatchIntoClusters(string $batchText, int $startIndex, AudioBook $audioBook): array
    {
        $bookLine = 'Sách: "' . $audioBook->title . '"' . ($audioBook->author ? ' - Tác giả: ' . $audioBook->author : '');
        $prompt = $this->buildClusterSplitPrompt($batchText, $bookLine, $startIndex);

        $text = $this->callGemini($prompt, [
            'temperature' => 0.1,
            'json' => true,
            'max_output_tokens' => 32768,
        ]);

        return $this->parseClusterSplitResponseText($text, $startIndex, $batchText);
    }

    /**
     * Same as splitBatchIntoClusters() but fans EVERY batch out CONCURRENTLY (in pooled
     * rounds of self::POOL_CONCURRENCY requests at a time) instead of one Gemini call after
     * another — for a large book this is hundreds of independent round trips with no
     * cross-batch dependency, so running them sequentially was the dominant cost of Bước 1
     * (each call pays full network + "thinking" latency on its own). Indices are assigned
     * AFTER all responses come back, in original batch order, so pooling doesn't affect
     * numbering correctness.
     *
     * @param array<int,array{chapter_ids:array<int,int>,text:string}> $batches
     * @param callable|null $onChunkDone called with (int $processedBatchCount, array $allClustersSoFar)
     *   after each pooled round — batches within a chunk always complete together and chunks
     *   run strictly in original order, so clusters can be finalized/renumbered incrementally
     *   as each chunk lands, letting the caller keep streaming the growing list to the UI
     *   exactly like the old one-batch-at-a-time version did (just in bursts of
     *   self::POOL_CONCURRENCY instead of one at a time).
     * @return array<int,array{index:int,title:string,content:string}>
     */
    public function splitBatchesIntoClusters(array $batches, AudioBook $audioBook, ?callable $onChunkDone = null): array
    {
        if (empty($batches)) {
            return [];
        }

        $bookLine = 'Sách: "' . $audioBook->title . '"' . ($audioBook->author ? ' - Tác giả: ' . $audioBook->author : '');
        $apiKey = trim((string) config('services.gemini.api_key', '')) ?: trim((string) config('services.gemini.tts_api_key', ''));
        if ($apiKey === '') {
            throw new \RuntimeException('Thiếu GEMINI_API_KEY / GEMINI_TTS_API_KEY trong cấu hình runtime.');
        }

        $allClusters = [];
        $nextIndex = 1;
        $processed = 0;

        foreach (array_chunk($batches, self::POOL_CONCURRENCY, true) as $chunk) {
            $responses = $this->poolClusterSplitRequests($chunk, $bookLine, $apiKey);

            // Gemini's spend-based rate limit can 429 a handful of requests within a burst
            // even when most succeed — retry just those (once, after a backoff) instead of
            // immediately settling for the coarse "whole batch as one cluster" fallback.
            $rateLimited = array_filter($chunk, fn($batch, $i) => ($responses[$i] ?? null) instanceof \Illuminate\Http\Client\Response
                && $responses[$i]->status() === 429, ARRAY_FILTER_USE_BOTH);

            if (!empty($rateLimited)) {
                sleep(15);
                $retryResponses = $this->poolClusterSplitRequests($rateLimited, $bookLine, $apiKey);
                $responses = array_replace($responses, $retryResponses);
            }

            // Chunks are processed strictly in order and array_chunk() preserves the original
            // batch order within a chunk, so it's safe to renumber+append immediately here.
            foreach ($chunk as $i => $batch) {
                $batchClusters = $this->parseClusterSplitPoolResponse($responses[$i] ?? null, $batch['text']);
                foreach ($batchClusters as $cluster) {
                    $allClusters[] = ['index' => $nextIndex, 'title' => $cluster['title'], 'content' => $cluster['content']];
                    $nextIndex++;
                }
                $processed++;
            }

            if ($onChunkDone) {
                $onChunkDone($processed, $allClusters);
            }
        }

        return $allClusters;
    }

    /**
     * @param array<int,array{chapter_ids:array<int,int>,text:string}> $chunk keyed by original batch index
     * @return array<int,mixed> pooled Http responses keyed the same as $chunk
     */
    private function poolClusterSplitRequests(array $chunk, string $bookLine, string $apiKey): array
    {
        return Http::pool(function (Pool $pool) use ($chunk, $bookLine, $apiKey) {
            $requests = [];
            foreach ($chunk as $i => $batch) {
                $prompt = $this->buildClusterSplitPrompt($batch['text'], $bookLine, 1);
                $requests[] = $pool->as((string) $i)
                    ->acceptJson()
                    ->connectTimeout(15)
                    ->timeout(180)
                    ->post("https://generativelanguage.googleapis.com/v1beta/models/" . self::MODEL . ":generateContent?key={$apiKey}", [
                        'contents' => [['parts' => [['text' => $prompt]]]],
                        'generationConfig' => [
                            'temperature' => 0.1,
                            'maxOutputTokens' => 32768,
                            'responseMimeType' => 'application/json',
                        ],
                    ]);
            }
            return $requests;
        });
    }

    private function buildClusterSplitPrompt(string $batchText, string $bookLine, int $startIndex): string
    {
        return "Bạn là biên tập viên sách audio. Dưới đây là MỘT PHẦN transcript (đã trích theo đúng thứ tự) của cuốn sách.\n"
            . $bookLine . "\n\n"
            . "Nhiệm vụ: CHIA đoạn transcript này thành các CỤM SỰ KIỆN (nhóm sự kiện/tình tiết liên quan chặt chẽ, diễn ra liên tục hoặc cùng bối cảnh) theo ĐÚNG THỨ TỰ xuất hiện trong văn bản.\n"
            . "Yêu cầu bắt buộc:\n"
            . "- TUYỆT ĐỐI không tóm tắt, không diễn giải lại, không bỏ bớt câu chữ. Copy NGUYÊN VĂN transcript gốc vào trường content của từng cụm.\n"
            . "- Ghép đủ TOÀN BỘ đoạn transcript được đưa vào các cụm, không được bỏ sót bất kỳ câu nào.\n"
            . "- Không được lặp lại nội dung giữa các cụm.\n"
            . "- Mỗi cụm có 1 tiêu đề ngắn gọn (dưới 15 từ) mô tả sự kiện/giai đoạn chính của cụm.\n"
            . "- Đánh số index bắt đầu từ {$startIndex}, tăng dần 1 đơn vị theo đúng thứ tự xuất hiện.\n"
            . "Trả về STRICT JSON theo đúng format: {\"clusters\": [{\"index\": {$startIndex}, \"title\": \"...\", \"content\": \"...\"}]}\n"
            . "Không thêm giải thích, không dùng markdown.\n\n"
            . "TRANSCRIPT:\n" . $batchText;
    }

    /**
     * @return array<int,array{index:int,title:string,content:string}>
     */
    private function parseClusterSplitResponseText(string $text, int $startIndex, string $batchText): array
    {
        $decoded = $this->decodeJsonResponse($text);
        $rows = is_array($decoded['clusters'] ?? null) ? $decoded['clusters'] : [];
        $clusters = [];
        $index = $startIndex;

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $content = trim((string) ($row['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $clusters[] = [
                'index' => $index,
                'title' => trim((string) ($row['title'] ?? ('Cụm sự kiện ' . $index))),
                'content' => $content,
            ];
            $index++;
        }

        if (empty($clusters)) {
            // Fallback: keep the whole batch as a single cluster rather than losing content.
            $clusters[] = [
                'index' => $startIndex,
                'title' => 'Cụm sự kiện ' . $startIndex,
                'content' => trim($batchText),
            ];
        }

        return $clusters;
    }

    /**
     * @param mixed $response a pooled Http response, or an exception object if the request failed
     * @return array<int,array{index:int,title:string,content:string}>
     */
    private function parseClusterSplitPoolResponse(mixed $response, string $batchText): array
    {
        $fallback = [['index' => 1, 'title' => 'Cụm sự kiện', 'content' => trim($batchText)]];

        if (!is_object($response) || !method_exists($response, 'successful') || !$response->successful()) {
            return $fallback;
        }

        $finishReason = (string) data_get($response->json(), 'candidates.0.finishReason', '');
        $text = trim((string) data_get($response->json(), 'candidates.0.content.parts.0.text', ''));

        if ($finishReason === 'MAX_TOKENS' || $text === '') {
            return $fallback;
        }

        try {
            return $this->parseClusterSplitResponseText($text, 1, $batchText);
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    /**
     * Group the fine-grained event clusters produced by splitBatchIntoClusters() into a
     * smaller number of major "cụm thông tin chính" (e.g. 6-12), preserving order and
     * merging the original content verbatim so no text is lost.
     *
     * @param array<int,array{index:int,title:string,content:string}> $fineClusters
     * @return array<int,array{index:int,title:string,content:string}>
     */
    public function consolidateClusters(array $fineClusters, AudioBook $audioBook): array
    {
        $total = count($fineClusters);
        if ($total <= 1) {
            return array_values($fineClusters);
        }

        $byIndex = [];
        foreach ($fineClusters as $c) {
            $byIndex[(int) $c['index']] = $c;
        }

        $listText = collect($fineClusters)
            ->map(fn($c) => "[{$c['index']}] {$c['title']}")
            ->implode("\n");

        $bookLine = 'Sách: "' . $audioBook->title . '"' . ($audioBook->author ? ' - Tác giả: ' . $audioBook->author : '');

        $prompt = "Bạn là biên tập viên sách. Dưới đây là danh sách các cụm sự kiện chi tiết (đã đánh số theo đúng thứ tự xuất hiện) của một cuốn sách.\n"
            . $bookLine . "\n\n"
            . "Nhiệm vụ: NHÓM các cụm sự kiện chi tiết này lại thành khoảng 6-12 CỤM THÔNG TIN CHÍNH, mỗi cụm chính đại diện cho một giai đoạn/mạch truyện lớn của sách.\n"
            . "Yêu cầu bắt buộc:\n"
            . "- Mỗi cụm chính là một DẢI LIÊN TỤC các cụm chi tiết theo đúng thứ tự xuất hiện (start_index đến end_index), không được xáo trộn, không được bỏ sót cụm nào.\n"
            . "- Các dải không được chồng lấn, phải nối tiếp nhau: end_index của cụm này + 1 = start_index của cụm kế tiếp.\n"
            . "- Cụm chính đầu tiên bắt đầu từ index 1, cụm chính cuối cùng phải kết thúc ở index lớn nhất trong danh sách.\n"
            . "- Đặt tên ngắn gọn, khái quát cho mỗi cụm chính.\n"
            . "Trả về STRICT JSON theo format: {\"groups\": [{\"title\": \"...\", \"start_index\": 1, \"end_index\": 5}]}\n"
            . "Không thêm giải thích, không dùng markdown.\n\n"
            . "DANH SÁCH CỤM SỰ KIỆN CHI TIẾT (tổng {$total} cụm):\n" . $listText;

        try {
            $text = $this->callGemini($prompt, ['temperature' => 0.2, 'json' => true, 'max_output_tokens' => 8192]);
            $decoded = $this->decodeJsonResponse($text);
            $groups = is_array($decoded['groups'] ?? null) ? $decoded['groups'] : [];
            $ranges = $this->repairGroupRanges($groups, $total);
        } catch (\Throwable $e) {
            // Fallback: mechanically split into ~8 even groups rather than losing the grouping entirely.
            $ranges = $this->mechanicalGroupRanges($total, 8);
        }

        $result = [];
        $newIndex = 1;

        foreach ($ranges as $range) {
            $contentParts = [];
            for ($i = $range['start']; $i <= $range['end']; $i++) {
                if (isset($byIndex[$i])) {
                    $contentParts[] = $byIndex[$i]['content'];
                }
            }

            if (empty($contentParts)) {
                continue;
            }

            $result[] = [
                'index' => $newIndex,
                'title' => $range['title'] !== '' ? $range['title'] : ('Phần ' . $newIndex),
                'content' => implode("\n\n", $contentParts),
            ];
            $newIndex++;
        }

        return $result ?: array_values($fineClusters);
    }

    /**
     * Sort/clamp/fill Gemini's proposed grouping ranges so every fine cluster index from
     * 1..$total is covered exactly once by a contiguous, non-overlapping range.
     *
     * @param array<int,array<string,mixed>> $groups
     * @return array<int,array{title:string,start:int,end:int}>
     */
    private function repairGroupRanges(array $groups, int $total): array
    {
        $clean = [];
        foreach ($groups as $g) {
            if (!is_array($g)) {
                continue;
            }
            $start = (int) ($g['start_index'] ?? 0);
            $end = (int) ($g['end_index'] ?? 0);
            if ($start < 1 || $end < $start) {
                continue;
            }
            $clean[] = ['title' => trim((string) ($g['title'] ?? '')), 'start' => $start, 'end' => $end];
        }

        usort($clean, fn($a, $b) => $a['start'] <=> $b['start']);

        $repaired = [];
        $cursor = 1;

        foreach ($clean as $g) {
            $start = max($g['start'], $cursor);
            if ($start > $total) {
                break;
            }
            $end = min($g['end'], $total);
            if ($end < $start) {
                continue;
            }
            $repaired[] = ['title' => $g['title'] !== '' ? $g['title'] : ('Phần ' . (count($repaired) + 1)), 'start' => $start, 'end' => $end];
            $cursor = $end + 1;
        }

        if ($cursor <= $total) {
            $repaired[] = ['title' => 'Phần ' . (count($repaired) + 1), 'start' => $cursor, 'end' => $total];
        }

        return $repaired ?: $this->mechanicalGroupRanges($total, 8);
    }

    /**
     * @return array<int,array{title:string,start:int,end:int}>
     */
    private function mechanicalGroupRanges(int $total, int $targetGroups): array
    {
        $targetGroups = max(1, min($targetGroups, $total));
        $size = (int) ceil($total / $targetGroups);
        $ranges = [];

        for ($start = 1; $start <= $total; $start += $size) {
            $end = min($start + $size - 1, $total);
            $ranges[] = ['title' => 'Phần ' . (count($ranges) + 1), 'start' => $start, 'end' => $end];
        }

        return $ranges;
    }

    // A "cụm thông tin chính" (major cluster) can end up enormous for very long books — the
    // 6-12-clusters target in consolidateClusters() doesn't shrink cluster SIZE, only count.
    // A single Gemini call asked to enumerate every event across, say, 200k+ characters as a
    // structured timeline reliably produces truncated or malformed JSON well before hitting
    // an explicit MAX_TOKENS flag. Sub-batch large clusters the same way Bước 1 sub-batches
    // the whole book, then merge — the public method's signature/behavior is unchanged.
    private const TIMELINE_MAX_CHARS = 6000;

    /**
     * Step 2: build the full timeline table for one event cluster.
     *
     * @param array{index:int,title:string,content:string} $cluster
     * @return array<int,array{stt:int,characters:string,event:string,incident:string,consequence:string,importance:string}>
     */
    public function generateTimelineForCluster(array $cluster): array
    {
        $subBatches = $this->splitContentIntoSubBatches($cluster['content'], self::TIMELINE_MAX_CHARS);

        if (count($subBatches) === 1) {
            $prompt = $this->buildTimelinePrompt($cluster['title'], $subBatches[0]);
            $text = $this->callGemini($prompt, ['temperature' => 0.2, 'json' => true, 'max_output_tokens' => 32768]);
            return $this->renumberTimelineRows($this->parseTimelineResponseText($text));
        }

        $apiKey = trim((string) config('services.gemini.api_key', '')) ?: trim((string) config('services.gemini.tts_api_key', ''));
        if ($apiKey === '') {
            throw new \RuntimeException('Thiếu GEMINI_API_KEY / GEMINI_TTS_API_KEY trong cấu hình runtime.');
        }

        $allRows = [];

        foreach (array_chunk($subBatches, self::POOL_CONCURRENCY, true) as $chunk) {
            $responses = $this->poolTimelineRequests($chunk, $cluster['title'], $apiKey);

            $rateLimited = array_filter($chunk, fn($text, $i) => ($responses[$i] ?? null) instanceof \Illuminate\Http\Client\Response
                && $responses[$i]->status() === 429, ARRAY_FILTER_USE_BOTH);

            if (!empty($rateLimited)) {
                sleep(15);
                $responses = array_replace($responses, $this->poolTimelineRequests($rateLimited, $cluster['title'], $apiKey));
            }

            foreach ($chunk as $i => $subText) {
                $allRows = array_merge($allRows, $this->parseTimelinePoolResponse($responses[$i] ?? null, $subText, $cluster['title']));
            }
        }

        return $this->renumberTimelineRows($allRows);
    }

    /**
     * @param array<int,string> $chunk keyed by original sub-batch index
     * @return array<int,mixed> pooled Http responses keyed the same as $chunk
     */
    private function poolTimelineRequests(array $chunk, string $clusterTitle, string $apiKey): array
    {
        return Http::pool(function (Pool $pool) use ($chunk, $clusterTitle, $apiKey) {
            $requests = [];
            foreach ($chunk as $i => $subText) {
                $prompt = $this->buildTimelinePrompt($clusterTitle, $subText);
                $requests[] = $pool->as((string) $i)
                    ->acceptJson()
                    ->connectTimeout(15)
                    ->timeout(180)
                    ->post("https://generativelanguage.googleapis.com/v1beta/models/" . self::MODEL . ":generateContent?key={$apiKey}", [
                        'contents' => [['parts' => [['text' => $prompt]]]],
                        'generationConfig' => [
                            'temperature' => 0.2,
                            'maxOutputTokens' => 32768,
                            'responseMimeType' => 'application/json',
                        ],
                    ]);
            }
            return $requests;
        });
    }

    private function buildTimelinePrompt(string $clusterTitle, string $content): string
    {
        return "Bạn là biên tập viên phân tích truyện. Dưới đây là MỘT PHẦN của một cụm sự kiện (trích nguyên văn từ transcript gốc), tiêu đề cụm: \"{$clusterTitle}\".\n"
            . "Nhiệm vụ: Lập BẢNG TIMELINE đầy đủ cho TOÀN BỘ đoạn nội dung này, liệt kê MỌI sự kiện/tình tiết xuất hiện, theo đúng thứ tự xuất hiện, không bỏ sót.\n"
            . "Với mỗi dòng, xác định:\n"
            . "- stt: số thứ tự tăng dần bắt đầu từ 1\n"
            . "- characters: (các) nhân vật liên quan\n"
            . "- event: sự kiện diễn ra\n"
            . "- incident: biến cố xuất hiện (để chuỗi rỗng nếu không có biến cố nổi bật)\n"
            . "- consequence: hệ quả của sự kiện/biến cố\n"
            . "- importance: mức độ quan trọng, CHỈ được chọn đúng 1 trong 3 giá trị: \"Bắt buộc giữ\", \"Có thể rút gọn\", \"Có thể bỏ\"\n"
            . "Trả về STRICT JSON theo format: {\"timeline\": [{\"stt\":1,\"characters\":\"...\",\"event\":\"...\",\"incident\":\"...\",\"consequence\":\"...\",\"importance\":\"Bắt buộc giữ\"}]}\n"
            . "Không thêm giải thích, không dùng markdown.\n\n"
            . "NỘI DUNG:\n" . $content;
    }

    /**
     * @return array<int,array{characters:string,event:string,incident:string,consequence:string,importance:string}>
     */
    private function parseTimelineResponseText(string $text): array
    {
        $decoded = $this->decodeJsonResponse($text);
        $rows = is_array($decoded['timeline'] ?? null) ? $decoded['timeline'] : [];
        $result = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $importance = trim((string) ($row['importance'] ?? ''));
            if (!in_array($importance, ['Bắt buộc giữ', 'Có thể rút gọn', 'Có thể bỏ'], true)) {
                $importance = 'Bắt buộc giữ';
            }
            $result[] = [
                'characters' => trim((string) ($row['characters'] ?? '')),
                'event' => trim((string) ($row['event'] ?? '')),
                'incident' => trim((string) ($row['incident'] ?? '')),
                'consequence' => trim((string) ($row['consequence'] ?? '')),
                'importance' => $importance,
            ];
        }

        return $result;
    }

    /**
     * @param mixed $response a pooled Http response, or an exception object if the request failed
     * @return array<int,array{characters:string,event:string,incident:string,consequence:string,importance:string}>
     */
    private function parseTimelinePoolResponse(mixed $response, string $subText, string $clusterTitle): array
    {
        // Fallback keeps the sub-batch's content traceable rather than silently dropping it —
        // a single coarse "Bắt buộc giữ" row beats losing the events in this chunk entirely.
        $fallback = [[
            'characters' => '',
            'event' => mb_substr(trim($subText), 0, 200),
            'incident' => '',
            'consequence' => '',
            'importance' => 'Bắt buộc giữ',
        ]];

        if (!is_object($response) || !method_exists($response, 'successful') || !$response->successful()) {
            return $fallback;
        }

        $finishReason = (string) data_get($response->json(), 'candidates.0.finishReason', '');
        $text = trim((string) data_get($response->json(), 'candidates.0.content.parts.0.text', ''));

        if ($finishReason === 'MAX_TOKENS' || $text === '') {
            return $fallback;
        }

        try {
            $rows = $this->parseTimelineResponseText($text);
            return empty($rows) ? $fallback : $rows;
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    /**
     * @param array<int,array{characters:string,event:string,incident:string,consequence:string,importance:string}> $rows
     * @return array<int,array{stt:int,characters:string,event:string,incident:string,consequence:string,importance:string}>
     */
    private function renumberTimelineRows(array $rows): array
    {
        $timeline = [];
        $stt = 1;
        foreach ($rows as $row) {
            $timeline[] = array_merge(['stt' => $stt], $row);
            $stt++;
        }
        return $timeline;
    }

    /**
     * Split arbitrary text into ~$maxChars pieces on paragraph boundaries (falling back to
     * sentence boundaries for a single huge paragraph) — generic version of the chapter-aware
     * splitting in buildBatches(), for splitting one cluster's already-extracted content.
     *
     * @return array<int,string>
     */
    private function splitContentIntoSubBatches(string $content, int $maxChars): array
    {
        $content = trim($content);
        if ($content === '') {
            return [''];
        }
        if (mb_strlen($content) <= $maxChars) {
            return [$content];
        }

        $paragraphs = preg_split('/\r?\n+/u', $content) ?: [$content];
        $batches = [];
        $current = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            // A single paragraph longer than the whole budget still needs splitting further
            // (rare, but historical-annal-style texts can have very long unbroken passages).
            if (mb_strlen($paragraph) > $maxChars) {
                if ($current !== '') {
                    $batches[] = trim($current);
                    $current = '';
                }
                $sentences = preg_split('/(?<=[.!?…])\s+/u', $paragraph) ?: [$paragraph];
                foreach ($sentences as $sentence) {
                    $piece = ($current === '' ? '' : ' ') . $sentence;
                    if ($current !== '' && mb_strlen($current . $piece) > $maxChars) {
                        $batches[] = trim($current);
                        $current = '';
                        $piece = $sentence;
                    }
                    $current .= $piece;
                }
                continue;
            }

            $piece = ($current === '' ? '' : "\n\n") . $paragraph;
            if ($current !== '' && mb_strlen($current . $piece) > $maxChars) {
                $batches[] = trim($current);
                $current = '';
                $piece = $paragraph;
            }
            $current .= $piece;
        }

        if (trim($current) !== '') {
            $batches[] = trim($current);
        }

        return $batches ?: [$content];
    }

    /**
     * Step 3: retell the story for one cluster, guided by its timeline table.
     *
     * @param array{index:int,title:string,content:string} $cluster
     * @param array<int,array<string,mixed>> $timelineRows
     */
    public function generateRetellForCluster(
        AudioBook $audioBook,
        array $cluster,
        array $timelineRows,
        bool $isFirstCluster,
        float $ratioMin = 0.25,
        float $ratioMax = 0.40
    ): string {
        $introInstruction = $isFirstCluster
            ? "Vì đây là PHẦN MỞ ĐẦU, hãy viết thêm đoạn giới thiệu nhanh (intro) về cuốn sách đang review: tên sách \"{$audioBook->title}\""
                . ($audioBook->author ? " và tác giả {$audioBook->author}" : '') . ", trước khi vào nội dung câu chuyện.\n"
            : "Đây KHÔNG PHẢI phần mở đầu, KHÔNG được viết lại phần giới thiệu sách/tác giả, vào thẳng nội dung câu chuyện.\n";

        $originalLength = mb_strlen($cluster['content']);
        $targetMin = max(1, (int) round($originalLength * $ratioMin));
        $targetMax = max($targetMin + 1, (int) round($originalLength * $ratioMax));
        $pctMin = (int) round($ratioMin * 100);
        $pctMax = (int) round($ratioMax * 100);

        $prompt = "Bạn là biên kịch viết lại nội dung sách thành lời KỂ LẠI RÚT GỌN cho video, nội dung này sẽ được chuyển sang TTS để đọc thành audio.\n"
            . "Nhiệm vụ: KỂ LẠI (không phải sao chép/diễn giải nguyên văn) câu chuyện dựa trên TÀI LIỆU GỐC bên dưới, theo đúng định hướng của BẢNG TIMELINE đã lập cho cụm sự kiện này.\n"
            . $introInstruction
            . "\nYêu cầu bắt buộc về ĐỘ DÀI (quan trọng nhất):\n"
            . "- Tài liệu gốc dài khoảng {$originalLength} ký tự. Bài kể lại PHẢI súc tích, chỉ dài khoảng {$targetMin}-{$targetMax} ký tự (tương đương {$pctMin}-{$pctMax}% độ dài gốc). KHÔNG được viết dài hơn mức này.\n"
            . "- Để đạt độ dài này: lược bỏ mô tả rườm rà, hội thoại phụ, chi tiết không ảnh hưởng cốt truyện; gộp nhiều câu/đoạn nhỏ lẻ thành câu súc tích; kể tóm lược thay vì thuật lại từng câu.\n"
            . "\nYêu cầu bắt buộc khác:\n"
            . "- Số liệu: không dùng dấu chấm phân cách hàng nghìn (ví dụ viết 12000 thay vì 12.000).\n"
            . "- Địa danh có tên gọi thời xưa: có thể chú thích tên gọi ngày nay theo dạng \"nay là vùng xxx\".\n"
            . "- Đơn vị đo lường kiểu xưa hoặc kiểu Anh/Mỹ: chuyển sang đơn vị đo lường phổ biến người Việt dễ hiểu (mét, km, kg...).\n"
            . "- Tên riêng tiếng Anh: chuyển sang phiên âm tiếng Việt để dễ đọc khi lồng tiếng.\n"
            . ($ratioMax <= 0.15
                ? "- Vì đây là mức tóm tắt RẤT NGẮN, được phép LƯỢC BỎ nhân vật phụ và sự kiện phụ (kể cả một số dòng \"Bắt buộc giữ\" ít quan trọng nhất) để đảm bảo đúng độ dài mục tiêu, miễn là giữ được mạch truyện chính, nhân vật chính và cái kết không bị đứt gãy.\n"
                : "- Giữ đầy đủ DIỄN BIẾN CỐT LÕI: không được tự ý bỏ nhân vật hoặc sự kiện nào chưa được đánh dấu \"Có thể bỏ\" trong bảng timeline. Nhưng \"giữ\" ở đây là giữ Ý và mạch truyện, KHÔNG phải giữ nguyên văn từng câu chữ hay độ dài gốc.\n")
            . "- Các dòng timeline mức \"Bắt buộc giữ\" phải xuất hiện trong bài kể nhưng viết CÔ ĐỌNG (1-2 câu súc tích/sự kiện là đủ, không cần thuật chi tiết). Dòng \"Có thể rút gọn\" chỉ nên tóm trong 1 câu ngắn. Dòng \"Có thể bỏ\" nên lược bỏ hẳn để tiết kiệm dung lượng.\n"
            . "- Được sắp xếp lại câu chữ, cô đọng lại cách diễn đạt cho mạch lạc, tự nhiên khi đọc thành lời, nhưng KHÔNG được làm mất hoặc thay đổi diễn biến/nhân vật/nguyên nhân-kết quả so với tài liệu gốc.\n"
            . "- Đây là nội dung sẽ chuyển thành audio (TTS), CHỈ viết nội dung kể chuyện thuần túy, TUYỆT ĐỐI không thêm ghi chú, ký hiệu, tiêu đề phần, chú thích trong ngoặc, hướng dẫn dàn dựng.\n"
            . "- Viết bằng tiếng Việt, giọng văn kể chuyện lôi cuốn, mạch lạc, súc tích.\n"
            . "\nBẢNG TIMELINE ĐỊNH HƯỚNG (JSON):\n" . json_encode($timelineRows, JSON_UNESCAPED_UNICODE)
            . "\n\nTÀI LIỆU GỐC CỦA CỤM SỰ KIỆN NÀY ({$originalLength} ký tự):\n" . $cluster['content']
            . "\n\nHãy viết lại bài kể chuyện RÚT GỌN (khoảng {$targetMin}-{$targetMax} ký tự) cho cụm sự kiện này.";

        return $this->claudeService->complete($prompt, ['max_tokens' => 8192]);
    }

    public function generateOutro(AudioBook $audioBook, string $lastRetellText): string
    {
        $tailContext = mb_substr($lastRetellText, max(0, mb_strlen($lastRetellText) - 2000));

        $prompt = "Bạn là biên kịch viết đoạn outro (kết thúc) cho video kể chuyện sách \"{$audioBook->title}\""
            . ($audioBook->author ? " của tác giả {$audioBook->author}" : '') . ".\n"
            . "Đây là đoạn xuất hiện SAU KHI đã kể xong toàn bộ nội dung câu chuyện.\n"
            . "Yêu cầu:\n"
            . "- Viết đoạn outro ngắn gọn (khoảng 3-6 câu), khép lại cảm xúc câu chuyện, cảm ơn người xem đã theo dõi, mời like/subscribe/để lại bình luận kênh.\n"
            . "- Đây là nội dung sẽ chuyển thành audio (TTS), CHỈ viết nội dung lời thoại thuần túy, không thêm ghi chú, ký hiệu, hướng dẫn dàn dựng.\n"
            . "- Viết bằng tiếng Việt, giọng văn gần gũi, tự nhiên.\n"
            . "\nĐoạn cuối cùng của câu chuyện (để outro liền mạch):\n" . $tailContext;

        return $this->claudeService->complete($prompt, ['max_tokens' => 2048]);
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

        if (isset($options['thinking_budget'])) {
            $generationConfig['thinkingConfig'] = ['thinkingBudget' => (int) $options['thinking_budget']];
        }

        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
        $body = ['contents' => [['parts' => [['text' => $prompt]]]], 'generationConfig' => $generationConfig];

        // Gemini enforces a SPEND-based rate limit (not just request-count), so a burst of
        // calls (e.g. the pooled Bước 1 splitter) can leave the account rate-limited for a
        // short window afterward even for a single, unrelated call. Retry 429s with growing
        // backoff instead of failing the whole step immediately — the window is transient.
        $maxAttempts = 4;
        $response = null;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $response = Http::acceptJson()
                ->connectTimeout(15)
                ->timeout($options['timeout'] ?? 180)
                ->post($url, $body);

            if ($response->successful() || $response->status() !== 429 || $attempt === $maxAttempts) {
                break;
            }

            sleep($attempt * 10); // 10s, 20s, 30s
        }

        if (!$response->successful()) {
            $shortBody = mb_substr(trim((string) $response->body()), 0, 500);
            throw new \RuntimeException('Gemini API lỗi HTTP ' . $response->status() . ($shortBody !== '' ? (': ' . $shortBody) : ''));
        }

        $finishReason = (string) data_get($response->json(), 'candidates.0.finishReason', '');
        $text = trim((string) data_get($response->json(), 'candidates.0.content.parts.0.text', ''));

        if ($finishReason === 'MAX_TOKENS') {
            throw new \RuntimeException('Gemini bị cắt nội dung do vượt giới hạn token đầu ra (finishReason: MAX_TOKENS). Đoạn nội dung có thể quá dài, thử lại hoặc chia nhỏ hơn.');
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
