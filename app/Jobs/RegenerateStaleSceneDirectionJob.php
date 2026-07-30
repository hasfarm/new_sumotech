<?php

namespace App\Jobs;

use App\Models\AudioBook;
use App\Models\AudiobookStoryBible;
use App\Models\AudiobookVideoScene;
use App\Services\VideoSceneAnalysisService;
use Illuminate\Bus\Queueable;
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
 */
class RegenerateStaleSceneDirectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(private readonly int $audioBookId) {}

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
        $pipeline = $audioBook->videoPipeline;
        $shotChunks = collect($pipeline->shot_chunks ?? []);

        $staleShotIds = [];
        $reassignedScenes = 0;

        foreach ($scenes as $scene) {
            $isStale = $scene->story_bible_version_used !== $activeBible->bible_version
                || $scene->scene_direction_version !== VideoSceneAnalysisService::SCENE_DIRECTION_VERSION;

            if (!$isStale) {
                continue;
            }

            $assignment = $service->assignSceneContext($scene, $activeBible, [
                'book_id' => $this->audioBookId,
                'scene_id' => $scene->id,
            ]);
            $service->persistSceneContext($scene, $activeBible, $assignment);
            $reassignedScenes++;

            foreach ($scene->shots()->pluck('id') as $shotId) {
                $staleShotIds[] = $shotId;
            }
        }

        $result = ['status' => 'ok', 'reassigned' => $reassignedScenes, 'total' => $scenes->count(), 'stale_chunk_indices' => []];

        if (empty($staleShotIds)) {
            return $result;
        }

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

        return $result;
    }
}
