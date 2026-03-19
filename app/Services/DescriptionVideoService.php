<?php

namespace App\Services;

use App\Models\AudioBook;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Service for generating description intro videos from chunked text.
 * 
 * Pipeline:
 * 1. AI splits description into chunks (preserving original text)
 * 2. AI generates image prompts for each chunk
 * 3. Generate images from prompts (Gemini)
 * 4. Generate TTS audio for each chunk
 * 5. Generate SRT subtitles for each chunk (proportional sentence timing)
 * 6. Compose final video: images + audio + subtitles + intro/outro music
 */
class DescriptionVideoService
{
    private GeminiImageService $imageService;
    private TTSService $ttsService;
    private VideoCompositionService $compositionService;
    private string $ffmpegPath;
    private string $ffprobePath;

    public function __construct(
        GeminiImageService $imageService,
        TTSService $ttsService,
        VideoCompositionService $compositionService
    ) {
        $this->imageService = $imageService;
        $this->ttsService = $ttsService;
        $this->compositionService = $compositionService;
        $this->ffmpegPath = env('FFMPEG_PATH', 'ffmpeg');
        $this->ffprobePath = env('FFPROBE_PATH', 'ffprobe');
    }

    /**
     * Get the working directory for description video pipeline.
     */
    public function getWorkDir(int $bookId): string
    {
        $dir = storage_path('app/public/books/' . $bookId . '/description_video');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * Get the chunks JSON file path.
     */
    public function getChunksPath(int $bookId): string
    {
        return $this->getWorkDir($bookId) . '/chunks.json';
    }

    /**
     * Load saved chunks from JSON.
     */
    public function loadChunks(int $bookId): ?array
    {
        $path = $this->getChunksPath($bookId);
        if (file_exists($path)) {
            return json_decode(file_get_contents($path), true);
        }
        return null;
    }

    /**
     * Save chunks to JSON.
     */
    public function saveChunks(int $bookId, array $chunks): void
    {
        $path = $this->getChunksPath($bookId);
        file_put_contents($path, json_encode($chunks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    // ========== STEP 1+2: AI CHUNK + GENERATE PROMPTS ==========

    /**
     * Use AI to split description into chunks and generate image prompts.
     * Preserves original text — no modifications.
     */
    public function analyzeAndChunk(AudioBook $audioBook): array
    {
        $description = $audioBook->description;
        if (empty($description)) {
            throw new \Exception('Chưa có nội dung giới thiệu sách.');
        }

        $title = $audioBook->title;
        $category = $audioBook->category ?? '';
        $bookType = $audioBook->book_type ?? '';

        $apiKey = env('GEMINI_API_KEY');
        if (!$apiKey) {
            // Fallback: split by paragraphs
            return $this->fallbackChunk($description);
        }

        $prompt = $this->buildChunkPrompt($title, $description, $category, $bookType);

        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);

            $payload = [
                'contents' => [
                    ['parts' => [['text' => $prompt]]]
                ],
                'generationConfig' => [
                    'temperature' => 0.3,
                    'maxOutputTokens' => 4096
                ]
            ];

            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $result = json_decode($response, true);
                $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';

                $chunks = $this->parseChunkResponse($text, $description);
                if (!empty($chunks)) {
                    Log::info("AI chunked description into " . count($chunks) . " chunks");
                    $this->saveChunks($audioBook->id, $chunks);
                    return $chunks;
                }
            }

            Log::warning("AI chunking failed (HTTP {$httpCode}), using fallback");
            $chunks = $this->fallbackChunk($description);
            $this->saveChunks($audioBook->id, $chunks);
            return $chunks;
        } catch (\Exception $e) {
            Log::error("AI chunking error: " . $e->getMessage());
            $chunks = $this->fallbackChunk($description);
            $this->saveChunks($audioBook->id, $chunks);
            return $chunks;
        }
    }

    /**
     * Build the AI prompt for chunking + image prompt generation.
     */
    private function buildChunkPrompt(string $title, string $description, string $category, string $bookType): string
    {
        $prompt = "Bạn là chuyên gia phân tích văn bản và tạo storyboard video.\n\n";
        $prompt .= "NHIỆM VỤ: Chia nội dung giới thiệu sách sau thành các CHUNK (đoạn) hợp lý để tạo video giới thiệu.\n\n";

        $prompt .= "QUY TẮC QUAN TRỌNG:\n";
        $prompt .= "1. KHÔNG ĐƯỢC thay đổi nội dung gốc - mỗi chunk phải là NGUYÊN VĂN một phần của nội dung gốc\n";
        $prompt .= "2. Các chunk nối lại phải tạo thành TOÀN BỘ nội dung gốc (không thiếu, không thừa)\n";
        $prompt .= "3. Chia theo đoạn ý nghĩa hợp lý (1-3 câu mỗi chunk), không cắt giữa câu\n";
        $prompt .= "4. Mỗi chunk tạo MÔ TẢ HÌNH ẢNH (image_prompt) bằng tiếng Anh để AI vẽ ảnh minh họa phù hợp\n";
        $prompt .= "5. Số lượng chunk tùy thuộc vào nội dung (thường 4-10 chunks)\n\n";

        $prompt .= "THÔNG TIN SÁCH:\n";
        $prompt .= "- Tên: {$title}\n";
        if ($category) $prompt .= "- Thể loại: {$category}\n";
        if ($bookType) $prompt .= "- Loại: {$bookType}\n";

        $prompt .= "\nNỘI DUNG GIỚI THIỆU (NGUYÊN VĂN):\n";
        $prompt .= "---\n{$description}\n---\n\n";

        $prompt .= "OUTPUT FORMAT (JSON thuần, KHÔNG markdown):\n";
        $prompt .= "[\n";
        $prompt .= "  {\n";
        $prompt .= "    \"chunk_index\": 0,\n";
        $prompt .= "    \"text\": \"Nguyên văn đoạn text từ nội dung gốc\",\n";
        $prompt .= "    \"image_prompt\": \"Detailed English prompt for generating an illustration that matches this text chunk. Include scene description, mood, colors, style.\"\n";
        $prompt .= "  }\n";
        $prompt .= "]\n\n";
        $prompt .= "CHÚ Ý: Trường 'text' phải là COPY CHÍNH XÁC từ nội dung gốc, không thêm bớt bất kỳ ký tự nào.\n";

        return $prompt;
    }

    /**
     * Parse AI response into chunk array. Validates chunks against original text.
     */
    private function parseChunkResponse(string $responseText, string $originalDescription): array
    {
        // Extract JSON from response
        $cleaned = trim($responseText);
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned);
        $cleaned = preg_replace('/\s*```$/i', '', $cleaned);

        $parsed = json_decode($cleaned, true);
        if (!is_array($parsed) || empty($parsed)) {
            Log::warning("Failed to parse chunk JSON response");
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

        // Validate: all chunks together should resemble original description
        $combined = implode(' ', array_column($chunks, 'text'));
        $originalClean = preg_replace('/\s+/', ' ', trim($originalDescription));
        $combinedClean = preg_replace('/\s+/', ' ', trim($combined));

        $similarity = similar_text($originalClean, $combinedClean, $percent);
        Log::info("Chunk validation: similarity = {$percent}%", [
            'original_len' => mb_strlen($originalClean),
            'combined_len' => mb_strlen($combinedClean),
            'chunk_count' => count($chunks)
        ]);

        if ($percent < 50) {
            Log::warning("Chunks diverge too much from original ({$percent}%), using fallback");
            return [];
        }

        return $chunks;
    }

    /**
     * Fallback chunking: split by paragraphs or sentences.
     */
    private function fallbackChunk(string $description): array
    {
        // Split by double newline (paragraphs)
        $paragraphs = preg_split('/\n\s*\n/', trim($description));
        $paragraphs = array_filter($paragraphs, fn($p) => mb_strlen(trim($p)) > 10);
        $paragraphs = array_values($paragraphs);

        // If too few paragraphs, split by sentences
        if (count($paragraphs) <= 2) {
            $sentences = preg_split('/(?<=[.!?。])\s+/u', trim($description));
            $sentences = array_filter($sentences, fn($s) => mb_strlen(trim($s)) > 5);
            $sentences = array_values($sentences);

            // Group into chunks of 2-3 sentences
            $chunks = [];
            $buffer = '';
            $sentenceCount = 0;
            foreach ($sentences as $sentence) {
                $buffer .= ($buffer ? ' ' : '') . trim($sentence);
                $sentenceCount++;
                if ($sentenceCount >= 2) {
                    $chunks[] = $buffer;
                    $buffer = '';
                    $sentenceCount = 0;
                }
            }
            if ($buffer) $chunks[] = $buffer;
            $paragraphs = $chunks;
        }

        $result = [];
        foreach ($paragraphs as $i => $text) {
            $result[] = [
                'chunk_index' => $i,
                'text' => trim($text),
                'image_prompt' => 'A cinematic illustration related to the following text: ' . mb_substr(trim($text), 0, 200),
                'image_path' => null,
                'audio_path' => null,
                'audio_duration' => null,
                'srt_path' => null
            ];
        }

        return $result;
    }

    // ========== STEP 3: GENERATE IMAGE FOR A CHUNK ==========

    /**
     * Generate image for a single chunk.
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

        // Delete old image if exists
        if (file_exists($outputPath)) {
            unlink($outputPath);
        }

        $result = $this->imageService->generateImage($prompt, $outputPath, '16:9', $imageProvider);

        if ($result['success']) {
            // Update chunks JSON
            $chunks = $this->loadChunks($bookId);
            if ($chunks && isset($chunks[$chunkIndex])) {
                $chunks[$chunkIndex]['image_path'] = $outputPath;
                $chunks[$chunkIndex]['image_provider'] = $imageProvider;
                $this->saveChunks($bookId, $chunks);
            }

            return [
                'success' => true,
                'image_path' => $outputPath,
                'url' => asset('storage/books/' . $bookId . '/description_video/images/' . $filename)
            ];
        }

        return $result;
    }

    // ========== STEP 4: GENERATE TTS FOR A CHUNK ==========

    /**
     * Generate TTS audio for a single chunk.
     */
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

        // Skip style_instruction for Microsoft and OpenAI
        $providersWithoutStyle = ['microsoft', 'openai'];
        if (in_array(strtolower($provider), $providersWithoutStyle)) {
            $styleInstruction = null;
        }

        // Generate TTS
        $tempPath = $this->ttsService->generateAudio(
            $text,
            $chunkIndex,
            $voiceGender,
            $voiceName,
            $provider,
            $styleInstruction,
            null
        );

        // Move to permanent location
        $filename = "chunk_{$chunkIndex}.mp3";
        $outputPath = $audioDir . '/' . $filename;

        $sourcePath = storage_path('app/' . $tempPath);
        if (file_exists($sourcePath)) {
            if (file_exists($outputPath)) {
                unlink($outputPath);
            }
            copy($sourcePath, $outputPath);
            unlink($sourcePath);
        } else {
            throw new \Exception("TTS output file not found: {$sourcePath}");
        }

        // Get duration
        $duration = $this->getAudioDuration($outputPath);

        // Update chunks JSON
        $chunks = $this->loadChunks($bookId);
        if ($chunks && isset($chunks[$chunkIndex])) {
            $chunks[$chunkIndex]['audio_path'] = $outputPath;
            $chunks[$chunkIndex]['audio_duration'] = $duration;
            $this->saveChunks($bookId, $chunks);
        }

        return [
            'success' => true,
            'audio_path' => $outputPath,
            'duration' => $duration,
            'url' => asset('storage/books/' . $bookId . '/description_video/audio/' . $filename)
        ];
    }

