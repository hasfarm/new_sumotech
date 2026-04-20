<?php

namespace App\Console\Commands;

use App\Models\AudioBookChapterChunk;
use App\Services\ChunkMetadataEnrichmentService;
use App\Services\QdrantChunkIndexService;
use Illuminate\Console\Command;

class SyncAudiobookChunksToQdrant extends Command
{
    protected $signature = 'qdrant:sync-audiobook-chunks
        {audioBookId? : Optional audio_book_id filter}
        {--chapter-id= : Optional audiobook_chapter_id filter}
        {--chunk-id= : Optional chunk id filter}
        {--limit=0 : Maximum number of chunks to sync}
        {--recreate : Recreate collection before indexing the first chunk}
        {--dry-run : Show matched chunks without indexing}';

    protected $description = 'Sync rows from audiobook_chapter_chunks into Qdrant vector collection';

    public function handle(
        QdrantChunkIndexService $indexService,
        ChunkMetadataEnrichmentService $enrichmentService
    ): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (!$dryRun) {
            $missing = $indexService->missingRequiredConfig();
            if (!empty($missing)) {
                $this->error('Missing required configuration: ' . implode(', ', $missing));
                $this->line('Set these values in your .env file before syncing.');
                return self::FAILURE;
            }
        }

        $query = AudioBookChapterChunk::query()
            ->with(['chapter:id,audio_book_id,chapter_number,title'])
            ->orderBy('audiobook_chapter_id')
            ->orderBy('chunk_number');

        $audioBookId = $this->argument('audioBookId');
        if ($audioBookId !== null && $audioBookId !== '') {
            $bookId = (int) $audioBookId;
            $query->whereHas('chapter', function ($q) use ($bookId) {
                $q->where('audio_book_id', $bookId);
            });
        }

        $chapterId = (string) $this->option('chapter-id');
        if ($chapterId !== '') {
            $query->where('audiobook_chapter_id', (int) $chapterId);
        }

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
            $this->warn('No chunks matched the provided filters.');
            return self::SUCCESS;
        }

        $this->info('Matched chunks: ' . $chunks->count());
        $this->line('Target collection: ' . $indexService->collectionName());

        if ($dryRun) {
            $this->table(
                ['chunk_id', 'chapter_id', 'book_id', 'chapter_no', 'chunk_no', 'chars'],
                $chunks->map(function (AudioBookChapterChunk $chunk) {
                    return [
                        $chunk->id,
                        $chunk->audiobook_chapter_id,
                        $chunk->chapter?->audio_book_id,
                        $chunk->chapter?->chapter_number,
                        $chunk->chunk_number,
                        mb_strlen((string) $chunk->text_content, 'UTF-8'),
                    ];
                })->all()
            );

            return self::SUCCESS;
        }

        $recreateCollection = (bool) $this->option('recreate');
        $indexed = 0;
        $skipped = 0;
        $failed = 0;

        $bar = $this->output->createProgressBar($chunks->count());
        $bar->start();

        foreach ($chunks as $i => $chunk) {
            try {
                $metadata = $enrichmentService->enrich($chunk);
                $chunk->forceFill($metadata)->save();

                $result = $indexService->indexChunk($chunk, $recreateCollection && $i === 0);

                if (($result['status'] ?? '') === 'skipped') {
                    $skipped++;
                } else {
                    $indexed++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->newLine();
                $this->warn(sprintf('Chunk %d failed: %s', (int) $chunk->id, $e->getMessage()));
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('Sync completed.');
        $this->line('Indexed: ' . $indexed);
        $this->line('Skipped: ' . $skipped);
        $this->line('Failed: ' . $failed);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
