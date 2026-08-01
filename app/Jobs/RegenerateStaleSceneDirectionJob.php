<?php

namespace App\Jobs;

use App\Models\AudioBook;
use App\Models\AudiobookStoryBible;
use App\Models\AudiobookVideoPipeline;
use App\Models\AudiobookVideoScene;
use App\Services\VideoSceneAnalysisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Targeted re-run for when the active Story Bible version (or the scene-assignment logic
 * itself) has moved on since a scene's timeline/location/character bindings were computed —
 * re-assigns ONLY scenes matching the staleness predicate and regenerates ONLY the shot
 * chunks that belonged to those scenes, never the whole pipeline.
 *
 * Single source of truth for this flow — `story-direction:regenerate-stale` (CLI) and the
 * pipeline UI's "Regenerate stale scenes" button both call handle() directly rather than
 * duplicating the staleness predicate, since assignSceneContext() makes real OpenAI calls
 * and can't run inline in an HTTP request without risking a timeout on a book with many
 * stale scenes.
 *
 * Writes live progress to story_bible_regenerate_stale_status (same resumable-ledger shape as
 * BulkGenerateNarrationTtsJob etc) — previously this job reported nothing while running, so the
 * "Regenerate stale scenes" button looked done the instant the request was dispatched even
 * though real OpenAI calls were still in flight per stale scene.
 */
class RegenerateStaleSceneDirectionJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 3600;
    // Must stay >= $timeout — see BulkGenerateShotImagesJob::$uniqueFor for why (a queue
    // "job considered lost, let another worker grab it" race can otherwise duplicate work).
    public int $uniqueFor = 3600;

    public function __construct(private readonly int $audioBookId) {}

    public function uniqueId(): string
    {
        return (string) $this->audioBookId;
    }

    /**
     * @return array{status:string, reassigned?:int, total?:int, stale_chunk_indices?:array<int,int>}
     */
    public function handle(VideoSceneAnalysisService $service): array
    {
        $audioBook = AudioBook::find($this->audioBookId);
        if (!$audioBook) {
            return ['status' => 'not_found'];
        }

        $activeBible = AudiobookStoryBible::where('audio_book_id', $this->audioBookId)->where('is_active', true)->first();
        if (!$activeBible) {
            return ['status' => 'no_active_bible'];
        }

        $scenes = AudiobookVideoScene::where('audio_book_id', $this->audioBookId)->orderBy('scene_index')->get();
        $pipeline = AudiobookVideoPipeline::firstOrCreate(['audio_book_id' => $this->audioBookId]);
        $shotChunks = collect($pipeline->shot_chunks ?? []);

        $staleScenes = $scenes->filter(function ($scene) use ($activeBible) {
            return $scene->story_bible_version_used !== $activeBible->bible_version
                || $scene->scene_direction_version !== VideoSceneAnalysisService::SCENE_DIRECTION_VERSION;
        })->values();

        $pipeline->update(['story_bible_regenerate_stale_status' => [
            'status' => 'running',
            'total' => $staleScenes->count(),
            'processed' => 0,
            'started_at' => now()->toIso8601String(),
            'last_progress_at' => now()->toIso8601String(),
        ]]);

        $staleShotIds = [];
        $reassignedScenes = 0;

        foreach ($staleScenes as $scene) {
            $assignment = $service->assignSceneContext($scene, $activeBible, [
                'book_id' => $this->audioBookId,
                'scene_id' => $scene->id,
            ]);
            $service->persistSceneContext($scene, $activeBible, $assignment);
            $reassignedScenes++;

            foreach ($scene->shots()->pluck('id') as $shotId) {
                $staleShotIds[] = $shotId;
            }

            $pipeline->refresh();
            $pipeline->update(['story_bible_regenerate_stale_status' => array_merge($pipeline->story_bible_regenerate_stale_status ?? [], [
                'processed' => $reassignedScenes,
                'last_progress_at' => now()->toIso8601String(),
            ])]);
        }

        $result = ['status' => 'ok', 'reassigned' => $reassignedScenes, 'total' => $scenes->count(), 'stale_chunk_indices' => []];

        $staleShotIdsFlipped = array_flip($staleShotIds);
        $staleChunkIndices = $shotChunks
            ->filter(fn($chunk) => collect($chunk['shot_ids'] ?? [])->contains(fn($id) => isset($staleShotIdsFlipped[$id])))
            ->pluck('index')
            ->values()
            ->all();

        $result['stale_chunk_indices'] = $staleChunkIndices;

        if (!empty($staleChunkIndices)) {
            EnrichVideoShotsJob::dispatch($this->audioBookId, $staleChunkIndices);
        }

        $pipeline->refresh();
        $pipeline->update(['story_bible_regenerate_stale_status' => array_merge($pipeline->story_bible_regenerate_stale_status ?? [], [
            'status' => 'done',
            'last_progress_at' => now()->toIso8601String(),
            'stale_chunks_dispatched' => count($staleChunkIndices),
        ])]);

        return $result;
    }
}
