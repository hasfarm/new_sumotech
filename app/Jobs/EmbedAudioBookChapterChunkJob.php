<?php

namespace App\Jobs;

use App\Models\AudioBookChapterChunk;
use App\Services\ChunkMetadataEnrichmentService;
use App\Services\QdrantChunkIndexService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EmbedAudioBookChapterChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [10, 30, 90];

    public function __construct(public readonly int $chunkId)
    {
    }

    public function handle(
        QdrantChunkIndexService $indexService,
        ChunkMetadataEnrichmentService $enrichmentService
    ): void
    {
        $claimed = AudioBookChapterChunk::query()
            ->whereKey($this->chunkId)
            ->where('embedding_status', 'pending')
            ->update([
                'embedding_status' => 'processing',
                'updated_at' => now(),
            ]);

        if ($claimed === 0) {
            return;
        }

        $chunk = AudioBookChapterChunk::with('chapter:id,audio_book_id,chapter_number,title')->find($this->chunkId);
        if (!$chunk) {
            return;
        }

        $hash = hash('sha256', (string) $chunk->text_content);

        try {
            $metadata = $enrichmentService->enrich($chunk);
            $chunk->forceFill($metadata)->save();

            $indexService->indexChunk($chunk);

            $chunk->forceFill([
                'embedding_status' => 'done',
                'embedded_at' => now(),
                'qdrant_point_id' => (string) $chunk->id,
                'content_hash' => $hash,
            ])->save();
        } catch (\Throwable $e) {
            AudioBookChapterChunk::query()
                ->whereKey($this->chunkId)
                ->update([
                    'embedding_status' => 'pending',
                    'embedded_at' => null,
                ]);

            Log::error('Failed to embed audiobook chapter chunk', [
                'chunk_id' => $this->chunkId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        AudioBookChapterChunk::query()
            ->whereKey($this->chunkId)
            ->update([
                'embedding_status' => 'error',
                'embedded_at' => null,
            ]);

        Log::error('Embedding chunk job failed after max retries', [
            'chunk_id' => $this->chunkId,
            'error' => $exception->getMessage(),
        ]);
    }
}
