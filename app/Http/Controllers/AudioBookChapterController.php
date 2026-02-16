<?php

namespace App\Http\Controllers;

use App\Models\AudioBook;
use App\Models\AudioBookChapter;
use App\Models\AudioBookChapterChunk;
use App\Services\TTSService;
use App\Jobs\GenerateChapterTtsBatchJob;
use Illuminate\Http\Request;
use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class AudioBookChapterController extends Controller
{
    protected $ttsService;

    public function __construct(TTSService $ttsService)
    {
        $this->ttsService = $ttsService;
    }

    /**
     * Create chapter form
     */
    public function create(AudioBook $audioBook)
    {
        $nextChapter = $audioBook->chapters()->max('chapter_number') + 1;
        return view('audiobooks.chapters.create', compact('audioBook', 'nextChapter'));
    }

    /**
     * Store chapter
     */
    public function store(Request $request, AudioBook $audioBook)
    {
        $data = $request->validate([
            'chapter_number' => 'required|integer|min:1',
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'cover_image' => 'nullable|image|max:2048',
            'tts_voice' => 'required|string',
            'tts_speed' => 'required|numeric|between:0.5,2.0'
        ]);

        $data['audio_book_id'] = $audioBook->id;

        if ($request->hasFile('cover_image')) {
            $path = $request->file('cover_image')->store('audiobook_chapters', 'public');
            $data['cover_image'] = $path;
        }

        $chapter = AudioBookChapter::create($data);

        // Split content into chunks
        $chunks = $chapter->splitIntoChunks(1000);
        $chapter->total_chunks = count($chunks);
        $chapter->save();

        // Create chunk records
        foreach ($chunks as $index => $chunk) {
            AudioBookChapterChunk::create([
                'audiobook_chapter_id' => $chapter->id,
                'chunk_number' => $index + 1,
                'text_content' => $chunk,
                'status' => 'pending'
            ]);
        }

        // Update book total chapters
        $audioBook->total_chapters = $audioBook->chapters()->count();
        $audioBook->save();

        return redirect()->route('audiobooks.show', $audioBook)->with('success', 'Chương đã được tạo với ' . count($chunks) . ' đoạn');
    }

    /**
     * Edit chapter form
     */
    public function edit(AudioBook $audioBook, AudioBookChapter $chapter)
    {
        return view('audiobooks.chapters.edit', compact('audioBook', 'chapter'));
    }

    /**
     * Update chapter
     */
    public function update(Request $request, AudioBook $audioBook, AudioBookChapter $chapter)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'cover_image' => 'nullable|image|max:2048',
            'tts_voice' => 'required|string',
            'tts_speed' => 'required|numeric|between:0.5,2.0'
        ]);

        if ($request->hasFile('cover_image')) {
            $path = $request->file('cover_image')->store('audiobook_chapters', 'public');
            $data['cover_image'] = $path;
        }

        // If content changed, re-split into chunks
        if ($data['content'] !== $chapter->content) {
            // Delete old chunks and their audio files
            foreach ($chapter->chunks as $oldChunk) {
                if ($oldChunk->audio_file) {
                    $filePath = storage_path('app/public/' . $oldChunk->audio_file);
                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }
                }
            }
            $chapter->chunks()->delete();

            // Also delete merged audio file if it exists
            if ($chapter->audio_file) {
                $mergedPath = storage_path('app/public/' . $chapter->audio_file);
                if (file_exists($mergedPath)) {
                    unlink($mergedPath);
                }
                $data['audio_file'] = null;
            }

            // Update content on model BEFORE splitting so splitIntoChunks reads new content
            $chapter->content = $data['content'];

            // Create new chunks from NEW content
            $chunks = $chapter->splitIntoChunks(1000);
            $data['total_chunks'] = count($chunks);

            foreach ($chunks as $index => $chunk) {
                AudioBookChapterChunk::create([
                    'audiobook_chapter_id' => $chapter->id,
                    'chunk_number' => $index + 1,
                    'text_content' => $chunk,
                    'status' => 'pending'
                ]);
            }

            $data['status'] = 'pending';
        }

        $chapter->update($data);

        return redirect()->route('audiobooks.show', $audioBook)->with('success', 'Chương đã được cập nhật');
    }

    /**
     * Delete chapter
     */
    public function destroy(AudioBook $audioBook, AudioBookChapter $chapter)
    {
        $chapter->delete();
        $audioBook->total_chapters = $audioBook->chapters()->count();
        $audioBook->save();

        return redirect()->route('audiobooks.show', $audioBook)->with('success', 'Chương đã bị xóa');
    }

    /**
     * Generate TTS for chapter chunks
     */
    public function generateTts(AudioBook $audioBook, AudioBookChapter $chapter)
    {
        // Re-read chapter content from DB to ensure we have the latest
        $chapter->refresh();

        $chapter->status = 'processing';
        $chapter->save();

        // Check if existing chunks match current content
        $existingChunks = $chapter->chunks()->orderBy('chunk_number')->get();
        $needsRechunk = $existingChunks->isEmpty();

        if (!$needsRechunk) {
            $existingText = $existingChunks->pluck('text_content')->implode("\n\n");
            if (trim($chapter->content) !== trim($existingText)) {
                // Content changed — delete old chunks and re-create
                $chapter->chunks()->delete();
                $chunks = $chapter->splitIntoChunks(1000);
                $chapter->total_chunks = count($chunks);
                $chapter->save();

                foreach ($chunks as $index => $chunkText) {
                    AudioBookChapterChunk::create([
                        'audiobook_chapter_id' => $chapter->id,
                        'chunk_number' => $index + 1,
                        'text_content' => $chunkText,
                        'status' => 'pending'
                    ]);
                }
            }
        }

        // Process each chunk
        foreach ($chapter->chunks()->orderBy('chunk_number')->get() as $chunk) {
            try {
                $this->generateChunkAudio($chunk, $chapter);
            } catch (\Exception $e) {
                $chunk->status = 'error';
                $chunk->error_message = $e->getMessage();
                $chunk->save();
            }
        }

        // Mark chapter as completed if all chunks succeeded
        $failedChunks = $chapter->chunks()->where('status', '!=', 'completed')->count();
        if ($failedChunks === 0) {
            $chapter->status = 'completed';
        } else {
            $chapter->status = 'error';
            $chapter->error_message = "$failedChunks chunks failed";
        }
        $chapter->save();

        return back()->with('success', 'Quá trình tạo TTS đã hoàn tất');
    }

    /**
     * Queue TTS generation for selected chapters (async).
     */
    public function startTtsBatch(Request $request, AudioBook $audioBook)
    {
        $request->validate([
            'chapter_ids' => 'required|array|min:1',
            'chapter_ids.*' => 'integer',
            'provider' => 'required|string|in:openai,gemini,microsoft,vbee',
            'voice_name' => 'required|string',
            'voice_gender' => 'nullable|string|in:male,female',
            'style_instruction' => 'nullable|string',
            'tts_speed' => 'nullable|numeric|between:0.5,2.0',
            'pause_between_chunks' => 'nullable|numeric|between:0,5'
        ]);

        $this->initTtsBatchProgress($audioBook->id, 'queued', 'Da dua vao hang doi, co the tat trinh duyet.');

        GenerateChapterTtsBatchJob::dispatch(
            $audioBook->id,
            $request->input('chapter_ids'),
            $request->only([
                'provider',
                'voice_name',
                'voice_gender',
                'style_instruction',
                'tts_speed',
                'pause_between_chunks'
            ])
        );

        return response()->json([
            'success' => true,
            'queued' => true,
            'message' => 'Da dua vao hang doi xu ly.'
        ]);
    }

    /**
     * Get background TTS progress for an audiobook.
     */
    public function getTtsBatchProgress(AudioBook $audioBook)
    {
        $progress = Cache::get("tts_batch_progress_{$audioBook->id}");
        if (!$progress) {
            return response()->json([
                'success' => true,
                'status' => 'idle'
            ]);
        }

        $progress['logs'] = Cache::get("tts_batch_logs_{$audioBook->id}", []);

        return response()->json(array_merge(['success' => true], $progress));
    }

    private function initTtsBatchProgress(int $audioBookId, string $status, string $message): void
    {
        Cache::put("tts_batch_progress_{$audioBookId}", [
            'status' => $status,
            'percent' => 1,
            'message' => $message,
            'current_chapter_number' => null,
            'current_chunk_number' => null,
            'current_chunk_total' => null,
            'chunk_percent' => null,
            'logs' => [],
            'updated_at' => now()->toIso8601String(),
        ], now()->addHours(6));

        Cache::put("tts_batch_logs_{$audioBookId}", [], now()->addHours(6));
    }

    /**
     * Generate audio for a single chunk
     */
    private function generateChunkAudio(AudioBookChapterChunk $chunk, AudioBookChapter $chapter)
    {
        $chunk->status = 'processing';
        $chunk->save();

        $outputDir = storage_path('app/public/audiobooks/audio');
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $filename = 'chapter_' . $chapter->id . '_chunk_' . $chunk->chunk_number . '.mp3';
        $outputPath = $outputDir . DIRECTORY_SEPARATOR . $filename;

        // Run Edge TTS
        $python = 'D:\\Download\\apps\\laragon\\www\\sumotech\\.venv\\Scripts\\python.exe';
        $script = storage_path('scripts/edge_tts_generate.py');

        $process = new Process([
            $python,
            $script,
            '--text',
            $chunk->text_content,
            '--out',
            $outputPath,
            '--voice',
            $chapter->tts_voice
        ]);

        $process->setEnv([
            'VIRTUAL_ENV' => 'D:\\Download\\apps\\laragon\\www\\sumotech\\.venv',
            'PATH' => 'D:\\Download\\apps\\laragon\\www\\sumotech\\.venv\\Scripts;' . getenv('PATH'),
        ]);

        $process->setTimeout(300);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \Exception($process->getErrorOutput() ?: 'TTS generation failed');
        }

        $chunk->audio_file = 'audiobooks/audio/' . $filename;
        $chunk->status = 'completed';
        $chunk->save();
    }

    /**
     * Initialize chunks for a chapter (split content, create chunk records)
     * Does NOT generate audio - just prepares the chunks
     */
    public function initializeChunks(Request $request, AudioBook $audioBook, AudioBookChapter $chapter)
    {
        try {
            $request->validate([
                'provider' => 'required|string|in:openai,gemini,microsoft,vbee',
                'voice_name' => 'required|string',
                'voice_gender' => 'nullable|string|in:male,female',
                'style_instruction' => 'nullable|string',
                'tts_speed' => 'nullable|numeric|between:0.5,2.0',
                'pause_between_chunks' => 'nullable|numeric|between:0,5'
            ]);

            // Update audiobook TTS settings
            $updateData = [
                'tts_provider' => $request->provider,
                'tts_voice_name' => $request->voice_name,
                'tts_voice_gender' => $request->voice_gender ?? 'female',
                'tts_style_instruction' => $request->style_instruction
            ];
            if ($request->has('tts_speed')) {
                $updateData['tts_speed'] = $request->tts_speed;
            }
            if ($request->has('pause_between_chunks')) {
                $updateData['pause_between_chunks'] = $request->pause_between_chunks;
            }
            $audioBook->update($updateData);

            // Update chapter voice
            $chapter->tts_voice = $request->voice_name;
            $chapter->save();

            // Re-read chapter content from DB to ensure we have the latest
            $chapter->refresh();

            // Check existing chunks
            $existingChunks = $chapter->chunks()->orderBy('chunk_number')->get();

            // Determine if chunks need to be recreated:
            // 1. No chunks exist yet
            // 2. Chapter content has changed since chunks were created
            $needsRechunk = $existingChunks->isEmpty();

            if (!$needsRechunk && !$existingChunks->isEmpty()) {
                // Reconstruct text from existing chunks and compare with current content
                $existingText = $existingChunks->pluck('text_content')->implode("\n\n");
                $currentContent = trim($chapter->content);
                $existingTextNormalized = trim($existingText);

                // Compare normalized versions (ignore minor whitespace differences)
                if ($currentContent !== $existingTextNormalized) {
                    Log::info("Chapter {$chapter->id} content changed since chunks were created. Re-chunking.", [
                        'existing_text_length' => mb_strlen($existingTextNormalized),
                        'current_content_length' => mb_strlen($currentContent),
                    ]);
                    $needsRechunk = true;
                }
            }

            if ($needsRechunk) {
                // Delete old chunks (and their audio files) before re-creating
                foreach ($existingChunks as $oldChunk) {
                    if ($oldChunk->audio_file) {
                        $filePath = storage_path('app/public/' . $oldChunk->audio_file);
                        if (file_exists($filePath)) {
                            unlink($filePath);
                        }
                    }
                }
                $chapter->chunks()->delete();

                // Also delete merged audio file if it exists
                if ($chapter->audio_file) {
                    $mergedPath = storage_path('app/public/' . $chapter->audio_file);
                    if (file_exists($mergedPath)) {
                        unlink($mergedPath);
                    }
                    $chapter->audio_file = null;
                }

                $content = $chapter->content;
                $chunksText = $this->chunkContent($content, 2000);

                foreach ($chunksText as $index => $chunkText) {
                    AudioBookChapterChunk::create([
                        'audiobook_chapter_id' => $chapter->id,
                        'chunk_number' => $index + 1,
                        'text_content' => $chunkText,
                        'status' => 'pending'
                    ]);
                }

                $chapter->total_chunks = count($chunksText);
                $chapter->status = 'processing';
                $chapter->save();
            }

            // Get all chunks with their status
            $chunks = $chapter->chunks()->orderBy('chunk_number')->get()->map(function ($chunk) {
                return [
                    'id' => $chunk->id,
                    'chunk_number' => $chunk->chunk_number,
                    'status' => $chunk->status,
                    'audio_file' => $chunk->audio_file,
                    'duration' => $chunk->duration,
                    'text_preview' => mb_substr($chunk->text_content, 0, 50) . '...'
                ];
            });

            // Count pending chunks (need to generate)
            $pendingChunks = $chapter->chunks()->where('status', 'pending')->count();
            $completedChunks = $chapter->chunks()->where('status', 'completed')->count();

            return response()->json([
                'success' => true,
                'chapter_id' => $chapter->id,
                'chapter_number' => $chapter->chapter_number,
                'total_chunks' => $chapter->chunks()->count(),
                'pending_chunks' => $pendingChunks,
                'completed_chunks' => $completedChunks,
                'chunks' => $chunks
            ]);
        } catch (\Exception $e) {
            Log::error("Initialize chunks failed for chapter {$chapter->id}: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate audio for a single chunk
     */
    public function generateSingleChunk(Request $request, AudioBook $audioBook, AudioBookChapter $chapter, AudioBookChapterChunk $chunk)
    {
        try {
            $request->validate([
                'provider' => 'required|string|in:openai,gemini,microsoft,vbee',
                'voice_name' => 'required|string',
                'voice_gender' => 'nullable|string|in:male,female',
                'style_instruction' => 'nullable|string'
            ]);

            // Skip if already completed
            if ($chunk->status === 'completed' && $chunk->audio_file && file_exists(storage_path('app/public/' . $chunk->audio_file))) {
                return response()->json([
                    'success' => true,
                    'skipped' => true,
                    'chunk_number' => $chunk->chunk_number,
                    'audio_file' => $chunk->audio_file,
                    'audio_url' => asset('storage/' . $chunk->audio_file),
                    'duration' => $chunk->duration
                ]);
            }

            $chunk->status = 'processing';
            $chunk->save();

            // Generate audio
            $this->generateChunkAudioWithService($chunk, $chapter, $request);

            // Refresh chunk data
            $chunk->refresh();

            return response()->json([
                'success' => true,
                'skipped' => false,
                'chunk_number' => $chunk->chunk_number,
                'audio_file' => $chunk->audio_file,
                'audio_url' => asset('storage/' . $chunk->audio_file),
                'duration' => $chunk->duration
            ]);
        } catch (\Exception $e) {
            Log::error("Generate single chunk failed: " . $e->getMessage());

            $chunk->status = 'error';
            $chunk->error_message = $e->getMessage();
            $chunk->save();

            return response()->json([
                'success' => false,
                'chunk_number' => $chunk->chunk_number,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Merge all completed chunks into a full chapter audio file
     */
    public function mergeChapterAudioEndpoint(Request $request, AudioBook $audioBook, AudioBookChapter $chapter)
    {
        try {
            $mergedFile = $this->mergeChapterAudio($chapter);

            if ($mergedFile) {
                $chapter->audio_file = $mergedFile;
                $chapter->status = 'completed';
                $chapter->save();

                return response()->json([
                    'success' => true,
                    'merged_file' => $mergedFile,
                    'audio_url' => asset('storage/' . $mergedFile),
                    'duration' => $chapter->total_duration
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => 'Không thể ghép file audio'
            ], 500);
        } catch (\Exception $e) {
            Log::error("Merge chapter audio failed: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete audio for a single chunk
     */
    public function deleteChunkAudio(AudioBook $audioBook, AudioBookChapter $chapter, AudioBookChapterChunk $chunk)
    {
        try {
            // Verify chunk belongs to this chapter
            if ($chunk->audiobook_chapter_id !== $chapter->id) {
                return response()->json(['success' => false, 'error' => 'Chunk không thuộc chương này'], 403);
            }

            $deletedFile = null;

            if ($chunk->audio_file) {
                $filePath = storage_path('app/public/' . $chunk->audio_file);
                if (file_exists($filePath)) {
                    unlink($filePath);
                    $deletedFile = $chunk->audio_file;
                }
                $chunk->audio_file = null;
                $chunk->duration = 0;
                $chunk->status = 'pending';
                $chunk->error_message = null;
                $chunk->save();
            }

            return response()->json([
                'success' => true,
                'deleted_file' => $deletedFile,
                'chunk_id' => $chunk->id,
                'chunk_number' => $chunk->chunk_number,
            ]);
        } catch (\Exception $e) {
            Log::error("Delete chunk audio failed: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete audio files for a chapter (chunks and/or merged)
     */
    public function deleteAudio(Request $request, AudioBook $audioBook, AudioBookChapter $chapter)
    {
        try {
            $deleteChunks = $request->input('delete_chunks', true);
            $deleteMerged = $request->input('delete_merged', true);
            $deletedFiles = [];

            $bookId = $audioBook->id;
            $basePath = storage_path('app/public/books/' . $bookId);

            if ($deleteChunks) {
                foreach ($chapter->chunks as $chunk) {
                    if ($chunk->audio_file) {
                        $filePath = storage_path('app/public/' . $chunk->audio_file);
                        if (file_exists($filePath)) {
                            unlink($filePath);
                            $deletedFiles[] = $chunk->audio_file;
                        }
                        $chunk->audio_file = null;
                        $chunk->duration = 0;
                        $chunk->status = 'pending';
                        $chunk->save();
                    }
                }
            }

            if ($deleteMerged && $chapter->audio_file) {
                $filePath = storage_path('app/public/' . $chapter->audio_file);
                if (file_exists($filePath)) {
                    unlink($filePath);
                    $deletedFiles[] = $chapter->audio_file;
                }
                $chapter->audio_file = null;
                $chapter->total_duration = 0;
                $chapter->save();
            }

            // Update chapter status
            if ($deleteChunks) {
                $chapter->status = 'pending';
                $chapter->save();
            }

            return response()->json([
                'success' => true,
                'deleted_files' => $deletedFiles,
                'count' => count($deletedFiles)
            ]);
        } catch (\Exception $e) {
            Log::error("Delete audio failed: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate TTS chunks from chapter content
     * Chunks content into max 2000 characters and generates audio for each
     */
    public function generateTtsChunks(Request $request, AudioBook $audioBook, AudioBookChapter $chapter)
    {
        try {
            $request->validate([
                'provider' => 'required|string|in:openai,gemini,microsoft,vbee',
                'voice_name' => 'required|string',
                'voice_gender' => 'nullable|string|in:male,female',
                'style_instruction' => 'nullable|string',
                'tts_speed' => 'nullable|numeric|between:0.5,2.0',
                'pause_between_chunks' => 'nullable|numeric|between:0,5'
            ]);

            Log::info("Starting TTS chunk generation for chapter {$chapter->id}");

            // Update audiobook TTS settings
            $updateData = [
                'tts_provider' => $request->provider,
                'tts_voice_name' => $request->voice_name,
                'tts_voice_gender' => $request->voice_gender ?? 'female',
                'tts_style_instruction' => $request->style_instruction
            ];
            if ($request->has('tts_speed')) {
                $updateData['tts_speed'] = $request->tts_speed;
            }
            if ($request->has('pause_between_chunks')) {
                $updateData['pause_between_chunks'] = $request->pause_between_chunks;
            }
            $audioBook->update($updateData);

            // Set chapter to processing
            $chapter->status = 'processing';
            $chapter->tts_voice = $request->voice_name;
            $chapter->save();

            // Delete existing chunks
            $chapter->chunks()->delete();

            // Chunk content into max 2000 characters
            $content = $chapter->content;
            $chunks = $this->chunkContent($content, 2000);

            Log::info("Split chapter {$chapter->id} into " . count($chunks) . " chunks");

            $chunkNumber = 1;
            $errors = [];

            foreach ($chunks as $chunkText) {
                try {
                    // Create chunk record
                    $chunk = AudioBookChapterChunk::create([
                        'audiobook_chapter_id' => $chapter->id,
                        'chunk_number' => $chunkNumber,
                        'text_content' => $chunkText,
                        'status' => 'processing'
                    ]);

                    // Generate audio
                    $this->generateChunkAudioWithService($chunk, $chapter, $request);

                    $chunkNumber++;
                } catch (\Exception $e) {
                    Log::error("Error generating chunk {$chunkNumber} for chapter {$chapter->id}: " . $e->getMessage());
                    $errors[] = "Chunk {$chunkNumber}: " . $e->getMessage();

                    if (isset($chunk)) {
                        $chunk->status = 'error';
                        $chunk->error_message = $e->getMessage();
                        $chunk->save();
                    }

                    $chunkNumber++;
                }
            }

            // Update chapter status
            $failedChunks = $chapter->chunks()->where('status', 'error')->count();
            if ($failedChunks === 0) {
                $chapter->status = 'completed';
                $chapter->total_chunks = count($chunks);
                $chapter->error_message = null;

                // Merge all chunks into a single file
                $mergedFile = $this->mergeChapterAudio($chapter);
                if ($mergedFile) {
                    $chapter->audio_file = $mergedFile;
                    $chapter->save();
                    Log::info("Merged audio for chapter {$chapter->id}: {$mergedFile}");
                }
            } else {
                $chapter->status = 'error';
                $chapter->error_message = "$failedChunks/" . count($chunks) . " chunks failed";
            }
            $chapter->save();

            return response()->json([
                'success' => $failedChunks === 0,
                'message' => "Generated " . (count($chunks) - $failedChunks) . "/" . count($chunks) . " chunks",
                'chunks_count' => count($chunks) - $failedChunks,
                'total_chunks' => count($chunks),
                'merged_file' => $mergedFile ?? null,
                'errors' => $errors
            ]);
        } catch (\Exception $e) {
            Log::error("TTS chunk generation failed for chapter {$chapter->id}: " . $e->getMessage());

            $chapter->status = 'error';
            $chapter->error_message = $e->getMessage();
            $chapter->save();

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Split content into chunks of max size, preferring to break at sentence/paragraph boundaries
     */
    private function chunkContent(string $content, int $maxSize = 2000): array
    {
        $chunks = [];
        $content = trim($content);

        // Split by paragraphs first
        $paragraphs = preg_split('/\n\s*\n/', $content);

        $currentChunk = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if (empty($paragraph)) continue;

            // If paragraph itself is too long, split by sentences
            if (mb_strlen($paragraph, 'UTF-8') > $maxSize) {
                // Flush current chunk first
                if (!empty($currentChunk)) {
                    $chunks[] = trim($currentChunk);
                    $currentChunk = '';
                }

                // Split paragraph by sentences
                $sentences = preg_split('/(?<=[.!?。！？])\s+/', $paragraph);

                foreach ($sentences as $sentence) {
                    $sentence = trim($sentence);
                    if (empty($sentence)) continue;

                    // If sentence itself is too long, force split
                    if (mb_strlen($sentence, 'UTF-8') > $maxSize) {
                        if (!empty($currentChunk)) {
                            $chunks[] = trim($currentChunk);
                            $currentChunk = '';
                        }

                        // Force split at max size, preferring space boundaries
                        $words = explode(' ', $sentence);
                        $tempChunk = '';
                        foreach ($words as $word) {
                            if (mb_strlen($tempChunk . ' ' . $word, 'UTF-8') > $maxSize) {
                                if (!empty($tempChunk)) {
                                    $chunks[] = trim($tempChunk);
                                }
                                $tempChunk = $word;
                            } else {
                                $tempChunk = empty($tempChunk) ? $word : $tempChunk . ' ' . $word;
                            }
                        }
                        if (!empty($tempChunk)) {
                            $currentChunk = $tempChunk;
                        }
                    } else {
                        // Normal sentence
                        if (mb_strlen($currentChunk . ' ' . $sentence, 'UTF-8') > $maxSize) {
                            if (!empty($currentChunk)) {
                                $chunks[] = trim($currentChunk);
                            }
                            $currentChunk = $sentence;
                        } else {
                            $currentChunk = empty($currentChunk) ? $sentence : $currentChunk . ' ' . $sentence;
                        }
                    }
                }
            } else {
                // Normal paragraph
                if (mb_strlen($currentChunk . "\n\n" . $paragraph, 'UTF-8') > $maxSize) {
                    if (!empty($currentChunk)) {
                        $chunks[] = trim($currentChunk);
                    }
                    $currentChunk = $paragraph;
                } else {
                    $currentChunk = empty($currentChunk) ? $paragraph : $currentChunk . "\n\n" . $paragraph;
                }
            }
        }

        // Add remaining chunk
        if (!empty($currentChunk)) {
            $chunks[] = trim($currentChunk);
        }

        return $chunks;
    }

    /**
     * Generate audio using TTSService
     * Files saved to: storage/app/public/books/{book_id}/c_{chunk_number}_{timestamp}.mp3
     */
    private function generateChunkAudioWithService(AudioBookChapterChunk $chunk, AudioBookChapter $chapter, Request $request)
    {
        $chunk->status = 'processing';
        $chunk->save();

        // Skip style_instruction for Microsoft and OpenAI TTS
        $providersWithoutStyle = ['microsoft', 'openai'];
        $styleInstruction = in_array($request->provider, $providersWithoutStyle)
            ? null
            : $request->style_instruction;

        // Get audiobook info for intro/outro
        $audioBook = $chapter->audioBook;
        $totalChunks = $chapter->chunks()->count();

        // Build the text content with intro/outro
        $textContent = $this->buildChunkTextWithIntroOutro($chunk, $chapter, $audioBook, $totalChunks);

        // Get TTS speed from audiobook settings
        $ttsSpeed = (float) ($audioBook->tts_speed ?? 1.0);

        // Use TTSService - $index parameter is just for filename uniqueness
        $audioPath = $this->ttsService->generateAudio(
            $textContent,
            $chunk->chunk_number, // index
            $request->voice_gender ?? 'female',
            $request->voice_name,
            $request->provider,
            $styleInstruction,
            null, // projectId
            $ttsSpeed
        );

        // Save to storage/books/{book_id}/c_{chapter}_{chunk}_{timestamp}.mp3
        $bookId = $chapter->audio_book_id;
        $outputDir = storage_path('app/public/books/' . $bookId);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        // Format: c_{chapter_number}_{chunk_number}_{timestamp}.mp3
        $chapterNum = str_pad($chapter->chapter_number, 3, '0', STR_PAD_LEFT);
        $chunkNum = str_pad($chunk->chunk_number, 3, '0', STR_PAD_LEFT);
        $timestamp = time();
        $filename = "c_{$chapterNum}_{$chunkNum}_{$timestamp}.mp3";
        $outputPath = $outputDir . DIRECTORY_SEPARATOR . $filename;

        $sourcePath = storage_path('app/' . $audioPath);
        if (file_exists($sourcePath)) {
            copy($sourcePath, $outputPath);
            unlink($sourcePath); // Clean up original
        }

        // Get audio duration using ffprobe if available
        $duration = $this->getAudioDuration($outputPath);

        // Store relative path: books/{book_id}/filename.mp3
        $chunk->audio_file = 'books/' . $bookId . '/' . $filename;
        $chunk->duration = $duration;
        $chunk->status = 'completed';
        $chunk->save();

        Log::info("Generated audio for chapter {$chapter->chapter_number} chunk {$chunk->chunk_number}: {$filename}");
    }

    /**
     * Build chunk text with intro (for first chunk) and outro (for last chunk)
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

        // Build book type label
        $bookTypeLabel = match ($bookType) {
            'truyen' => 'truyện',
            'tieu_thuyet' => 'tiểu thuyết',
            'truyen_ngan' => 'truyện ngắn',
            'sach' => 'sách',
            default => $bookType ?: 'sách',
        };

        // Build full book description: "bộ tiểu thuyết phiêu lưu mạo hiểm mang tên X của nhà văn Y"
        $bookDesc = "bộ {$bookTypeLabel}";
        if ($bookCategory) {
            $bookDesc .= " {$bookCategory}";
        }
        $bookDesc .= " mang tên {$bookTitle}";
        if ($author) {
            $bookDesc .= " của nhà văn {$author}";
        }

        // Get total chapters of the book
        $totalBookChapters = $audioBook->chapters()->count();
        $isLastChapter = ($chapterNumber >= $totalBookChapters);

        // Chunk 1: Add chapter title intro
        if ($chunk->chunk_number === 1) {
            // Check if title already contains "Chương X" pattern
            $hasChapterPrefix = preg_match('/^(Chương|Chapter|Phần)\s*\d+/iu', $chapterTitle);

            if ($hasChapterPrefix) {
                // Title already has chapter number, just use the title
                $intro = "{$chapterTitle}.\n\n";
            } else {
                // Add chapter number prefix
                $intro = "Chương {$chapterNumber}: {$chapterTitle}.\n\n";
            }
            $text = $intro . $text;
        }

        // Last chunk: Add outro with book_type, category, author and CTA
        if ($chunk->chunk_number === $totalChunks) {
            if ($isLastChapter) {
                // This is the final chapter of the book
                $outro = "\n\nBạn vừa nghe xong chương {$chapterNumber}, chương cuối cùng của {$bookDesc}.";
                $outro .= " Cảm ơn bạn đã đồng hành cùng chúng tôi trong suốt tác phẩm này.";
                if ($channelName) {
                    $outro .= " Nếu bạn thích nội dung này, hãy nhấn like, subscribe và bật chuông thông báo để không bỏ lỡ những tác phẩm hay tiếp theo từ kênh {$channelName}.";
                    $outro .= " Sự ủng hộ của bạn là động lực lớn để chúng tôi tiếp tục sáng tạo. Hẹn gặp lại bạn!";
                } else {
                    $outro .= " Hẹn gặp lại bạn trong những tác phẩm tiếp theo.";
                }
            } else {
                // There are more chapters
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
     * Get audio duration using ffprobe
     */
    private function getAudioDuration(string $filePath): ?float
    {
        if (!file_exists($filePath)) {
            return null;
        }

        // Try ffprobe first
        $command = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 \"{$filePath}\" 2>&1";
        \exec($command, $output, $returnCode);

        if ($returnCode === 0 && isset($output[0]) && is_numeric($output[0])) {
            return (float) $output[0];
        }

        // Fallback: estimate based on file size (128kbps MP3)
        $fileSize = filesize($filePath);
        return $fileSize / (128 * 1024 / 8); // bytes / (bitrate in bytes/sec)
    }

    /**
     * Merge all chunk audio files of a chapter into a single file
     * Output: c_{chapter_number}_full.mp3
     * Includes intro/outro music with fade effects if configured
     */
    private function mergeChapterAudio(AudioBookChapter $chapter): ?string
    {
        try {
            $bookId = $chapter->audio_book_id;
            $audioBook = $chapter->audioBook;
            $chapterNum = str_pad($chapter->chapter_number, 3, '0', STR_PAD_LEFT);

            // Get all completed chunks ordered by chunk_number
            $chunks = $chapter->chunks()
                ->where('status', 'completed')
                ->whereNotNull('audio_file')
                ->orderBy('chunk_number')
                ->get();

            if ($chunks->isEmpty()) {
                Log::warning("No completed chunks found for chapter {$chapter->id}");
                return null;
            }

            $outputDir = storage_path('app/public/books/' . $bookId);
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            // Generate silence file if pause_between_chunks > 0
            $pauseDuration = (float) ($audioBook->pause_between_chunks ?? 0);
            $silenceFile = null;
            if ($pauseDuration > 0) {
                $silenceFile = $outputDir . DIRECTORY_SEPARATOR . "silence_{$chapterNum}.mp3";
                $silenceCmd = "ffmpeg -y -f lavfi -i anullsrc=r=44100:cl=stereo -t {$pauseDuration} -q:a 9 \"{$silenceFile}\" 2>&1";
                exec($silenceCmd, $silenceOutput, $silenceReturnCode);
                if ($silenceReturnCode !== 0 || !file_exists($silenceFile)) {
                    Log::warning("Failed to create silence file", ['output' => $silenceOutput]);
                    $silenceFile = null;
                }
            }

            // Create list file for ffmpeg concat
            $listFile = $outputDir . DIRECTORY_SEPARATOR . "concat_list_{$chapterNum}.txt";
            $listContent = "";

            $chunkCount = 0;
            $totalChunkCount = $chunks->count();
            foreach ($chunks as $chunk) {
                $chunkPath = storage_path('app/public/' . $chunk->audio_file);
                if (file_exists($chunkPath)) {
                    $chunkCount++;
                    // Use forward slashes and escape single quotes for ffmpeg
                    $escapedPath = str_replace("'", "'\\''", str_replace('\\', '/', $chunkPath));
                    $listContent .= "file '{$escapedPath}'\n";

                    // Add silence between chunks (not after the last one)
                    if ($silenceFile && $chunkCount < $totalChunkCount) {
                        $escapedSilence = str_replace("'", "'\\''", str_replace('\\', '/', $silenceFile));
                        $listContent .= "file '{$escapedSilence}'\n";
                    }
                }
            }

            if (empty($listContent)) {
                Log::warning("No valid audio files to merge for chapter {$chapter->id}");
                if ($silenceFile && file_exists($silenceFile)) unlink($silenceFile);
                return null;
            }

            file_put_contents($listFile, $listContent);

            // Output merged file (voice only, temporary if we have intro/outro)
            $voiceOnlyFilename = "c_{$chapterNum}_voice.mp3";
            $voiceOnlyPath = $outputDir . DIRECTORY_SEPARATOR . $voiceOnlyFilename;
            $mergedFilename = "c_{$chapterNum}_full.mp3";
            $mergedPath = $outputDir . DIRECTORY_SEPARATOR . $mergedFilename;

            // Remove existing files if exists
            if (file_exists($voiceOnlyPath)) {
                unlink($voiceOnlyPath);
            }
            if (file_exists($mergedPath)) {
                unlink($mergedPath);
            }

            // Step 1: Merge all voice chunks into single voice file
            $command = "ffmpeg -f concat -safe 0 -i \"{$listFile}\" -c copy \"{$voiceOnlyPath}\" 2>&1";
            Log::info("Merging chapter voice audio", ['command' => $command]);

            exec($command, $output, $returnCode);

            // Clean up list file and silence file
            if (file_exists($listFile)) {
                unlink($listFile);
            }
            if ($silenceFile && file_exists($silenceFile)) {
                unlink($silenceFile);
            }

            if ($returnCode !== 0 || !file_exists($voiceOnlyPath)) {
                Log::error("FFmpeg merge failed for chapter {$chapter->id}", [
                    'return_code' => $returnCode,
                    'output' => $output
                ]);
                return null;
            }

            // Get voice duration
            $voiceDuration = $this->getAudioDuration($voiceOnlyPath);
            if ($voiceDuration) {
                $chapter->total_duration = $voiceDuration;
            }

            // Step 2: Add intro/outro music if configured
            $hasIntro = !empty($audioBook->intro_music) && file_exists(storage_path('app/public/' . $audioBook->intro_music));

            // Check outro: either has dedicated outro file OR outro_use_intro is enabled with intro available
            $outroUseIntro = $audioBook->outro_use_intro ?? false;
            $hasOutro = false;
            if (!empty($audioBook->outro_music) && file_exists(storage_path('app/public/' . $audioBook->outro_music))) {
                $hasOutro = true;
            } elseif ($outroUseIntro && $hasIntro) {
                $hasOutro = true; // Will use intro music as outro
            }

            if ($hasIntro || $hasOutro) {
                $finalPath = $this->addIntroOutroMusic(
                    $voiceOnlyPath,
                    $mergedPath,
                    $audioBook,
                    $voiceDuration
                );

                // Clean up voice only file after merging with music
                if (file_exists($voiceOnlyPath) && file_exists($mergedPath)) {
                    unlink($voiceOnlyPath);
                }

                if (!$finalPath) {
                    // If adding music failed, use voice only as final
                    rename($voiceOnlyPath, $mergedPath);
                }
            } else {
                // No intro/outro, just rename voice file to final
                rename($voiceOnlyPath, $mergedPath);
            }

            // Update duration with final file
            $totalDuration = $this->getAudioDuration($mergedPath);
            if ($totalDuration) {
                $chapter->total_duration = $totalDuration;
            }

            Log::info("Successfully merged {$chunks->count()} chunks into {$mergedFilename}", [
                'has_intro' => $hasIntro,
                'has_outro' => $hasOutro,
                'voice_duration' => $voiceDuration,
                'total_duration' => $totalDuration
            ]);

            return 'books/' . $bookId . '/' . $mergedFilename;
        } catch (\Exception $e) {
            Log::error("Error merging chapter audio: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Add intro and outro music to voice audio with fade effects
     * 
     * Intro: Music plays at full volume, fades out as voice starts
     * Outro: Music fades in during last X seconds of voice, continues after voice ends
     */
    private function addIntroOutroMusic(
        string $voicePath,
        string $outputPath,
        AudioBook $audioBook,
        float $voiceDuration
    ): ?string {
        try {
            $introPath = $audioBook->intro_music ? storage_path('app/public/' . $audioBook->intro_music) : null;

            // Check if outro should use intro music
            $outroUseIntro = $audioBook->outro_use_intro ?? false;
            if ($outroUseIntro && $introPath) {
                $outroPath = $introPath; // Use intro music for outro
            } else {
                $outroPath = $audioBook->outro_music ? storage_path('app/public/' . $audioBook->outro_music) : null;
            }

            $introFadeDuration = $audioBook->intro_fade_duration ?? 3;
            $outroFadeDuration = $audioBook->outro_fade_duration ?? 10;
            $outroExtendDuration = $audioBook->outro_extend_duration ?? 5;

            $hasIntro = $introPath && file_exists($introPath);
            $hasOutro = $outroPath && file_exists($outroPath);

            if (!$hasIntro && !$hasOutro) {
                return null;
            }

            // Normalize paths for FFmpeg
            $voicePathNorm = str_replace('\\', '/', $voicePath);
            $outputPathNorm = str_replace('\\', '/', $outputPath);

            $filterComplex = [];
            $inputs = ["-i \"{$voicePathNorm}\""];
            $inputIndex = 0;

            // Voice is always input 0
            $voiceInput = "[{$inputIndex}:a]";
            $inputIndex++;

            // Calculate intro duration (how long intro plays before voice starts)
            $introDuration = 0;
            if ($hasIntro) {
                $introPathNorm = str_replace('\\', '/', $introPath);
                $inputs[] = "-i \"{$introPathNorm}\"";
                $introInput = "[{$inputIndex}:a]";
                $inputIndex++;

                // Get intro music duration
                $introMusicDuration = $this->getAudioDuration($introPath);
                // Use minimum of intro music length or 5 seconds for intro before voice
                $introDuration = min($introMusicDuration ?? 5, 5);

                // Intro music: play for introDuration, then fade out over introFadeDuration seconds
                // The intro overlaps with the beginning of voice during fade
                $introFadeStart = max(0, $introDuration - 0.5); // Start fade slightly before voice
                $filterComplex[] = "{$introInput}atrim=0:" . ($introDuration + $introFadeDuration) . ",afade=t=out:st={$introFadeStart}:d={$introFadeDuration}[intro]";
            }

            if ($hasOutro) {
                $outroPathNorm = str_replace('\\', '/', $outroPath);
                $inputs[] = "-i \"{$outroPathNorm}\"";
                $outroInput = "[{$inputIndex}:a]";
                $inputIndex++;

                // Outro music: fade in during last outroFadeDuration seconds of voice
                // Then continue for outroExtendDuration after voice ends
                $outroStartInVoice = max(0, $voiceDuration - $outroFadeDuration);
                $outroTotalDuration = $outroFadeDuration + $outroExtendDuration;

                // Fade in the outro music
                $filterComplex[] = "{$outroInput}atrim=0:{$outroTotalDuration},afade=t=in:st=0:d={$outroFadeDuration},afade=t=out:st=" . ($outroTotalDuration - 2) . ":d=2[outro]";
            }

            // Build the final mix
            if ($hasIntro && $hasOutro) {
                // Delay voice by intro duration, then mix with intro
                $filterComplex[] = "{$voiceInput}adelay=" . ($introDuration * 1000) . "|" . ($introDuration * 1000) . "[voicedelayed]";

                // Calculate outro delay (introDuration + time until outro starts in voice)
                $outroDelay = ($introDuration + max(0, $voiceDuration - $outroFadeDuration)) * 1000;
                $filterComplex[] = "[outro]adelay={$outroDelay}|{$outroDelay}[outrodelayed]";

                // Mix all three: intro + delayed voice + delayed outro
                $filterComplex[] = "[intro][voicedelayed]amix=inputs=2:duration=longest:normalize=0[premix]";
                $filterComplex[] = "[premix][outrodelayed]amix=inputs=2:duration=longest:normalize=0[final]";
            } elseif ($hasIntro) {
                // Delay voice by intro duration
                $filterComplex[] = "{$voiceInput}adelay=" . ($introDuration * 1000) . "|" . ($introDuration * 1000) . "[voicedelayed]";
                // Mix intro with delayed voice
                $filterComplex[] = "[intro][voicedelayed]amix=inputs=2:duration=longest:normalize=0[final]";
            } elseif ($hasOutro) {
                // Calculate outro delay
                $outroDelay = max(0, $voiceDuration - $outroFadeDuration) * 1000;
                $filterComplex[] = "[outro]adelay={$outroDelay}|{$outroDelay}[outrodelayed]";
                // Mix voice with delayed outro
                $filterComplex[] = "{$voiceInput}[outrodelayed]amix=inputs=2:duration=longest:normalize=0[final]";
            }

            $inputsStr = implode(' ', $inputs);
            $filterStr = implode(';', $filterComplex);

            $command = "ffmpeg -y {$inputsStr} -filter_complex \"{$filterStr}\" -map \"[final]\" -c:a libmp3lame -b:a 192k \"{$outputPathNorm}\" 2>&1";

            Log::info("Adding intro/outro music to chapter", [
                'command' => $command,
                'voice_duration' => $voiceDuration,
                'has_intro' => $hasIntro,
                'has_outro' => $hasOutro
            ]);

            exec($command, $output, $returnCode);

            if ($returnCode !== 0) {
                Log::error("FFmpeg intro/outro mix failed", [
                    'return_code' => $returnCode,
                    'output' => implode("\n", $output)
                ]);
                return null;
            }

            return $outputPath;
        } catch (\Exception $e) {
            Log::error("Error adding intro/outro music: " . $e->getMessage());
            return null;
        }
    }
}