    // ========== STEP 5: GENERATE SRT FOR A CHUNK ==========

    /**
     * Generate SRT subtitle for a single chunk using proportional sentence timing.
     * Each sentence gets duration proportional to its character count.
     */
    public function generateChunkSrt(int $bookId, int $chunkIndex): array
    {
        $chunks = $this->loadChunks($bookId);
        if (!$chunks || !isset($chunks[$chunkIndex])) {
            throw new \Exception("Chunk {$chunkIndex} không tồn tại.");
        }

        $chunk = $chunks[$chunkIndex];
        $text = $chunk['text'];
        $audioDuration = $chunk['audio_duration'] ?? null;

        if (!$audioDuration) {
            // Try to get from audio file
            if (!empty($chunk['audio_path']) && file_exists($chunk['audio_path'])) {
                $audioDuration = $this->getAudioDuration($chunk['audio_path']);
            }
            if (!$audioDuration) {
                throw new \Exception("Chunk {$chunkIndex} chưa có audio hoặc không có thời lượng.");
            }
        }

        // Split text into sentences
        $sentences = $this->splitIntoSentences($text);

        // Calculate total character count
        $totalChars = 0;
        foreach ($sentences as $s) {
            $totalChars += mb_strlen($s);
        }
        if ($totalChars === 0) $totalChars = 1;

        // Generate SRT entries with proportional timing
        $srtEntries = [];
        $currentTime = 0.0;

        foreach ($sentences as $i => $sentence) {
            $proportion = mb_strlen($sentence) / $totalChars;
            $duration = $audioDuration * $proportion;
            $duration = max(0.5, $duration); // minimum 0.5s per sentence

            $startTime = $currentTime;
            $endTime = $currentTime + $duration;

            $srtEntries[] = [
                'index' => $i + 1,
                'start' => $this->secondsToSrtTime($startTime),
                'end' => $this->secondsToSrtTime($endTime),
                'text' => trim($sentence)
            ];

            $currentTime = $endTime;
        }

        // Normalize to fit exact audio duration
        if (!empty($srtEntries) && $currentTime > 0) {
            $scale = $audioDuration / $currentTime;
            $adjustedTime = 0.0;
            foreach ($srtEntries as &$entry) {
                $entryDuration = $this->srtTimeToSeconds($entry['end']) - $this->srtTimeToSeconds($entry['start']);
                $adjustedDuration = $entryDuration * $scale;
                $entry['start'] = $this->secondsToSrtTime($adjustedTime);
                $adjustedTime += $adjustedDuration;
                $entry['end'] = $this->secondsToSrtTime($adjustedTime);
            }
        }

        // Write SRT file
        $workDir = $this->getWorkDir($bookId);
        $srtDir = $workDir . '/srt';
        if (!is_dir($srtDir)) {
            mkdir($srtDir, 0755, true);
        }

        $filename = "chunk_{$chunkIndex}.srt";
        $srtPath = $srtDir . '/' . $filename;
        $srtContent = $this->buildSrtContent($srtEntries);
        file_put_contents($srtPath, $srtContent);

        // Update chunks JSON
        $chunks[$chunkIndex]['srt_path'] = $srtPath;
        $this->saveChunks($bookId, $chunks);

        return [
            'success' => true,
            'srt_path' => $srtPath,
            'entries' => $srtEntries,
            'sentences' => count($sentences)
        ];
    }

