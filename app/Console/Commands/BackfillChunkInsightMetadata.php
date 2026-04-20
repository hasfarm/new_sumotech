<?php

namespace App\Console\Commands;

use App\Models\AudioBookChapterChunk;
use App\Services\ChunkMetadataEnrichmentService;
use Illuminate\Console\Command;

class BackfillChunkInsightMetadata extends Command
{
    protected $signature = 'chunks:backfill-insight-metadata
        {--limit=0 : Maximum number of rows to process}
        {--chunk-id= : Process only one chunk id}
        {--dry-run : Show what would change without saving}';

    protected $description = 'Backfill book_id/chapter_id/chunk_index and insight metadata for audiobook chapter chunks';

    public function handle(ChunkMetadataEnrichmentService $enrichmentService): int
    {
        $query = AudioBookChapterChunk::query()
            ->with('chapter:id,audio_book_id,chapter_number')
            ->where(function ($q) {
                $q->whereNull('book_id')
                    ->orWhereNull('chapter_id')
                    ->orWhereNull('chunk_index')
                    ->orWhereNull('character_tags')
                    ->orWhereNull('scene_type')
                    ->orWhereNull('topic_tags')
                    ->orWhereNull('importance_score');
            })
            ->orderBy('id');

        $chunkId = (string) $this->option('chunk-id');
        if ($chunkId !== '') {
            $query->whereKey((int) $chunkId);
        }

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $chunks = $query->get();

        if ($chunks->isEmpty()) {
            $this->info('No rows need backfill.');
            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->info('Rows to process: ' . $chunks->count());
        if ($dryRun) {
            $this->warn('Dry run mode enabled, no changes will be saved.');
        }

        $updated = 0;
        $skipped = 0;

        $bar = $this->output->createProgressBar($chunks->count());
        $bar->start();

        foreach ($chunks as $chunk) {
            $audioMeta = $this->extractMetaFromAudioFile((string) $chunk->audio_file);
            $enriched = $enrichmentService->enrich($chunk);

            $bookId = $audioMeta['book_id']
                ?? ($enriched['book_id'] ?? null)
                ?? ($chunk->chapter?->audio_book_id ? (int) $chunk->chapter->audio_book_id : null);

            $chapterId = $enriched['chapter_id'] ?? (int) $chunk->audiobook_chapter_id;
            if (!$chapterId) {
                $chapterId = null;
            }

            $chunkIndex = $audioMeta['chunk_index']
                ?? ($enriched['chunk_index'] ?? null)
                ?? (int) $chunk->chunk_number;

            $payload = [
                'book_id' => $bookId,
                'chapter_id' => $chapterId,
                'chunk_index' => $chunkIndex,
                'character_tags' => $enriched['character_tags'] ?? [],
                'scene_type' => $enriched['scene_type'] ?? ['general'],
                'topic_tags' => $enriched['topic_tags'] ?? [],
                'importance_score' => $enriched['importance_score'] ?? 0.0,
            ];

            $changed = false;
            foreach ($payload as $key => $value) {
                if ($chunk->{$key} !== $value) {
                    $changed = true;
                    break;
                }
            }

            if (!$changed) {
                $skipped++;
                $bar->advance();
                continue;
            }

            if (!$dryRun) {
                $chunk->forceFill($payload)->save();
            }

            $updated++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('Backfill completed.');
        $this->line('Updated: ' . $updated);
        $this->line('Skipped: ' . $skipped);

        return self::SUCCESS;
    }

    /**
     * @return array{book_id:?int,chapter_number:?int,chunk_index:?int}
     */
    private function extractMetaFromAudioFile(string $audioFile): array
    {
        $normalized = str_replace('\\\\', '/', trim($audioFile));

        $bookId = null;
        $chapterNumber = null;
        $chunkIndex = null;

        if (preg_match('#books/(\d+)/c_(\d+)_(\d+)_\d+\.mp3#i', $normalized, $matches)) {
            $bookId = isset($matches[1]) ? (int) $matches[1] : null;
            $chapterNumber = isset($matches[2]) ? (int) $matches[2] : null;
            $chunkIndex = isset($matches[3]) ? (int) $matches[3] : null;
        }

        return [
            'book_id' => $bookId,
            'chapter_number' => $chapterNumber,
            'chunk_index' => $chunkIndex,
        ];
    }
}
