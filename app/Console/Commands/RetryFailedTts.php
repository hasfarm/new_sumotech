<?php

namespace App\Console\Commands;

use App\Http\Controllers\AudioBookChapterController;
use App\Models\AudioBook;
use App\Models\AudioBookChapter;
use App\Models\AudioBookChapterChunk;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

class RetryFailedTts extends Command
{
    protected $signature = 'audiobook:retry-tts
        {--book= : Specific audiobook ID (default: all books with errors)}
        {--dry-run : Show what would be retried without executing}';

    protected $description = 'Retry TTS generation for error/pending chunks that have no audio';

    public function handle(): int
    {
        $bookId = $this->option('book');
        $dryRun = $this->option('dry-run');

        // Find chunks with error or pending status AND no audio file
        $query = AudioBookChapterChunk::whereIn('status', ['error', 'pending'])
            ->where(function ($q) {
                $q->whereNull('audio_file')
                    ->orWhere('audio_file', '');
            });

        if ($bookId) {
            $query->whereHas('chapter', fn($q) => $q->where('audio_book_id', $bookId));
        }

        $chunks = $query->with('chapter.audioBook')->get();

        if ($chunks->isEmpty()) {
            $this->info('No error/pending chunks found. Nothing to retry.');
            return 0;
        }

        // Group by book → chapter
        $grouped = [];
        foreach ($chunks as $chunk) {
            $book = $chunk->chapter->audioBook;
            $chapter = $chunk->chapter;
            $key = $book->id;
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'book' => $book,
                    'chapters' => [],
                ];
            }
            if (!isset($grouped[$key]['chapters'][$chapter->id])) {
                $grouped[$key]['chapters'][$chapter->id] = [
                    'chapter' => $chapter,
                    'error' => 0,
                    'pending' => 0,
                    'chunks' => [],
                ];
            }
            $grouped[$key]['chapters'][$chapter->id][$chunk->status]++;
            $grouped[$key]['chapters'][$chapter->id]['chunks'][] = $chunk;
        }

        // Display summary
        $this->info('=== TTS Retry Summary ===');
        $totalChunks = 0;
        foreach ($grouped as $bookData) {
            $book = $bookData['book'];
            $this->newLine();
            $this->info("📚 Book #{$book->id}: {$book->title}");
            $this->info("   Provider: {$book->tts_provider} | Voice: {$book->tts_voice_name}");

            foreach ($bookData['chapters'] as $chData) {
                $ch = $chData['chapter'];
                $count = $chData['error'] + $chData['pending'];
                $totalChunks += $count;
                $this->line("   Ch#{$ch->chapter_number} ({$ch->title}): {$chData['error']} error, {$chData['pending']} pending");
            }
        }

        $this->newLine();
        $this->info("Total: {$totalChunks} chunks to retry across " . count($grouped) . " book(s)");

        if ($dryRun) {
            $this->warn('Dry run mode — no changes made.');
            return 0;
        }

        if (!$this->confirm('Proceed with retry?')) {
            return 0;
        }

        // Reset error chunks to pending
        $resetCount = AudioBookChapterChunk::whereIn('status', ['error'])
            ->where(function ($q) {
                $q->whereNull('audio_file')->orWhere('audio_file', '');
            })
            ->when($bookId, fn($q) => $q->whereHas('chapter', fn($q2) => $q2->where('audio_book_id', $bookId)))
            ->update(['status' => 'pending', 'error_message' => null]);

        $this->info("Reset {$resetCount} error chunks to pending.");

        // Process chunks directly (skip initializeChunks to preserve existing audio)
        $controller = app(AudioBookChapterController::class);
        $successCount = 0;
        $errorCount = 0;

        foreach ($grouped as $bookData) {
            $book = $bookData['book'];
            $ttsRequest = new Request([
                'provider' => $book->tts_provider ?? 'vbee',
                'voice_name' => $book->tts_voice_name ?? 'hn_female_ngochuyen_full_48k-fhg',
                'voice_gender' => $book->tts_voice_gender ?? 'female',
                'style_instruction' => $book->tts_style_instruction,
            ]);

            foreach ($bookData['chapters'] as $chData) {
                $chapter = $chData['chapter'];
                $chapter->status = 'processing';
                $chapter->save();

                foreach ($chData['chunks'] as $chunk) {
                    $chunk->refresh();
                    // Skip if already completed/has audio
                    if ($chunk->audio_file && file_exists(storage_path('app/public/' . $chunk->audio_file))) {
                        continue;
                    }

                    $this->output->write("  Ch#{$chapter->chapter_number} chunk#{$chunk->chunk_number}... ");

                    $response = $controller->generateSingleChunk($ttsRequest, $book, $chapter, $chunk);
                    $data = method_exists($response, 'getData') ? $response->getData(true) : [];

                    if ($data['success'] ?? false) {
                        $successCount++;
                        $this->output->writeln('<info>OK</info>');
                    } else {
                        $errorCount++;
                        $err = $data['error'] ?? 'unknown';
                        $this->output->writeln("<error>FAIL: {$err}</error>");
                    }
                }

                // After processing all chunks, try merge if all completed
                $failedChunks = $chapter->chunks()->where('status', '!=', 'completed')->count();
                if ($failedChunks === 0) {
                    $this->output->write("  Ch#{$chapter->chapter_number} merging... ");
                    $mergeResponse = $controller->mergeChapterAudioEndpoint(new Request([]), $book, $chapter);
                    $mergeData = method_exists($mergeResponse, 'getData') ? $mergeResponse->getData(true) : [];
                    if ($mergeData['success'] ?? false) {
                        $this->output->writeln('<info>OK</info>');
                    } else {
                        $this->output->writeln('<error>FAIL</error>');
                    }
                    $chapter->status = 'completed';
                } else {
                    $chapter->status = 'error';
                    $chapter->error_message = "{$failedChunks} chunks failed";
                }
                $chapter->save();
            }
        }

        $this->newLine();
        $this->info("Done. Success: {$successCount}, Errors: {$errorCount}");

        return $errorCount > 0 ? 1 : 0;
    }
}