    /**
     * Split text into sentences, preserving Vietnamese punctuation.
     */
    private function splitIntoSentences(string $text): array
    {
        // Split on sentence-ending punctuation followed by space or end of string
        $sentences = preg_split('/(?<=[.!?。…])\s+/u', trim($text));
        $sentences = array_filter($sentences, fn($s) => mb_strlen(trim($s)) > 0);
        return array_values($sentences);
    }

    /**
     * Convert seconds to SRT timestamp format (HH:MM:SS,mmm).
     */
    private function secondsToSrtTime(float $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = floor($seconds % 60);
        $millis = round(($seconds - floor($seconds)) * 1000);

        return sprintf('%02d:%02d:%02d,%03d', $hours, $minutes, $secs, $millis);
    }

    /**
     * Convert SRT timestamp back to seconds.
     */
    private function srtTimeToSeconds(string $srtTime): float
    {
        $parts = preg_split('/[:, ]/', $srtTime);
        return (int)$parts[0] * 3600 + (int)$parts[1] * 60 + (int)$parts[2] + (int)($parts[3] ?? 0) / 1000;
    }

    /**
     * Build SRT file content from entries.
     */
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

    // ========== STEP 6: COMPOSE FINAL VIDEO ==========

    /**
     * Compose the final description video from all chunks.
     * Combines: images (Ken Burns) + audio + subtitles + intro/outro music.
     */
    public function composeVideo(AudioBook $audioBook): array
    {
        $bookId = $audioBook->id;
        $chunks = $this->loadChunks($bookId);

        if (!$chunks || empty($chunks)) {
            throw new \Exception('Chưa có chunks. Vui lòng chạy bước phân tích trước.');
        }

        // Validate all chunks have required data
        foreach ($chunks as $i => $chunk) {
            if (empty($chunk['image_path']) || !file_exists($chunk['image_path'])) {
                throw new \Exception("Chunk {$i} chưa có ảnh minh họa.");
            }
            if (empty($chunk['audio_path']) || !file_exists($chunk['audio_path'])) {
                throw new \Exception("Chunk {$i} chưa có audio TTS.");
            }
        }

        $workDir = storage_path('app/temp/desc_video_' . $bookId . '_' . time());
        if (!is_dir($workDir)) {
            mkdir($workDir, 0755, true);
        }

        try {
            // Step 1: Create individual chunk clips (image + audio)
            $chunkClips = [];
            foreach ($chunks as $i => $chunk) {
                $clipPath = $this->createChunkClip($chunk, $workDir, $i);
                $chunkClips[] = [
                    'path' => $clipPath,
                    'duration' => $chunk['audio_duration']
                ];
            }

            // Step 2: Concatenate all chunk clips with transitions
            if (count($chunkClips) === 1) {
                $concatenated = $chunkClips[0]['path'];
            } else {
                $concatenated = $this->concatenateChunkClips($chunkClips, $workDir);
            }

            // Step 3: Merge all individual SRT files into one global SRT
            $globalSrt = $this->mergeChunkSrts($chunks, $workDir);

            // Step 4: Burn subtitles into video (if SRT exists)
            if ($globalSrt && file_exists($globalSrt)) {
                $videoWithSubs = $this->burnSubtitles($concatenated, $globalSrt, $workDir);
            } else {
                $videoWithSubs = $concatenated;
            }

            // Step 5: Add intro music (from audiobook settings)
            $currentVideo = $videoWithSubs;
            if ($audioBook->intro_music) {
                $introPath = storage_path('app/public/' . $audioBook->intro_music);
                if (file_exists($introPath)) {
                    $currentVideo = $this->addIntroMusic($currentVideo, $introPath, $workDir, $audioBook);
                }
            }

            // Step 6: Add outro music
            $outroMusicPath = null;
            if ($audioBook->outro_use_intro && $audioBook->intro_music) {
                $outroMusicPath = storage_path('app/public/' . $audioBook->intro_music);
            } elseif ($audioBook->outro_music) {
                $outroMusicPath = storage_path('app/public/' . $audioBook->outro_music);
            }
            if ($outroMusicPath && file_exists($outroMusicPath)) {
                $currentVideo = $this->addOutroMusic($currentVideo, $outroMusicPath, $workDir, $audioBook);
            }

            // Step 7: Move to final output
            $outputDir = storage_path('app/public/books/' . $bookId . '/mp4');
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }
            $outputPath = $outputDir . '/description_intro.mp4';
            if (file_exists($outputPath)) {
                unlink($outputPath);
            }

            copy($currentVideo, $outputPath);
            $finalDuration = $this->getVideoDuration($outputPath);

            $relativePath = 'books/' . $bookId . '/mp4/description_intro.mp4';

            // Update audiobook
            $audioBook->update([
                'description_scene_video' => $relativePath,
                'description_scene_video_duration' => $finalDuration
            ]);

            Log::info("Description intro video completed", [
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

    /**
     * Create a single chunk clip: image with Ken Burns zoom + audio.
     */
    private function createChunkClip(array $chunk, string $workDir, int $index): string
    {
        $outputClip = $workDir . "/chunk_clip_{$index}.mp4";
        $duration = $chunk['audio_duration'];
        $imagePath = $chunk['image_path'];
        $audioPath = $chunk['audio_path'];

        $fps = 30;
        $totalFrames = (int)ceil($duration * $fps);
        $zoomSpeed = 0.15 / max(1, $totalFrames); // zoom from 1.0 to 1.15

        // Ken Burns zoom-in effect on image + overlay audio
        $command = sprintf(
            '%s -loop 1 -i %s -i %s ' .
                '-filter_complex "[0:v]scale=1920:1080:force_original_aspect_ratio=increase,crop=1920:1080,' .
                'zoompan=z=\'min(zoom+%s,1.15)\':d=1:x=\'iw/2-(iw/zoom/2)\':y=\'ih/2-(ih/zoom/2)\':s=1920x1080:fps=%d[v]" ' .
                '-map "[v]" -map 1:a -c:v libx264 -preset fast -crf 23 -c:a aac -b:a 192k -pix_fmt yuv420p -shortest %s -y 2>&1',
            $this->ffmpegPath,
            escapeshellarg($imagePath),
            escapeshellarg($audioPath),
            $zoomSpeed,
            $fps,
            escapeshellarg($outputClip)
        );

        Log::info("Creating chunk clip", ['index' => $index, 'duration' => $duration]);
        exec($command, $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($outputClip)) {
            Log::error("Chunk clip creation failed", ['index' => $index, 'output' => implode("\n", $output)]);
            throw new \Exception("Không thể tạo video clip cho chunk {$index}");
        }

        return $outputClip;
    }

    /**
     * Concatenate chunk clips with xfade transitions.
     */
    private function concatenateChunkClips(array $clips, string $workDir): string
    {
        $transitions = ['fade', 'wipeleft', 'wiperight', 'dissolve', 'slideleft', 'slideright'];
        $transitionDuration = 0.5;

        // Prepare inputs
        $inputs = '';
        foreach ($clips as $clip) {
            $inputs .= ' -i ' . escapeshellarg($clip['path']);
        }

        // Build xfade chain for video
        $videoFilters = [];
        $audioFilters = [];
        $current = '[0:v]';

        for ($i = 0; $i < count($clips) - 1; $i++) {
            $transition = $transitions[array_rand($transitions)];
            $next = $i + 1;
            $isLast = ($i === count($clips) - 2);
            $output = $isLast ? '[outv]' : "[vt{$next}]";

            // Calculate offset
            $offset = 0;
            for ($j = 0; $j <= $i; $j++) {
                $offset += $clips[$j]['duration'];
            }
            $offset -= ($i + 1) * $transitionDuration;
            $offset = max(0, $offset);

            $videoFilters[] = sprintf(
                "%s[%d:v]xfade=transition=%s:duration=%.2f:offset=%.2f%s",
                $current,
                $next,
                $transition,
                $transitionDuration,
                $offset,
                $output
            );
            $current = "[vt{$next}]";
        }

        // Audio: simple concatenation
        $audioInputs = '';
        for ($i = 0; $i < count($clips); $i++) {
            $audioInputs .= "[{$i}:a]";
        }
        $audioFilters[] = $audioInputs . "concat=n=" . count($clips) . ":v=0:a=1[outa]";

        $filterComplex = implode('; ', $videoFilters) . '; ' . implode('; ', $audioFilters);
        $outputVideo = $workDir . '/concatenated.mp4';

        $command = sprintf(
            '%s %s -filter_complex %s -map "[outv]" -map "[outa]" -c:v libx264 -preset fast -crf 23 -c:a aac -b:a 192k -pix_fmt yuv420p %s -y 2>&1',
            $this->ffmpegPath,
            $inputs,
            escapeshellarg($filterComplex),
            escapeshellarg($outputVideo)
        );

        Log::info("Concatenating " . count($clips) . " chunk clips with transitions");
        exec($command, $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($outputVideo)) {
            Log::warning("xfade concat failed, trying fallback concat demuxer", [
                'output' => implode("\n", $output)
            ]);
            return $this->fallbackConcatClips($clips, $workDir);
        }

        return $outputVideo;
    }

    /**
     * Fallback concatenation using concat demuxer.
     */
    private function fallbackConcatClips(array $clips, string $workDir): string
    {
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
            throw new \Exception("Không thể nối các chunk clips: " . implode("\n", $output));
        }

        return $outputVideo;
    }

    /**
     * Merge all individual chunk SRT files into one global SRT with accumulated offsets.
     */
    private function mergeChunkSrts(array $chunks, string $workDir): ?string
    {
        $globalEntries = [];
        $timeOffset = 0.0;
        $entryIndex = 1;

        foreach ($chunks as $i => $chunk) {
            if (empty($chunk['srt_path']) || !file_exists($chunk['srt_path'])) {
                // No SRT for this chunk, skip but add time offset
                $timeOffset += ($chunk['audio_duration'] ?? 0);
                continue;
            }

            $srtContent = file_get_contents($chunk['srt_path']);
            $entries = $this->parseSrtContent($srtContent);

            foreach ($entries as $entry) {
                $startSeconds = $this->srtTimeToSeconds($entry['start']) + $timeOffset;
                $endSeconds = $this->srtTimeToSeconds($entry['end']) + $timeOffset;

                $globalEntries[] = [
                    'index' => $entryIndex++,
                    'start' => $this->secondsToSrtTime($startSeconds),
                    'end' => $this->secondsToSrtTime($endSeconds),
                    'text' => $entry['text']
                ];
            }

            $timeOffset += ($chunk['audio_duration'] ?? 0);
        }

        if (empty($globalEntries)) {
            return null;
        }

        $globalSrtPath = $workDir . '/subtitles.srt';
        file_put_contents($globalSrtPath, $this->buildSrtContent($globalEntries));

        // Also save a copy in the work dir
        $persistSrtPath = $this->getWorkDir($chunks[0]['chunk_index'] ?? 0);
        // Actually, let's save to the book's description_video dir
        // We need the bookId — extract from image_path
        if (!empty($chunks[0]['image_path'])) {
            preg_match('/books\/(\d+)\//', $chunks[0]['image_path'], $matches);
            if (!empty($matches[1])) {
                $bookWorkDir = $this->getWorkDir((int)$matches[1]);
                $persistPath = $bookWorkDir . '/subtitles.srt';
                copy($globalSrtPath, $persistPath);
            }
        }

        return $globalSrtPath;
    }

    /**
     * Parse SRT file content into entries.
     */
    private function parseSrtContent(string $content): array
    {
        $entries = [];
        $blocks = preg_split('/\n\s*\n/', trim($content));

        foreach ($blocks as $block) {
            $lines = explode("\n", trim($block));
            if (count($lines) >= 3) {
                $timeParts = explode(' --> ', $lines[1]);
                if (count($timeParts) === 2) {
                    $entries[] = [
                        'start' => trim($timeParts[0]),
                        'end' => trim($timeParts[1]),
                        'text' => implode("\n", array_slice($lines, 2))
                    ];
                }
            }
        }

        return $entries;
    }

    /**
     * Burn SRT subtitles into video using FFmpeg.
     */
    private function burnSubtitles(string $videoPath, string $srtPath, string $workDir): string
    {
        $outputVideo = $workDir . '/with_subtitles.mp4';

        // Use subtitles filter with styling
        // Force=1 to use full path on Windows
        $srtPathEscaped = str_replace(['\\', ':'], ['\\\\\\\\', '\\\\:'], $srtPath);

        $command = sprintf(
            '%s -i %s -vf "subtitles=%s:force_style=\'FontSize=22,FontName=Arial,PrimaryColour=&H00FFFFFF,OutlineColour=&H00000000,Outline=2,Shadow=1,MarginV=30\'" ' .
                '-c:v libx264 -preset fast -crf 23 -c:a copy %s -y 2>&1',
            $this->ffmpegPath,
            escapeshellarg($videoPath),
            $srtPathEscaped,
            escapeshellarg($outputVideo)
        );

        Log::info("Burning subtitles into video");
        exec($command, $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($outputVideo)) {
            Log::warning("Subtitle burn failed, trying ASS filter", ['output' => implode("\n", $output)]);

            // Fallback: try with simpler subtitles approach
            $command2 = sprintf(
                '%s -i %s -i %s -c:v libx264 -preset fast -crf 23 -c:a copy -c:s mov_text %s -y 2>&1',
                $this->ffmpegPath,
                escapeshellarg($videoPath),
                escapeshellarg($srtPath),
                escapeshellarg($outputVideo)
            );

            exec($command2, $output2, $returnCode2);

            if ($returnCode2 !== 0 || !file_exists($outputVideo)) {
                Log::warning("Subtitle embedding also failed, returning video without subtitles");
                return $videoPath;
            }
        }

        return $outputVideo;
    }

    /**
     * Add intro music with crossfade.
     */
    private function addIntroMusic(string $videoPath, string $introPath, string $workDir, AudioBook $audioBook): string
    {
        $outputVideo = $workDir . '/with_intro.mp4';
        $fadeDuration = $audioBook->intro_fade_duration ?? 3;

        // Overlay intro music at the beginning with fade out
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
            Log::warning("Intro music failed, continuing without");
            return $videoPath;
        }

        return $outputVideo;
    }

    /**
     * Add outro music at the end.
     */
    private function addOutroMusic(string $videoPath, string $outroPath, string $workDir, AudioBook $audioBook): string
    {
        $outputVideo = $workDir . '/with_outro.mp4';
        $fadeDuration = $audioBook->outro_fade_duration ?? 3;
        $extendDuration = $audioBook->outro_extend_duration ?? 5;

        // Get video duration
        $videoDuration = $this->getVideoDuration($videoPath);

        // Add outro music mixed in at the end
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
            Log::warning("Outro music failed, continuing without");
            return $videoPath;
        }

        return $outputVideo;
    }

    // ========== HELPERS ==========

    private function getAudioDuration(string $audioPath): float
    {
        $command = sprintf(
            '%s -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s',
            $this->ffprobePath,
            escapeshellarg($audioPath)
        );
        exec($command, $output);
        return !empty($output) ? (float)$output[0] : 0;
    }

    private function getVideoDuration(string $videoPath): float
    {
        $command = sprintf(
            '%s -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s',
            $this->ffprobePath,
            escapeshellarg($videoPath)
        );
        exec($command, $output);
        return !empty($output) ? (float)$output[0] : 0;
    }

    private function cleanupDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            $files = glob($dir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            @rmdir($dir);
        }
    }
}
