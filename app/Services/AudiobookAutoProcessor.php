<?php

namespace App\Services;

use App\Models\AudioBook;
use App\Models\AudioBookChapter;
use App\Models\AudioBookChapterChunk;
use App\Mail\AudiobookProcessingComplete;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AudiobookAutoProcessor
{
    protected TTSService $ttsService;

    public function __construct(TTSService $ttsService)
    {
        $this->ttsService = $ttsService;
    }

    /**
     * Find and process audiobooks that need TTS generation
     * Returns summary of processed/completed books
     */
    public function processPendingTTS(int $limit = 3): array
    {
        $processed = [];
        $completed = [];

        // Find audiobooks with TTS configured but incomplete chapters
        $audiobooks = AudioBook::whereNotNull('tts_provider')
            ->whereNotNull('tts_voice_name')
            ->whereHas('chapters', function ($q) {
                $q->whereNotNull('content')
                    ->where('content', '!=', '')
                    ->where(function ($q2) {
                        // Chapter not completed
                        $q2->where('status', '!=', 'completed')
                            // OR chapter has error/pending chunks
                            ->orWhereHas('chunks', function ($q3) {
                                $q3->whereIn('status', ['error', 'pending']);
                            })
                            // OR chapter has no chunks at all
                            ->orWhereDoesntHave('chunks');
                    });
            })
            ->limit($limit)
            ->get();

        foreach ($audiobooks as $audioBook) {
            try {
                Log::info("[AutoProcess] Processing TTS for audiobook: {$audioBook->title} (ID: {$audioBook->id})");
                $result = $this->processBookTTS($audioBook);
                $processed[] = $audioBook->id;

                // Check if ALL chapters are now completed
                $pendingCount = $audioBook->chapters()
                    ->where('status', '!=', 'completed')
                    ->whereNotNull('content')
                    ->where('content', '!=', '')
                    ->count();

                if ($pendingCount === 0 && $result['chapters_processed'] > 0) {
                    $completed[] = $audioBook->id;
                    $this->sendCompletionEmail($audioBook, 'audio');
                }
            } catch (\Exception $e) {
                Log::error("[AutoProcess] TTS processing failed for audiobook {$audioBook->id}: " . $e->getMessage());
            }
        }

        return [
            'processed' => $processed,
            'completed' => $completed,
            'found' => $audiobooks->count(),
        ];
    }

    /**
     * Process TTS for a single audiobook's chapters
     */
    private function processBookTTS(AudioBook $audioBook): array
    {
        $chaptersProcessed = 0;
        $chunksGenerated = 0;
        $errors = [];

        $chapters = $audioBook->chapters()
            ->whereNotNull('content')
            ->where('content', '!=', '')
            ->where(function ($q) {
                $q->where('status', '!=', 'completed')
                    ->orWhereHas('chunks', function ($q2) {
                        $q2->whereIn('status', ['error', 'pending']);
                    })
                    ->orWhereDoesntHave('chunks');
            })
            ->orderBy('chapter_number')
            ->get();

        foreach ($chapters as $chapter) {
            try {
                Log::info("[AutoProcess] Processing chapter {$chapter->chapter_number}: {$chapter->title}");

                // Step 1: Ensure chunks exist
                $existingChunks = $chapter->chunks()->count();
                if ($existingChunks === 0) {
                    $this->createChunksForChapter($chapter);
                }

                // Step 2: Reset error chunks to pending
                $chapter->chunks()
                    ->where('status', 'error')
                    ->update(['status' => 'pending', 'error_message' => null]);

                // Step 3: Generate audio for pending chunks
                $pendingChunks = $chapter->chunks()
                    ->where('status', 'pending')
                    ->orderBy('chunk_number')
                    ->get();

                foreach ($pendingChunks as $chunk) {
                    try {
                        $this->generateChunkAudio($chunk, $chapter, $audioBook);
                        $chunksGenerated++;
                    } catch (\Exception $e) {
                        $chunk->status = 'error';
                        $chunk->error_message = $e->getMessage();
                        $chunk->save();
                        $errors[] = "Chapter {$chapter->chapter_number}, Chunk {$chunk->chunk_number}: " . $e->getMessage();
                        Log::error("[AutoProcess] Chunk error: " . $e->getMessage());
                    }
                }

                // Step 4: If all chunks completed, merge into full audio
                $failedChunks = $chapter->chunks()->where('status', '!=', 'completed')->count();
                if ($failedChunks === 0) {
                    $mergedFile = $this->mergeChapterAudio($chapter);
                    if ($mergedFile) {
                        $chapter->audio_file = $mergedFile;
                        $chapter->status = 'completed';
                        $chapter->error_message = null;
                        Log::info("[AutoProcess] Chapter {$chapter->chapter_number} audio completed: {$mergedFile}");
                    } else {
                        $chapter->status = 'error';
                        $chapter->error_message = 'Merge failed';
                    }
                } else {
                    $chapter->status = 'error';
                    $chapter->error_message = "{$failedChunks} chunks failed";
                }

                $chapter->save();
                $chaptersProcessed++;
            } catch (\Exception $e) {
                Log::error("[AutoProcess] Chapter {$chapter->chapter_number} failed: " . $e->getMessage());
                $chapter->status = 'error';
                $chapter->error_message = $e->getMessage();
                $chapter->save();
            }
        }

        return [
            'chapters_processed' => $chaptersProcessed,
            'chunks_generated' => $chunksGenerated,
            'errors' => $errors,
        ];
    }

    /**
     * Create chunks for a chapter (split content into manageable pieces)
     */
    private function createChunksForChapter(AudioBookChapter $chapter): void
    {
        $content = trim($chapter->content);
        if (empty($content)) return;

        $chunks = $this->chunkContent($content, 2000);
        $chapter->total_chunks = count($chunks);
        $chapter->status = 'processing';
        $chapter->save();

        foreach ($chunks as $index => $chunkText) {
            AudioBookChapterChunk::create([
                'audiobook_chapter_id' => $chapter->id,
                'chunk_number' => $index + 1,
                'text_content' => $chunkText,
                'status' => 'pending',
            ]);
        }

        Log::info("[AutoProcess] Created {$chapter->total_chunks} chunks for chapter {$chapter->chapter_number}");
    }

    /**
     * Generate audio for a single chunk using TTSService
     */
    private function generateChunkAudio(AudioBookChapterChunk $chunk, AudioBookChapter $chapter, AudioBook $audioBook): void
    {
        $chunk->status = 'processing';
        $chunk->save();

        // Skip style_instruction for certain providers
        $providersWithoutStyle = ['microsoft', 'openai'];
        $styleInstruction = in_array($audioBook->tts_provider, $providersWithoutStyle)
            ? null
            : $audioBook->tts_style_instruction;

        $totalChunks = $chapter->chunks()->count();

        // Build text with intro/outro
        $textContent = $this->buildChunkTextWithIntroOutro($chunk, $chapter, $audioBook, $totalChunks);

        $ttsSpeed = (float) ($audioBook->tts_speed ?? 1.0);

        $audioPath = $this->ttsService->generateAudio(
            $textContent,
            $chunk->chunk_number,
            $audioBook->tts_voice_gender ?? 'female',
            $audioBook->tts_voice_name,
            $audioBook->tts_provider,
            $styleInstruction,
            null,
            $ttsSpeed
        );

        // Move to books/{book_id}/ directory
        $bookId = $chapter->audio_book_id;
        $outputDir = storage_path('app/public/books/' . $bookId);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $chapterNum = str_pad($chapter->chapter_number, 3, '0', STR_PAD_LEFT);
        $chunkNum = str_pad($chunk->chunk_number, 3, '0', STR_PAD_LEFT);
        $timestamp = time();
        $filename = "c_{$chapterNum}_{$chunkNum}_{$timestamp}.mp3";
        $outputPath = $outputDir . DIRECTORY_SEPARATOR . $filename;

        $sourcePath = storage_path('app/' . $audioPath);
        if (file_exists($sourcePath)) {
            copy($sourcePath, $outputPath);
            unlink($sourcePath);
        }

        $duration = $this->getAudioDuration($outputPath);

        $chunk->audio_file = 'books/' . $bookId . '/' . $filename;
        $chunk->duration = $duration;
        $chunk->status = 'completed';
        $chunk->save();
    }

    /**
     * Build chunk text with intro (for first chunk) and outro (for last chunk)
     * Replicates AudioBookChapterController::buildChunkTextWithIntroOutro()
     */
    private function buildChunkTextWithIntroOutro(
        AudioBookChapterChunk $chunk,
        AudioBookChapter $chapter,
        AudioBook $audioBook,
        int $totalChunks
    ): string {
        $text = $chunk->text_content;
        $chapterNumber = $chapter->chapter_number;
        $chapterTitle = $chapter->title;
        $bookTitle = $audioBook->title;
        $bookCategory = $audioBook->category ?? '';
        $bookType = $audioBook->book_type ?? 'sách';
        $author = $audioBook->author ?? '';
        $channelName = $audioBook->youtubeChannel->title ?? '';

        $bookTypeLabel = match ($bookType) {
            'truyen' => 'truyện',
            'tieu_thuyet' => 'tiểu thuyết',
            'truyen_ngan' => 'truyện ngắn',
            'sach' => 'sách',
            default => $bookType ?: 'sách',
        };

        $bookDesc = "bộ {$bookTypeLabel}";
        if ($bookCategory) {
            $bookDesc .= " {$bookCategory}";
        }
        $bookDesc .= " mang tên {$bookTitle}";
        if ($author) {
            $bookDesc .= " của nhà văn {$author}";
        }

        $totalBookChapters = $audioBook->chapters()->count();
        $isLastChapter = ($chapterNumber >= $totalBookChapters);

        // Chunk 1: Add chapter title intro
        if ($chunk->chunk_number === 1) {
            $hasChapterPrefix = preg_match('/^(Chương|Chapter|Phần)\s*\d+/iu', $chapterTitle);
            if ($hasChapterPrefix) {
                $intro = "{$chapterTitle}.\n\n";
            } else {
                $intro = "Chương {$chapterNumber}: {$chapterTitle}.\n\n";
            }
            $text = $intro . $text;
        }

        // Last chunk: Add outro
        if ($chunk->chunk_number === $totalChunks) {
            if ($isLastChapter) {
                $outro = "\n\nBạn vừa nghe xong chương {$chapterNumber}, chương cuối cùng của {$bookDesc}.";
                $outro .= " Cảm ơn bạn đã đồng hành cùng chúng tôi trong suốt tác phẩm này.";
                if ($channelName) {
                    $outro .= " Nếu bạn thích nội dung này, hãy nhấn like, subscribe và bật chuông thông báo để không bỏ lỡ những tác phẩm hay tiếp theo từ kênh {$channelName}.";
                    $outro .= " Sự ủng hộ của bạn là động lực lớn để chúng tôi tiếp tục sáng tạo. Hẹn gặp lại bạn!";
                } else {
                    $outro .= " Hẹn gặp lại bạn trong những tác phẩm tiếp theo.";
                }
            } else {
                $nextChapter = $chapterNumber + 1;
                $outro = "\n\nBạn vừa nghe xong chương {$chapterNumber} của {$bookDesc}.";
                $outro .= " Mời bạn tiếp tục nghe chương {$nextChapter}.";
                if ($channelName) {
                    $outro .= " Đừng quên like, subscribe và bật chuông để ủng hộ kênh {$channelName} nhé!";
                }
            }
            $text = $text . $outro;
        }

        return $text;
    }

    /**
     * Merge all chunk audio files into a single chapter audio file
     * Replicates AudioBookChapterController::mergeChapterAudio()
     */
    private function mergeChapterAudio(AudioBookChapter $chapter): ?string
    {
        try {
            $bookId = $chapter->audio_book_id;
            $audioBook = $chapter->audioBook;
            $chapterNum = str_pad($chapter->chapter_number, 3, '0', STR_PAD_LEFT);

            $chunks = $chapter->chunks()
                ->where('status', 'completed')
                ->whereNotNull('audio_file')
                ->orderBy('chunk_number')
                ->get();

            if ($chunks->isEmpty()) return null;

            $outputDir = storage_path('app/public/books/' . $bookId);
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            // Generate silence if configured
            $pauseDuration = (float) ($audioBook->pause_between_chunks ?? 0);
            $silenceFile = null;
            if ($pauseDuration > 0) {
                $silenceFile = $outputDir . DIRECTORY_SEPARATOR . "silence_{$chapterNum}.mp3";
                $silenceCmd = "ffmpeg -y -f lavfi -i anullsrc=r=44100:cl=stereo -t {$pauseDuration} -q:a 9 \"{$silenceFile}\" 2>&1";
                exec($silenceCmd, $silenceOutput, $silenceReturnCode);
                if ($silenceReturnCode !== 0 || !file_exists($silenceFile)) {
                    $silenceFile = null;
                }
            }

            // Build concat list
            $listFile = $outputDir . DIRECTORY_SEPARATOR . "concat_list_{$chapterNum}.txt";
            $listContent = "";
            $chunkCount = 0;
            $totalChunkCount = $chunks->count();

            foreach ($chunks as $chunk) {
                $chunkPath = storage_path('app/public/' . $chunk->audio_file);
                if (file_exists($chunkPath)) {
                    $chunkCount++;
                    $escapedPath = str_replace("'", "'\\''", str_replace('\\', '/', $chunkPath));
                    $listContent .= "file '{$escapedPath}'\n";

                    if ($silenceFile && $chunkCount < $totalChunkCount) {
                        $escapedSilence = str_replace("'", "'\\''", str_replace('\\', '/', $silenceFile));
                        $listContent .= "file '{$escapedSilence}'\n";
                    }
                }
            }

            if (empty($listContent)) {
                if ($silenceFile && file_exists($silenceFile)) unlink($silenceFile);
                return null;
            }

            file_put_contents($listFile, $listContent);

            $voiceOnlyFilename = "c_{$chapterNum}_voice.mp3";
            $voiceOnlyPath = $outputDir . DIRECTORY_SEPARATOR . $voiceOnlyFilename;
            $mergedFilename = "c_{$chapterNum}_full.mp3";
            $mergedPath = $outputDir . DIRECTORY_SEPARATOR . $mergedFilename;

            if (file_exists($voiceOnlyPath)) unlink($voiceOnlyPath);
            if (file_exists($mergedPath)) unlink($mergedPath);

            $command = "ffmpeg -f concat -safe 0 -i \"{$listFile}\" -c copy \"{$voiceOnlyPath}\" 2>&1";
            exec($command, $output, $returnCode);

            if (file_exists($listFile)) unlink($listFile);
            if ($silenceFile && file_exists($silenceFile)) unlink($silenceFile);

            if ($returnCode !== 0 || !file_exists($voiceOnlyPath)) return null;

            $voiceDuration = $this->getAudioDuration($voiceOnlyPath);
            if ($voiceDuration) {
                $chapter->total_duration = $voiceDuration;
            }

            // Add intro/outro music if configured
            $hasIntro = !empty($audioBook->intro_music) && file_exists(storage_path('app/public/' . $audioBook->intro_music));
            $outroUseIntro = $audioBook->outro_use_intro ?? false;
            $hasOutro = false;
            if (!empty($audioBook->outro_music) && file_exists(storage_path('app/public/' . $audioBook->outro_music))) {
                $hasOutro = true;
            } elseif ($outroUseIntro && $hasIntro) {
                $hasOutro = true;
            }

            if ($hasIntro || $hasOutro) {
                $finalPath = $this->addIntroOutroMusic($voiceOnlyPath, $mergedPath, $audioBook, $voiceDuration);
                if (file_exists($voiceOnlyPath) && file_exists($mergedPath)) {
                    unlink($voiceOnlyPath);
                }
                if (!$finalPath) {
                    rename($voiceOnlyPath, $mergedPath);
                }
            } else {
                rename($voiceOnlyPath, $mergedPath);
            }

            $totalDuration = $this->getAudioDuration($mergedPath);
            if ($totalDuration) {
                $chapter->total_duration = $totalDuration;
            }

            return 'books/' . $bookId . '/' . $mergedFilename;
        } catch (\Exception $e) {
            Log::error("[AutoProcess] Merge error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Add intro/outro music to voice audio with fade effects
     * Replicates AudioBookChapterController::addIntroOutroMusic()
     */
    private function addIntroOutroMusic(string $voicePath, string $outputPath, AudioBook $audioBook, float $voiceDuration): ?string
    {
        try {
            $introPath = $audioBook->intro_music ? storage_path('app/public/' . $audioBook->intro_music) : null;
            $outroUseIntro = $audioBook->outro_use_intro ?? false;
            $outroPath = ($outroUseIntro && $introPath) ? $introPath :
                ($audioBook->outro_music ? storage_path('app/public/' . $audioBook->outro_music) : null);

            $introFadeDuration = $audioBook->intro_fade_duration ?? 3;
            $outroFadeDuration = $audioBook->outro_fade_duration ?? 10;
            $outroExtendDuration = $audioBook->outro_extend_duration ?? 5;

            $hasIntro = $introPath && file_exists($introPath);
            $hasOutro = $outroPath && file_exists($outroPath);

            if (!$hasIntro && !$hasOutro) return null;

            $voicePathNorm = str_replace('\\', '/', $voicePath);
            $outputPathNorm = str_replace('\\', '/', $outputPath);

            $filterComplex = [];
            $inputs = ["-i \"{$voicePathNorm}\""];
            $inputIndex = 0;
            $voiceInput = "[{$inputIndex}:a]";
            $inputIndex++;

            $introDuration = 0;
            if ($hasIntro) {
                $introPathNorm = str_replace('\\', '/', $introPath);
                $inputs[] = "-i \"{$introPathNorm}\"";
                $introInput = "[{$inputIndex}:a]";
                $inputIndex++;

                $introMusicDuration = $this->getAudioDuration($introPath);
                $introDuration = min($introMusicDuration ?? 5, 5);
                $introFadeStart = max(0, $introDuration - 0.5);
                $filterComplex[] = "{$introInput}atrim=0:" . ($introDuration + $introFadeDuration) . ",afade=t=out:st={$introFadeStart}:d={$introFadeDuration}[intro]";
            }

            if ($hasOutro) {
                $outroPathNorm = str_replace('\\', '/', $outroPath);
                $inputs[] = "-i \"{$outroPathNorm}\"";
                $outroInput = "[{$inputIndex}:a]";
                $inputIndex++;

                $outroTotalDuration = $outroFadeDuration + $outroExtendDuration;
                $filterComplex[] = "{$outroInput}atrim=0:{$outroTotalDuration},afade=t=in:st=0:d={$outroFadeDuration},afade=t=out:st=" . ($outroTotalDuration - 2) . ":d=2[outro]";
            }

            if ($hasIntro && $hasOutro) {
                $filterComplex[] = "{$voiceInput}adelay=" . ($introDuration * 1000) . "|" . ($introDuration * 1000) . "[voicedelayed]";
                $outroDelay = ($introDuration + max(0, $voiceDuration - $outroFadeDuration)) * 1000;
                $filterComplex[] = "[outro]adelay={$outroDelay}|{$outroDelay}[outrodelayed]";
                $filterComplex[] = "[intro][voicedelayed]amix=inputs=2:duration=longest:normalize=0[premix]";
                $filterComplex[] = "[premix][outrodelayed]amix=inputs=2:duration=longest:normalize=0[final]";
            } elseif ($hasIntro) {
                $filterComplex[] = "{$voiceInput}adelay=" . ($introDuration * 1000) . "|" . ($introDuration * 1000) . "[voicedelayed]";
                $filterComplex[] = "[intro][voicedelayed]amix=inputs=2:duration=longest:normalize=0[final]";
            } elseif ($hasOutro) {
                $outroDelay = max(0, $voiceDuration - $outroFadeDuration) * 1000;
                $filterComplex[] = "[outro]adelay={$outroDelay}|{$outroDelay}[outrodelayed]";
                $filterComplex[] = "{$voiceInput}[outrodelayed]amix=inputs=2:duration=longest:normalize=0[final]";
            }

            $inputsStr = implode(' ', $inputs);
            $filterStr = implode(';', $filterComplex);
            $command = "ffmpeg -y {$inputsStr} -filter_complex \"{$filterStr}\" -map \"[final]\" -c:a libmp3lame -b:a 192k \"{$outputPathNorm}\" 2>&1";

            exec($command, $output, $returnCode);

            return $returnCode === 0 ? $outputPath : null;
        } catch (\Exception $e) {
            Log::error("[AutoProcess] Intro/outro error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Find and process audiobooks that need video generation
     */
    public function processPendingVideos(int $limit = 3): array
    {
        $processed = [];
        $completed = [];

        // Find audiobooks where chapters have audio + cover but no video
        $audiobooks = AudioBook::whereHas('chapters', function ($q) {
            $q->where('status', 'completed')
                ->whereNotNull('audio_file')
                ->whereNotNull('cover_image')
                ->where('cover_image', '!=', '')
                ->where(function ($q2) {
                    $q2->whereNull('video_path')
                        ->orWhere('video_path', '');
                });
        })
        ->limit($limit)
        ->get();

        foreach ($audiobooks as $audioBook) {
            try {
                Log::info("[AutoProcess] Processing videos for audiobook: {$audioBook->title} (ID: {$audioBook->id})");
                $result = $this->processBookVideos($audioBook);
                $processed[] = $audioBook->id;

                // Check if ALL chapters now have videos
                $chaptersWithContent = $audioBook->chapters()
                    ->whereNotNull('content')
                    ->where('content', '!=', '')
                    ->count();
                $chaptersWithVideo = $audioBook->chapters()
                    ->whereNotNull('video_path')
                    ->where('video_path', '!=', '')
                    ->count();

                if ($chaptersWithVideo >= $chaptersWithContent && $result['videos_created'] > 0) {
                    $completed[] = $audioBook->id;
                    $this->sendCompletionEmail($audioBook, 'video');
                }
            } catch (\Exception $e) {
                Log::error("[AutoProcess] Video processing failed for audiobook {$audioBook->id}: " . $e->getMessage());
            }
        }

        return [
            'processed' => $processed,
            'completed' => $completed,
            'found' => $audiobooks->count(),
        ];
    }

    /**
     * Generate videos for a single audiobook's chapters
     */
    private function processBookVideos(AudioBook $audioBook): array
    {
        $videosCreated = 0;
        $errors = [];

        $chapters = $audioBook->chapters()
            ->where('status', 'completed')
            ->whereNotNull('audio_file')
            ->whereNotNull('cover_image')
            ->where('cover_image', '!=', '')
            ->where(function ($q) {
                $q->whereNull('video_path')
                    ->orWhere('video_path', '');
            })
            ->orderBy('chapter_number')
            ->get();

        foreach ($chapters as $chapter) {
            try {
                $result = $this->generateChapterVideo($audioBook, $chapter);
                if ($result) {
                    $videosCreated++;
                    Log::info("[AutoProcess] Video created for chapter {$chapter->chapter_number}");
                }
            } catch (\Exception $e) {
                $errors[] = "Chapter {$chapter->chapter_number}: " . $e->getMessage();
                Log::error("[AutoProcess] Video error for chapter {$chapter->chapter_number}: " . $e->getMessage());
            }
        }

        // Also check for chapters with video_path set but file doesn't exist (broken videos)
        $brokenChapters = $audioBook->chapters()
            ->whereNotNull('video_path')
            ->where('video_path', '!=', '')
            ->whereNotNull('audio_file')
            ->whereNotNull('cover_image')
            ->get();

        foreach ($brokenChapters as $chapter) {
            $videoFile = storage_path('app/public/' . $chapter->video_path);
            if (!file_exists($videoFile)) {
                Log::info("[AutoProcess] Retrying broken video for chapter {$chapter->chapter_number}");
                try {
                    $chapter->video_path = null;
                    $chapter->save();
                    $result = $this->generateChapterVideo($audioBook, $chapter);
                    if ($result) {
                        $videosCreated++;
                    }
                } catch (\Exception $e) {
                    $errors[] = "Chapter {$chapter->chapter_number} (retry): " . $e->getMessage();
                }
            }
        }

        return [
            'videos_created' => $videosCreated,
            'errors' => $errors,
        ];
    }

    /**
     * Generate video for a single chapter (extracted from AudioBookController)
     */
    private function generateChapterVideo(AudioBook $audioBook, AudioBookChapter $chapter): bool
    {
        set_time_limit(0);

        // Check cover image
        $coverImagePath = storage_path('app/public/' . $chapter->cover_image);
        if (!file_exists($coverImagePath)) {
            throw new \Exception("Cover image not found: {$chapter->cover_image}");
        }

        // Check full audio file
        $chapterNumPadded = str_pad($chapter->chapter_number, 3, '0', STR_PAD_LEFT);
        $audioDir = "books/{$audioBook->id}";
        $fullAudioFilename = "c_{$chapterNumPadded}_full.mp3";
        $fullAudioPath = storage_path("app/public/{$audioDir}/{$fullAudioFilename}");

        if (!file_exists($fullAudioPath)) {
            // Try to auto-merge
            $mergedFile = $this->mergeChapterAudio($chapter);
            if ($mergedFile) {
                $chapter->audio_file = $mergedFile;
                $chapter->save();
            }
            if (!file_exists($fullAudioPath)) {
                throw new \Exception("Audio file not found: {$fullAudioFilename}");
            }
        }

        // Create MP4 output directory
        $mp4Dir = "books/{$audioBook->id}/mp4";
        $mp4DirPath = storage_path("app/public/{$mp4Dir}");
        if (!is_dir($mp4DirPath)) {
            mkdir($mp4DirPath, 0755, true);
        }

        $outputFilename = "chapter_{$chapter->chapter_number}.mp4";
        $outputPath = "{$mp4DirPath}/{$outputFilename}";

        if (file_exists($outputPath)) {
            unlink($outputPath);
        }

        $ffmpegPath = env('FFMPEG_PATH', 'ffmpeg');
        $imagePath = str_replace('\\', '/', $coverImagePath);
        $audioPath = str_replace('\\', '/', $fullAudioPath);
        $videoPath = str_replace('\\', '/', $outputPath);

        // Build video filter
        $waveEnabled = $audioBook->wave_enabled ?? false;
        $videoWidth = 1280;
        $videoHeight = 720;
        $baseFilter = "scale={$videoWidth}:{$videoHeight}:force_original_aspect_ratio=decrease,pad={$videoWidth}:{$videoHeight}:(ow-iw)/2:(oh-ih)/2";

        if ($waveEnabled) {
            $rawWaveType = $audioBook->wave_type ?? 'cline';
            $waveTypeMap = ['point' => 'point', 'line' => 'line', 'p2p' => 'p2p', 'cline' => 'cline', 'bar' => 'line'];
            $waveType = $waveTypeMap[$rawWaveType] ?? 'cline';
            $wavePosition = $audioBook->wave_position ?? 'bottom';
            $waveHeight = $audioBook->wave_height ?? 100;
            $waveWidthPercent = (int) ($audioBook->wave_width ?? 100);
            $waveColor = ltrim($audioBook->wave_color ?? '#00ff00', '#');
            $waveOpacity = $audioBook->wave_opacity ?? 0.8;

            $wavePixelWidth = (int) ($videoWidth * $waveWidthPercent / 100);
            $waveX = (int) (($videoWidth - $wavePixelWidth) / 2);

            $waveY = match ($wavePosition) {
                'top' => 20,
                'center' => ($videoHeight - $waveHeight) / 2,
                default => $videoHeight - $waveHeight - 20,
            };

            $filterComplex = sprintf(
                '[0:v]%s[bg];[1:a]showwaves=s=%dx%d:mode=%s:colors=0x%s@%.1f:rate=15[wave];[bg][wave]overlay=%d:%d:format=auto[out]',
                $baseFilter, $wavePixelWidth, $waveHeight, $waveType, $waveColor, $waveOpacity, $waveX, $waveY
            );

            $command = sprintf(
                '%s -y -loop 1 -framerate 15 -i %s -i %s -filter_complex "%s" -map "[out]" -map 1:a -c:v libx264 -preset ultrafast -tune stillimage -crf 28 -c:a aac -b:a 128k -pix_fmt yuv420p -shortest -threads 0 %s 2>&1',
                escapeshellarg($ffmpegPath),
                escapeshellarg($imagePath),
                escapeshellarg($audioPath),
                $filterComplex,
                escapeshellarg($videoPath)
            );
        } else {
            $command = sprintf(
                '%s -y -loop 1 -framerate 1 -i %s -i %s -c:v libx264 -preset ultrafast -tune stillimage -crf 28 -c:a aac -b:a 128k -pix_fmt yuv420p -r 15 -shortest -threads 0 -vf "%s" %s 2>&1',
                escapeshellarg($ffmpegPath),
                escapeshellarg($imagePath),
                escapeshellarg($audioPath),
                $baseFilter,
                escapeshellarg($videoPath)
            );
        }

        Log::info('[AutoProcess] FFmpeg command', ['chapter' => $chapter->chapter_number, 'command' => $command]);

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \Exception('FFmpeg failed: ' . implode("\n", array_slice($output, -3)));
        }

        $relativePath = "{$mp4Dir}/{$outputFilename}";
        $chapter->update(['video_path' => $relativePath]);

        return true;
    }

    /**
     * Send completion email to channel owner
     */
    public function sendCompletionEmail(AudioBook $audioBook, string $type): void
    {
        try {
            $channel = $audioBook->youtubeChannel;
            if (!$channel || empty($channel->youtube_connected_email)) {
                Log::warning("[AutoProcess] No email found for audiobook {$audioBook->id}");
                return;
            }

            $email = $channel->youtube_connected_email;

            // Collect stats
            $totalChapters = $audioBook->chapters()->count();
            $totalDuration = $audioBook->chapters()->sum('total_duration');

            Mail::to($email)->send(new AudiobookProcessingComplete(
                $audioBook,
                $type,
                [
                    'total_chapters' => $totalChapters,
                    'total_duration' => $totalDuration,
                    'channel_name' => $channel->title ?? '',
                ]
            ));

            Log::info("[AutoProcess] Email sent to {$email} for audiobook {$audioBook->id} ({$type})");
        } catch (\Exception $e) {
            Log::error("[AutoProcess] Email failed: " . $e->getMessage());
        }
    }

    /**
     * Get list of audiobooks that would be processed (dry run)
     */
    public function getDryRunInfo(int $limit = 3): array
    {
        $ttsPending = AudioBook::whereNotNull('tts_provider')
            ->whereNotNull('tts_voice_name')
            ->whereHas('chapters', function ($q) {
                $q->whereNotNull('content')
                    ->where('content', '!=', '')
                    ->where(function ($q2) {
                        $q2->where('status', '!=', 'completed')
                            ->orWhereHas('chunks', function ($q3) {
                                $q3->whereIn('status', ['error', 'pending']);
                            })
                            ->orWhereDoesntHave('chunks');
                    });
            })
            ->limit($limit)
            ->get(['id', 'title', 'tts_provider']);

        $videoPending = AudioBook::whereHas('chapters', function ($q) {
            $q->where('status', 'completed')
                ->whereNotNull('audio_file')
                ->whereNotNull('cover_image')
                ->where('cover_image', '!=', '')
                ->where(function ($q2) {
                    $q2->whereNull('video_path')
                        ->orWhere('video_path', '');
                });
        })
        ->limit($limit)
        ->get(['id', 'title']);

        return [
            'tts_pending' => $ttsPending->toArray(),
            'video_pending' => $videoPending->toArray(),
        ];
    }

    /**
     * Split content into chunks (max size, prefer sentence/paragraph boundaries)
     */
    private function chunkContent(string $content, int $maxSize = 2000): array
    {
        $chunks = [];
        $content = trim($content);
        $paragraphs = preg_split('/\n\s*\n/', $content);
        $currentChunk = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if (empty($paragraph)) continue;

            if (mb_strlen($paragraph, 'UTF-8') > $maxSize) {
                if (!empty($currentChunk)) {
                    $chunks[] = trim($currentChunk);
                    $currentChunk = '';
                }

                $sentences = preg_split('/(?<=[.!?。！？])\s+/', $paragraph);
                foreach ($sentences as $sentence) {
                    $sentence = trim($sentence);
                    if (empty($sentence)) continue;

                    if (mb_strlen($sentence, 'UTF-8') > $maxSize) {
                        if (!empty($currentChunk)) {
                            $chunks[] = trim($currentChunk);
                            $currentChunk = '';
                        }
                        $words = explode(' ', $sentence);
                        $tempChunk = '';
                        foreach ($words as $word) {
                            if (mb_strlen($tempChunk . ' ' . $word, 'UTF-8') > $maxSize) {
                                if (!empty($tempChunk)) $chunks[] = trim($tempChunk);
                                $tempChunk = $word;
                            } else {
                                $tempChunk = empty($tempChunk) ? $word : $tempChunk . ' ' . $word;
                            }
                        }
                        if (!empty($tempChunk)) $currentChunk = $tempChunk;
                    } else {
                        if (mb_strlen($currentChunk . ' ' . $sentence, 'UTF-8') > $maxSize) {
                            if (!empty($currentChunk)) $chunks[] = trim($currentChunk);
                            $currentChunk = $sentence;
                        } else {
                            $currentChunk = empty($currentChunk) ? $sentence : $currentChunk . ' ' . $sentence;
                        }
                    }
                }
            } else {
                if (mb_strlen($currentChunk . "\n\n" . $paragraph, 'UTF-8') > $maxSize) {
                    if (!empty($currentChunk)) $chunks[] = trim($currentChunk);
                    $currentChunk = $paragraph;
                } else {
                    $currentChunk = empty($currentChunk) ? $paragraph : $currentChunk . "\n\n" . $paragraph;
                }
            }
        }

        if (!empty($currentChunk)) {
            $chunks[] = trim($currentChunk);
        }

        return $chunks;
    }

    /**
     * Get audio duration using ffprobe
     */
    private function getAudioDuration(string $filePath): ?float
    {
        if (!file_exists($filePath)) return null;

        $command = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 \"{$filePath}\" 2>&1";
        exec($command, $output, $returnCode);

        if ($returnCode === 0 && isset($output[0]) && is_numeric($output[0])) {
            return (float) $output[0];
        }

        $fileSize = filesize($filePath);
        return $fileSize / (128 * 1024 / 8);
    }
}
