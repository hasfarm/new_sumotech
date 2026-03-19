<?php

namespace App\Services;

use App\Models\AudioBook;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Service for generating 15-minute book review videos.
 *
 * Pipeline:
 * 1. Summarize each chapter using Gemini AI
 * 2. Compose a cohesive review script (~3000-4000 words)
 * 3. AI chunks the script into visual segments with image prompts
 * 4. Generate images for each segment
 * 5. Generate TTS audio for each segment
 * 6. Generate SRT subtitles for each segment
 * 7. Compose final video with transitions, subtitles, intro/outro music
 */
class BookReviewVideoService
{
    private GeminiImageService $imageService;
    private StableDiffusionService $sdService;
    private TTSService $ttsService;
    private string $ffmpegPath;
    private string $ffprobePath;

    public function __construct(
        GeminiImageService $imageService,
        StableDiffusionService $sdService,
        TTSService $ttsService
    ) {
        $this->imageService = $imageService;
        $this->sdService = $sdService;
        $this->ttsService = $ttsService;
        $this->ffmpegPath = config('services.ffmpeg.path', 'ffmpeg');
        $this->ffprobePath = config('services.ffmpeg.ffprobe_path', 'ffprobe');
    }

    public function getWorkDir(int $bookId): string
    {
        $dir = storage_path('app/public/books/' . $bookId . '/review_video');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    public function getChunksPath(int $bookId): string
    {
        return $this->getWorkDir($bookId) . '/chunks.json';
    }

    public function loadChunks(int $bookId): ?array
    {
        $path = $this->getChunksPath($bookId);
        if (file_exists($path)) {
            return json_decode(file_get_contents($path), true);
        }
        return null;
    }

    public function saveChunks(int $bookId, array $chunks): void
    {
        $path = $this->getChunksPath($bookId);
        file_put_contents($path, json_encode($chunks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    // ========== STAGE 1: SUMMARIZE + GENERATE REVIEW SCRIPT ==========

    /**
     * Summarize all chapters and generate a review script.
     */
    public function generateReviewScript(AudioBook $audioBook, callable $onProgress = null): string
    {
        $chapters = $audioBook->chapters()->orderBy('chapter_number')->get();
        if ($chapters->isEmpty()) {
            throw new \Exception('Sách chưa có chương nào.');
        }

        $chaptersWithContent = $chapters->filter(fn($ch) => !empty($ch->content));
        if ($chaptersWithContent->isEmpty()) {
            throw new \Exception('Không có chương nào có nội dung.');
        }

        $apiKey = config('services.gemini.api_key');
        if (!$apiKey) {
            throw new \Exception('Chưa cấu hình GEMINI_API_KEY.');
        }

        // Phase 1: Summarize each chapter
        $summaries = [];
        $total = $chaptersWithContent->count();
        $current = 0;

        foreach ($chaptersWithContent as $chapter) {
            $current++;
            if ($onProgress) {
                $onProgress("Đang tóm tắt chương {$current}/{$total}: {$chapter->title}...", $current, $total);
            }

            $content = $chapter->content;
            // If chapter is very long, truncate to ~8000 chars for summarization
            if (mb_strlen($content) > 8000) {
                $content = mb_substr($content, 0, 4000) . "\n\n[...]\n\n" . mb_substr($content, -4000);
            }

            $summary = $this->summarizeChapter(
                $apiKey,
                $audioBook->title,
                $audioBook->author ?? '',
                $audioBook->category ?? '',
                $chapter->chapter_number,
                $chapter->title ?? "Chương {$chapter->chapter_number}",
                $content
            );

            $summaries[] = [
                'chapter_number' => $chapter->chapter_number,
                'title' => $chapter->title,
                'summary' => $summary
            ];

            // Rate limit: 4s delay between API calls (Gemini free tier: 15 req/min)
            sleep(4);
        }

        // Save intermediate summaries
        $workDir = $this->getWorkDir($audioBook->id);
        file_put_contents(
            $workDir . '/chapter_summaries.json',
            json_encode($summaries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        if ($onProgress) {
            $onProgress('Đang viết kịch bản review từ tóm tắt...', $total, $total);
        }

        // Phase 2: Compose review script from summaries
        $combinedSummaries = '';
        foreach ($summaries as $s) {
            $combinedSummaries .= "--- Chương {$s['chapter_number']}: {$s['title']} ---\n{$s['summary']}\n\n";
        }

        $script = $this->composeReviewScript(
            $apiKey,
            $audioBook->title,
            $audioBook->author ?? '',
            $audioBook->category ?? '',
            $audioBook->book_type ?? '',
            $total,
            $combinedSummaries,
            $audioBook->youtubeChannel->title ?? ''
        );

        // Save script
        file_put_contents($workDir . '/review_script.txt', $script);

        // Save to DB
        $audioBook->update(['review_script' => $script]);

        return $script;
    }

    private function summarizeChapter(
        string $apiKey,
        string $bookTitle,
        string $author,
        string $category,
        int $chapterNumber,
        string $chapterTitle,
        string $content
    ): string {
        $prompt = "Bạn là chuyên gia phân tích và tóm tắt sách.\n\n";
        $prompt .= "NHIỆM VỤ: Tóm tắt nội dung chương sau đây của sách \"{$bookTitle}\"";
        if ($author) $prompt .= " (tác giả: {$author})";
        if ($category) $prompt .= " (thể loại: {$category})";
        $prompt .= ".\n\n";
        $prompt .= "QUY TẮC:\n";
        $prompt .= "1. Tóm tắt TRUNG THÀNH với nội dung gốc\n";
        $prompt .= "2. Giữ lại các nhân vật chính, sự kiện quan trọng, chi tiết đặc sắc\n";
        $prompt .= "3. Độ dài: 300-500 từ tiếng Việt\n";
        $prompt .= "4. Viết dạng văn xuôi, không dùng bullet points\n\n";
        $prompt .= "CHƯƠNG {$chapterNumber}: {$chapterTitle}\n";
        $prompt .= "---\n{$content}\n---\n\n";
        $prompt .= "Trả về bản tóm tắt thuần, không giải thích thêm.";

        return $this->callGemini($apiKey, $prompt, 0.3, 2048);
    }

    private function composeReviewScript(
        string $apiKey,
        string $bookTitle,
        string $author,
        string $category,
        string $bookType,
        int $totalChapters,
        string $combinedSummaries,
        string $channelName
    ): string {
        $prompt = "Bạn là người dẫn chương trình review sách YouTube chuyên nghiệp, am hiểu văn học.\n\n";
        $prompt .= "NHIỆM VỤ: Từ các bản tóm tắt chương dưới đây, viết một KỊCH BẢN REVIEW SÁCH hoàn chỉnh để đọc trong ~15 phút (~3000-4000 từ tiếng Việt).\n\n";
        $prompt .= "THÔNG TIN SÁCH:\n";
        $prompt .= "- Tên: {$bookTitle}\n";
        if ($author) $prompt .= "- Tác giả: {$author}\n";
        if ($category) $prompt .= "- Thể loại: {$category}\n";
        if ($bookType) $prompt .= "- Loại: {$bookType}\n";
        $prompt .= "- Số chương: {$totalChapters}\n";
        if ($channelName) $prompt .= "- Kênh YouTube: {$channelName}\n";

        $prompt .= "\nTÓM TẮT TỪNG CHƯƠNG:\n{$combinedSummaries}\n\n";

        $prompt .= "CẤU TRÚC KỊCH BẢN (viết liền mạch, tự nhiên):\n\n";
        $prompt .= "1. MỞ ĐẦU (300-400 từ): Lời chào khán giả thân mật, giới thiệu sách và tác giả, lý do nên đọc/nghe\n";
        $prompt .= "2. TỔNG QUAN NỘI DUNG (400-500 từ): Bối cảnh, chủ đề chính, cốt truyện tổng quát\n";
        $prompt .= "3. PHÂN TÍCH NHÂN VẬT (500-600 từ): Các nhân vật chính, tính cách, sự phát triển\n";
        $prompt .= "4. ĐIỂM NHẤN & CÁC CHƯƠNG HAY (800-1000 từ): Những khoảnh khắc ấn tượng nhất, chi tiết đặc sắc\n";
        $prompt .= "5. CHỦ ĐỀ & THÔNG ĐIỆP (400-500 từ): Ý nghĩa sâu sắc, bài học rút ra\n";
        $prompt .= "6. KẾT LUẬN & KHUYẾN NGHỊ (300-400 từ): Đánh giá tổng thể, ai nên đọc, lời kêu gọi đăng ký kênh\n\n";

        $prompt .= "PHONG CÁCH:\n";
        $prompt .= "- Viết như đang nói chuyện với khán giả, thân mật, có cảm xúc\n";
        $prompt .= "- Ngôi thứ nhất \"tôi\"\n";
        $prompt .= "- KHÔNG dùng emoji, không dùng markdown\n";
        $prompt .= "- KHÔNG spoil kết thúc\n";
        $prompt .= "- Tạo cảm giác hấp dẫn, lôi cuốn để người nghe muốn đọc sách\n";
        $prompt .= "- KHÔNG bịa thông tin sai\n\n";
        $prompt .= "Chỉ trả về kịch bản, không giải thích thêm.";

        return $this->callGemini($apiKey, $prompt, 0.7, 8192);
    }

    // ========== STAGE 2: CHUNK REVIEW SCRIPT ==========

    public function chunkReviewScript(AudioBook $audioBook, string $script): array
    {
        $apiKey = config('services.gemini.api_key');
        if (!$apiKey) {
            $chunks = $this->fallbackChunk($script);
            $this->saveChunks($audioBook->id, $chunks);
            return $chunks;
        }

        $prompt = "Bạn là chuyên gia tạo storyboard video.\n\n";
        $prompt .= "NHIỆM VỤ: Chia kịch bản review sách sau thành các SEGMENT (đoạn) để tạo video.\n\n";
        $prompt .= "QUY TẮC:\n";
        $prompt .= "1. KHÔNG thay đổi nội dung gốc - mỗi segment phải là NGUYÊN VĂN một phần kịch bản\n";
        $prompt .= "2. Các segment nối lại phải tạo thành TOÀN BỘ kịch bản (không thiếu, không thừa)\n";
        $prompt .= "3. Mỗi segment dài 2-5 câu (khoảng 30-60 giây khi đọc)\n";
        $prompt .= "4. Chia theo ý nghĩa, không cắt giữa câu\n";
        $prompt .= "5. Mỗi segment tạo MÔ TẢ HÌNH ẢNH (image_prompt) bằng tiếng Anh\n";
        $prompt .= "6. Số lượng segment: 15-25 segments cho ~15 phút video\n\n";

        $prompt .= "SÁCH: {$audioBook->title}\n\n";
        $prompt .= "KỊCH BẢN:\n---\n{$script}\n---\n\n";

        $prompt .= "OUTPUT FORMAT (JSON thuần, KHÔNG markdown):\n";
        $prompt .= "[\n  {\n    \"chunk_index\": 0,\n    \"text\": \"Nguyên văn đoạn text\",\n";
        $prompt .= "    \"image_prompt\": \"Detailed English prompt for cinematic illustration. Include scene, mood, colors, style.\"\n  }\n]\n\n";
        $prompt .= "CHÚ Ý: Trường 'text' phải là COPY CHÍNH XÁC từ kịch bản, không thêm bớt ký tự nào.";

        try {
            $responseText = $this->callGemini($apiKey, $prompt, 0.3, 8192);
            $chunks = $this->parseChunkResponse($responseText);

            if (!empty($chunks)) {
                Log::info("AI chunked review script into " . count($chunks) . " segments");
                $this->saveChunks($audioBook->id, $chunks);
                return $chunks;
            }
        } catch (\Exception $e) {
            Log::error("AI chunking review script error: " . $e->getMessage());
        }

        Log::warning("AI chunking failed, using fallback");
        $chunks = $this->fallbackChunk($script);
        $this->saveChunks($audioBook->id, $chunks);
        return $chunks;
    }

    private function parseChunkResponse(string $responseText): array
    {
        $cleaned = trim($responseText);
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned);
        $cleaned = preg_replace('/\s*```$/i', '', $cleaned);

        $parsed = json_decode($cleaned, true);
        if (!is_array($parsed) || empty($parsed)) {
            return [];
        }

        $chunks = [];
        foreach ($parsed as $i => $item) {
            if (empty($item['text'])) continue;
            $chunks[] = [
                'chunk_index' => $i,
                'text' => trim($item['text']),
                'image_prompt' => $item['image_prompt'] ?? '',
                'image_path' => null,
                'audio_path' => null,
                'audio_duration' => null,
                'srt_path' => null
            ];
        }

        return $chunks;
    }

    private function fallbackChunk(string $script): array
    {
        $paragraphs = preg_split('/\n\s*\n/', trim($script));
        $paragraphs = array_filter($paragraphs, fn($p) => mb_strlen(trim($p)) > 10);
        $paragraphs = array_values($paragraphs);

        // If too few paragraphs, group sentences
        if (count($paragraphs) <= 5) {
            $sentences = preg_split('/(?<=[.!?。])\s+/u', trim($script));
            $sentences = array_filter($sentences, fn($s) => mb_strlen(trim($s)) > 5);
            $sentences = array_values($sentences);

            $chunks = [];
            $buffer = '';
            $count = 0;
            foreach ($sentences as $sentence) {
                $buffer .= ($buffer ? ' ' : '') . trim($sentence);
                $count++;
                if ($count >= 3) {
                    $chunks[] = $buffer;
                    $buffer = '';
                    $count = 0;
                }
            }
            if ($buffer) $chunks[] = $buffer;
            $paragraphs = $chunks;
        }

        // If too many, merge small paragraphs
        if (count($paragraphs) > 30) {
            $merged = [];
            $buffer = '';
            foreach ($paragraphs as $p) {
                $buffer .= ($buffer ? "\n\n" : '') . trim($p);
                if (mb_strlen($buffer) > 500) {
                    $merged[] = $buffer;
                    $buffer = '';
                }
            }
            if ($buffer) $merged[] = $buffer;
            $paragraphs = $merged;
        }

        $result = [];
        foreach ($paragraphs as $i => $text) {
            $result[] = [
                'chunk_index' => $i,
                'text' => trim($text),
                'image_prompt' => 'A cinematic illustration for a book review video segment: ' . mb_substr(trim($text), 0, 200),
                'image_path' => null,
                'audio_path' => null,
                'audio_duration' => null,
                'srt_path' => null
            ];
        }

        return $result;
    }

    // ========== STAGE 3: GENERATE IMAGES ==========

    /**
     * @param string $imageProvider 'gemini' or 'flux'
     */
    public function generateChunkImage(int $bookId, int $chunkIndex, string $prompt, string $imageProvider = 'gemini'): array
    {
        $workDir = $this->getWorkDir($bookId);
        $imagesDir = $workDir . '/images';
        if (!is_dir($imagesDir)) {
            mkdir($imagesDir, 0755, true);
        }

        $filename = "chunk_{$chunkIndex}.png";
        $outputPath = $imagesDir . '/' . $filename;

        if (file_exists($outputPath)) {
            unlink($outputPath);
        }

        $normalizedProvider = strtolower(trim($imageProvider)) === 'flux' ? 'flux' : 'gemini';
        $result = $this->imageService->generateImage($prompt, $outputPath, '16:9', $normalizedProvider);

        if ($result['success']) {
            $chunks = $this->loadChunks($bookId);
            if ($chunks && isset($chunks[$chunkIndex])) {
                $chunks[$chunkIndex]['image_path'] = $outputPath;
                $chunks[$chunkIndex]['image_provider'] = $normalizedProvider;
                $this->saveChunks($bookId, $chunks);
            }
            return ['success' => true, 'image_path' => $outputPath];
        }

        return $result;
    }

    // ========== STAGE 4: GENERATE TTS ==========

    public function generateChunkTts(
        int $bookId,
        int $chunkIndex,
        string $text,
        string $provider,
        string $voiceName,
        string $voiceGender = 'female',
        ?string $styleInstruction = null
    ): array {
        $workDir = $this->getWorkDir($bookId);
        $audioDir = $workDir . '/audio';
        if (!is_dir($audioDir)) {
            mkdir($audioDir, 0755, true);
        }

        if (in_array(strtolower($provider), ['microsoft', 'openai'])) {
            $styleInstruction = null;
        }

        $tempPath = $this->ttsService->generateAudio(
            $text, $chunkIndex, $voiceGender, $voiceName, $provider, $styleInstruction, null
        );

        $filename = "chunk_{$chunkIndex}.mp3";
        $outputPath = $audioDir . '/' . $filename;

        $sourcePath = storage_path('app/' . $tempPath);
        if (file_exists($sourcePath)) {
            if (file_exists($outputPath)) unlink($outputPath);
            copy($sourcePath, $outputPath);
            unlink($sourcePath);
        } else {
            throw new \Exception("TTS output file not found: {$sourcePath}");
        }

        $duration = $this->getAudioDuration($outputPath);

        $chunks = $this->loadChunks($bookId);
        if ($chunks && isset($chunks[$chunkIndex])) {
            $chunks[$chunkIndex]['audio_path'] = $outputPath;
            $chunks[$chunkIndex]['audio_duration'] = $duration;
            $this->saveChunks($bookId, $chunks);
        }

        return ['success' => true, 'audio_path' => $outputPath, 'duration' => $duration];
    }

    // ========== STAGE 5: GENERATE SRT ==========

    public function generateChunkSrt(int $bookId, int $chunkIndex): array
    {
        $chunks = $this->loadChunks($bookId);
        if (!$chunks || !isset($chunks[$chunkIndex])) {
            throw new \Exception("Chunk {$chunkIndex} không tồn tại.");
        }

        $chunk = $chunks[$chunkIndex];
        $text = $chunk['text'];
        $audioDuration = $chunk['audio_duration'] ?? null;

        if (!$audioDuration && !empty($chunk['audio_path']) && file_exists($chunk['audio_path'])) {
            $audioDuration = $this->getAudioDuration($chunk['audio_path']);
        }
        if (!$audioDuration) {
            throw new \Exception("Chunk {$chunkIndex} chưa có audio.");
        }

        $sentences = preg_split('/(?<=[.!?。…])\s+/u', trim($text));
        $sentences = array_filter($sentences, fn($s) => mb_strlen(trim($s)) > 0);
        $sentences = array_values($sentences);

        // Split long sentences into subtitle segments (max ~90 chars = 2 lines x 45 chars)
        $maxSubChars = 90;
        $subSegments = [];
        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if (mb_strlen($sentence) <= $maxSubChars) {
                $subSegments[] = $this->wrapSubtitleText($sentence, 45);
            } else {
                // Split at comma, semicolon, or word boundary
                $parts = $this->splitLongSentence($sentence, $maxSubChars);
                foreach ($parts as $part) {
                    $subSegments[] = $this->wrapSubtitleText(trim($part), 45);
                }
            }
        }

        $totalChars = array_sum(array_map(fn($s) => mb_strlen(str_replace("\n", '', $s)), $subSegments));
        if ($totalChars === 0) $totalChars = 1;

        $srtEntries = [];
        $currentTime = 0.0;

        foreach ($subSegments as $i => $segment) {
            $segChars = mb_strlen(str_replace("\n", '', $segment));
            $duration = max(0.5, $audioDuration * ($segChars / $totalChars));
            $srtEntries[] = [
                'index' => $i + 1,
                'start' => $this->secondsToSrtTime($currentTime),
                'end' => $this->secondsToSrtTime($currentTime + $duration),
                'text' => $segment
            ];
            $currentTime += $duration;
        }

        // Normalize to exact audio duration
        if (!empty($srtEntries) && $currentTime > 0) {
            $scale = $audioDuration / $currentTime;
            $adjustedTime = 0.0;
            foreach ($srtEntries as &$entry) {
                $entryDuration = ($this->srtTimeToSeconds($entry['end']) - $this->srtTimeToSeconds($entry['start'])) * $scale;
                $entry['start'] = $this->secondsToSrtTime($adjustedTime);
                $adjustedTime += $entryDuration;
                $entry['end'] = $this->secondsToSrtTime($adjustedTime);
            }
        }

        $workDir = $this->getWorkDir($bookId);
        $srtDir = $workDir . '/srt';
        if (!is_dir($srtDir)) mkdir($srtDir, 0755, true);

        $srtPath = $srtDir . "/chunk_{$chunkIndex}.srt";
        file_put_contents($srtPath, $this->buildSrtContent($srtEntries));

        $chunks[$chunkIndex]['srt_path'] = $srtPath;
        $this->saveChunks($bookId, $chunks);

        return ['success' => true, 'srt_path' => $srtPath];
    }

    // ========== STAGE 6: COMPOSE VIDEO ==========

    public function composeVideo(AudioBook $audioBook): array
    {
        $bookId = $audioBook->id;
        $chunks = $this->loadChunks($bookId);

        if (!$chunks || empty($chunks)) {
            throw new \Exception('Chưa có chunks.');
        }

        foreach ($chunks as $i => $chunk) {
            if (empty($chunk['image_path']) || !file_exists($chunk['image_path'])) {
                throw new \Exception("Chunk {$i} chưa có ảnh.");
            }
            if (empty($chunk['audio_path']) || !file_exists($chunk['audio_path'])) {
                throw new \Exception("Chunk {$i} chưa có audio.");
            }
        }

        $workDir = storage_path('app/temp/review_video_' . $bookId . '_' . time());
        if (!is_dir($workDir)) mkdir($workDir, 0755, true);

        try {
            // Step 1: Create chunk clips (image + audio with Ken Burns)
            $chunkClips = [];
            foreach ($chunks as $i => $chunk) {
                $clipPath = $this->createChunkClip($chunk, $workDir, $i);
                $chunkClips[] = ['path' => $clipPath, 'duration' => $chunk['audio_duration']];
            }

            // Step 2: Concatenate clips
            $concatenated = count($chunkClips) === 1
                ? $chunkClips[0]['path']
                : $this->concatenateClips($chunkClips, $workDir);

            // Step 3: Merge SRTs and burn subtitles
            $globalSrt = $this->mergeChunkSrts($chunks, $workDir);
            $currentVideo = $concatenated;
            if ($globalSrt && file_exists($globalSrt)) {
                $currentVideo = $this->burnSubtitles($concatenated, $globalSrt, $workDir);
            }

            // Step 4: Add intro music
            if ($audioBook->intro_music) {
                $introPath = storage_path('app/public/' . $audioBook->intro_music);
                if (file_exists($introPath)) {
                    $currentVideo = $this->addIntroMusic($currentVideo, $introPath, $workDir, $audioBook);
                }
            }

            // Step 5: Add outro music
            $outroPath = null;
            if ($audioBook->outro_use_intro && $audioBook->intro_music) {
                $outroPath = storage_path('app/public/' . $audioBook->intro_music);
            } elseif ($audioBook->outro_music) {
                $outroPath = storage_path('app/public/' . $audioBook->outro_music);
            }
            if ($outroPath && file_exists($outroPath)) {
                $currentVideo = $this->addOutroMusic($currentVideo, $outroPath, $workDir, $audioBook);
            }

            // Step 6: Move to final output
            $outputDir = storage_path('app/public/books/' . $bookId . '/mp4');
            if (!is_dir($outputDir)) mkdir($outputDir, 0755, true);

            $outputPath = $outputDir . '/review_video.mp4';
            if (file_exists($outputPath)) unlink($outputPath);

            copy($currentVideo, $outputPath);
            $finalDuration = $this->getVideoDuration($outputPath);

            $relativePath = 'books/' . $bookId . '/mp4/review_video.mp4';
            $audioBook->update([
                'review_video' => $relativePath,
                'review_video_duration' => $finalDuration
            ]);

            Log::info("Review video completed", [
                'book_id' => $bookId,
                'duration' => $finalDuration,
                'chunks' => count($chunks)
            ]);

            return [
                'success' => true,
                'video_path' => $relativePath,
                'video_url' => asset('storage/' . $relativePath),
                'duration' => $finalDuration,
                'chunks' => count($chunks)
            ];
        } finally {
            $this->cleanupDirectory($workDir);
        }
    }

    // ========== VIDEO HELPERS (mirrored from DescriptionVideoService) ==========

    private function createChunkClip(array $chunk, string $workDir, int $index): string
    {
        $outputClip = $workDir . "/chunk_clip_{$index}.mp4";
        $duration = $chunk['audio_duration'];
        $fps = 30;
        $totalFrames = (int)ceil($duration * $fps);
        $zoomSpeed = 0.15 / max(1, $totalFrames);

        $command = sprintf(
            '%s -loop 1 -i %s -i %s ' .
                '-filter_complex "[0:v]scale=1920:1080:force_original_aspect_ratio=increase,crop=1920:1080,' .
                'zoompan=z=\'min(zoom+%s,1.15)\':d=%d:x=\'iw/2-(iw/zoom/2)\':y=\'ih/2-(ih/zoom/2)\':s=1920x1080:fps=%d[v]" ' .
                '-map "[v]" -map 1:a -c:v libx264 -preset fast -crf 23 -c:a aac -b:a 192k -pix_fmt yuv420p -t %.3f %s -y 2>&1',
            $this->ffmpegPath,
            escapeshellarg($chunk['image_path']),
            escapeshellarg($chunk['audio_path']),
            $zoomSpeed,
            $totalFrames,
            $fps,
            $duration,
            escapeshellarg($outputClip)
        );

        exec($command, $output, $returnCode);
        if ($returnCode !== 0 || !file_exists($outputClip)) {
            throw new \Exception("Không thể tạo video clip cho chunk {$index}");
        }

        return $outputClip;
    }

    private function concatenateClips(array $clips, string $workDir): string
    {
        // Use concat demuxer (more reliable for many clips)
        $listFile = $workDir . '/concat_list.txt';
        $content = '';
        foreach ($clips as $clip) {
            $path = str_replace('\\', '/', $clip['path']);
            $content .= "file '{$path}'\n";
        }
        file_put_contents($listFile, $content);

        $outputVideo = $workDir . '/concatenated.mp4';
        $command = sprintf(
            '%s -f concat -safe 0 -i %s -c:v libx264 -preset fast -crf 23 -c:a aac -b:a 192k -pix_fmt yuv420p %s -y 2>&1',
            $this->ffmpegPath,
            escapeshellarg($listFile),
            escapeshellarg($outputVideo)
        );

        exec($command, $output, $returnCode);
        if ($returnCode !== 0 || !file_exists($outputVideo)) {
            throw new \Exception("Không thể nối các chunk clips");
        }

        return $outputVideo;
    }

    private function mergeChunkSrts(array $chunks, string $workDir): ?string
    {
        $globalEntries = [];
        $timeOffset = 0.0;
        $entryIndex = 1;

        foreach ($chunks as $chunk) {
            if (empty($chunk['srt_path']) || !file_exists($chunk['srt_path'])) {
                $timeOffset += ($chunk['audio_duration'] ?? 0);
                continue;
            }

            $srtContent = file_get_contents($chunk['srt_path']);
            $blocks = preg_split('/\n\s*\n/', trim($srtContent));

            foreach ($blocks as $block) {
                $lines = explode("\n", trim($block));
                if (count($lines) >= 3) {
                    $timeParts = explode(' --> ', $lines[1]);
                    if (count($timeParts) === 2) {
                        $start = $this->srtTimeToSeconds(trim($timeParts[0])) + $timeOffset;
                        $end = $this->srtTimeToSeconds(trim($timeParts[1])) + $timeOffset;
                        $globalEntries[] = [
                            'index' => $entryIndex++,
                            'start' => $this->secondsToSrtTime($start),
                            'end' => $this->secondsToSrtTime($end),
                            'text' => implode("\n", array_slice($lines, 2))
                        ];
                    }
                }
            }

            $timeOffset += ($chunk['audio_duration'] ?? 0);
        }

        if (empty($globalEntries)) return null;

        $srtPath = $workDir . '/subtitles.srt';
        file_put_contents($srtPath, $this->buildSrtContent($globalEntries));
        return $srtPath;
    }

    private function burnSubtitles(string $videoPath, string $srtPath, string $workDir): string
    {
        $outputVideo = $workDir . '/with_subtitles.mp4';
        $srtPathEscaped = str_replace(['\\', ':'], ['\\\\\\\\', '\\\\:'], $srtPath);

        $command = sprintf(
            '%s -i %s -vf "subtitles=%s:force_style=\'FontSize=28,FontName=Arial,PrimaryColour=&H00FFFFFF,OutlineColour=&H00000000,BackColour=&H80000000,Outline=2,Shadow=1,MarginV=40,Alignment=2,WrapStyle=0\'" ' .
                '-c:v libx264 -preset fast -crf 23 -c:a copy %s -y 2>&1',
            $this->ffmpegPath,
            escapeshellarg($videoPath),
            $srtPathEscaped,
            escapeshellarg($outputVideo)
        );

        exec($command, $output, $returnCode);
        if ($returnCode !== 0 || !file_exists($outputVideo)) {
            Log::warning("Subtitle burn failed, returning video without subtitles");
            return $videoPath;
        }

        return $outputVideo;
    }

    private function addIntroMusic(string $videoPath, string $introPath, string $workDir, AudioBook $audioBook): string
    {
        $outputVideo = $workDir . '/with_intro.mp4';
        $fadeDuration = $audioBook->intro_fade_duration ?? 3;

        $command = sprintf(
            '%s -i %s -i %s -filter_complex ' .
                '"[1:a]afade=t=out:st=0:d=%d,volume=0.3[intro]; [0:a][intro]amix=inputs=2:duration=first:dropout_transition=2[outa]" ' .
                '-map 0:v -map "[outa]" -c:v copy -c:a aac -b:a 192k %s -y 2>&1',
            $this->ffmpegPath,
            escapeshellarg($videoPath),
            escapeshellarg($introPath),
            $fadeDuration,
            escapeshellarg($outputVideo)
        );

        exec($command, $output, $returnCode);
        if ($returnCode !== 0 || !file_exists($outputVideo)) {
            return $videoPath;
        }
        return $outputVideo;
    }

    private function addOutroMusic(string $videoPath, string $outroPath, string $workDir, AudioBook $audioBook): string
    {
        $outputVideo = $workDir . '/with_outro.mp4';
        $fadeDuration = $audioBook->outro_fade_duration ?? 3;
        $extendDuration = $audioBook->outro_extend_duration ?? 5;
        $videoDuration = $this->getVideoDuration($videoPath);
        $startAt = max(0, $videoDuration - $fadeDuration - $extendDuration);

        $command = sprintf(
            '%s -i %s -i %s -filter_complex ' .
                '"[1:a]adelay=%d|%d,afade=t=in:st=%.2f:d=%d,volume=0.3[outro]; [0:a][outro]amix=inputs=2:duration=longest:dropout_transition=2[outa]" ' .
                '-map 0:v -map "[outa]" -c:v copy -c:a aac -b:a 192k -shortest %s -y 2>&1',
            $this->ffmpegPath,
            escapeshellarg($videoPath),
            escapeshellarg($outroPath),
            (int)($startAt * 1000),
            (int)($startAt * 1000),
            $startAt,
            $fadeDuration,
            escapeshellarg($outputVideo)
        );

        exec($command, $output, $returnCode);
        if ($returnCode !== 0 || !file_exists($outputVideo)) {
            return $videoPath;
        }
        return $outputVideo;
    }

    // ========== SUBTITLE HELPERS ==========

    /**
     * Wrap text to max N chars per line, max 2 lines.
     */
    private function wrapSubtitleText(string $text, int $maxLineChars = 45): string
    {
        if (mb_strlen($text) <= $maxLineChars) {
            return $text;
        }

        // Try to split at a natural break point near the middle
        $mid = (int)(mb_strlen($text) / 2);
        $bestPos = null;

        // Search around the middle for a space
        for ($offset = 0; $offset < min(20, $mid); $offset++) {
            if ($mid + $offset < mb_strlen($text) && mb_substr($text, $mid + $offset, 1) === ' ') {
                $bestPos = $mid + $offset;
                break;
            }
            if ($mid - $offset > 0 && mb_substr($text, $mid - $offset, 1) === ' ') {
                $bestPos = $mid - $offset;
                break;
            }
        }

        if ($bestPos !== null) {
            $line1 = mb_substr($text, 0, $bestPos);
            $line2 = mb_substr($text, $bestPos + 1);
            return $line1 . "\n" . $line2;
        }

        return $text;
    }

    /**
     * Split a long sentence into segments of max $maxChars.
     */
    private function splitLongSentence(string $sentence, int $maxChars = 90): array
    {
        $parts = [];
        // First try splitting at commas, semicolons
        $clauses = preg_split('/(?<=[,;:，；])\s*/u', $sentence);

        $buffer = '';
        foreach ($clauses as $clause) {
            $clause = trim($clause);
            if (empty($clause)) continue;

            if ($buffer && mb_strlen($buffer . ' ' . $clause) > $maxChars) {
                $parts[] = $buffer;
                $buffer = $clause;
            } else {
                $buffer .= ($buffer ? ' ' : '') . $clause;
            }
        }
        if ($buffer) $parts[] = $buffer;

        // If still too long, force split at word boundaries
        $result = [];
        foreach ($parts as $part) {
            if (mb_strlen($part) <= $maxChars) {
                $result[] = $part;
            } else {
                $words = explode(' ', $part);
                $buf = '';
                foreach ($words as $word) {
                    if ($buf && mb_strlen($buf . ' ' . $word) > $maxChars) {
                        $result[] = $buf;
                        $buf = $word;
                    } else {
                        $buf .= ($buf ? ' ' : '') . $word;
                    }
                }
                if ($buf) $result[] = $buf;
            }
        }

        return $result;
    }

    // ========== SPLIT CHUNK ==========

    /**
     * Split a chunk into multiple chunks using "---" delimiter.
     * Re-indexes all chunks after splitting.
     */
    public function splitChunk(int $bookId, int $chunkIndex, string $textWithDelimiters): array
    {
        $chunks = $this->loadChunks($bookId);
        if (!$chunks || !isset($chunks[$chunkIndex])) {
            throw new \Exception("Segment {$chunkIndex} không tồn tại.");
        }

        $originalChunk = $chunks[$chunkIndex];
        $parts = preg_split('/\n*---\n*/', $textWithDelimiters);
        $parts = array_filter($parts, fn($p) => mb_strlen(trim($p)) > 5);
        $parts = array_values($parts);

        if (count($parts) < 2) {
            throw new \Exception('Cần ít nhất 2 phần sau khi tách (dùng --- để phân cách).');
        }

        // Create new chunks from parts
        $newChunks = [];
        foreach ($parts as $part) {
            $newChunks[] = [
                'chunk_index' => 0, // Will be re-indexed
                'text' => trim($part),
                'image_prompt' => $originalChunk['image_prompt'] ?? '',
                'image_path' => null,
                'audio_path' => null,
                'audio_duration' => null,
                'srt_path' => null,
            ];
        }

        // Replace original chunk with new chunks
        array_splice($chunks, $chunkIndex, 1, $newChunks);

        // Re-index all chunks
        foreach ($chunks as $i => &$chunk) {
            $chunk['chunk_index'] = $i;
        }

        $this->saveChunks($bookId, $chunks);

        return $chunks;
    }

    // ========== TRANSLATE PROMPT ==========

    /**
     * Translate image prompt between English and Vietnamese.
     * Auto-detects the language and translates to the other.
     */
    public function translatePrompt(string $prompt): array
    {
        $apiKey = config('services.gemini.api_key');
        if (!$apiKey) {
            throw new \Exception('Chưa cấu hình GEMINI_API_KEY.');
        }

        $systemPrompt = "You are a translator. Detect the language of the following text.\n";
        $systemPrompt .= "- If it is English, translate it to Vietnamese.\n";
        $systemPrompt .= "- If it is Vietnamese, translate it to English.\n";
        $systemPrompt .= "- Keep the same style and detail level.\n";
        $systemPrompt .= "- Return ONLY the translation, nothing else.\n\n";
        $systemPrompt .= "Text to translate:\n{$prompt}";

        $translated = $this->callGemini($apiKey, $systemPrompt, 0.3, 2048);

        // Detect direction
        $isEnglish = preg_match('/^[a-zA-Z0-9\s.,!?\-\'\"():;\/\[\]{}@#$%^&*+=<>~`]+$/', trim($prompt));
        $direction = $isEnglish ? 'en_to_vi' : 'vi_to_en';

        return [
            'original' => $prompt,
            'translated' => trim($translated),
            'direction' => $direction,
        ];
    }

    // ========== UTILITY HELPERS ==========

    private function callGemini(string $apiKey, string $prompt, float $temperature = 0.7, int $maxTokens = 4096): string
    {
        $maxRetries = 3;
        $retryDelay = 10; // seconds

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 120);

            $payload = [
                'contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => [
                    'temperature' => $temperature,
                    'maxOutputTokens' => $maxTokens
                ]
            ];

            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            // Retry on 429 (rate limit) or 503 (overloaded)
            if (in_array($httpCode, [429, 503]) && $attempt < $maxRetries) {
                $waitTime = $retryDelay * $attempt; // 10s, 20s, 30s
                Log::warning("Gemini API rate limited (HTTP {$httpCode}), retry {$attempt}/{$maxRetries} after {$waitTime}s");
                sleep($waitTime);
                continue;
            }

            if ($httpCode !== 200) {
                throw new \Exception("Gemini API error (HTTP {$httpCode}): " . ($error ?: mb_substr($response, 0, 500)));
            }

            $result = json_decode($response, true);
            $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';

            if (empty($text)) {
                throw new \Exception('Gemini không trả về kết quả.');
            }

            return trim($text);
        }

        throw new \Exception('Gemini API failed after all retries.');
    }

    private function getAudioDuration(string $audioPath): float
    {
        $command = sprintf('%s -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s',
            $this->ffprobePath, escapeshellarg($audioPath));
        exec($command, $output);
        return !empty($output) ? (float)$output[0] : 0;
    }

    private function getVideoDuration(string $videoPath): float
    {
        $command = sprintf('%s -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s',
            $this->ffprobePath, escapeshellarg($videoPath));
        exec($command, $output);
        return !empty($output) ? (float)$output[0] : 0;
    }

    private function secondsToSrtTime(float $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = floor($seconds % 60);
        $millis = round(($seconds - floor($seconds)) * 1000);
        return sprintf('%02d:%02d:%02d,%03d', $hours, $minutes, $secs, $millis);
    }

    private function srtTimeToSeconds(string $srtTime): float
    {
        $parts = preg_split('/[:, ]/', $srtTime);
        return (int)$parts[0] * 3600 + (int)$parts[1] * 60 + (int)$parts[2] + (int)($parts[3] ?? 0) / 1000;
    }

    private function buildSrtContent(array $entries): string
    {
        $lines = [];
        foreach ($entries as $entry) {
            $lines[] = $entry['index'];
            $lines[] = $entry['start'] . ' --> ' . $entry['end'];
            $lines[] = $entry['text'];
            $lines[] = '';
        }
        return implode("\n", $lines);
    }

    private function cleanupDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            $files = glob($dir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) unlink($file);
            }
            @rmdir($dir);
        }
    }
}
