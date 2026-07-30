<?php

namespace App\Jobs;

use App\Models\AudioBook;
use App\Models\AudiobookVideoPipeline;
use App\Models\AudiobookVideoScene;
use App\Models\AudiobookVideoShot;
use App\Services\VideoSceneAnalysisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Stage B of the split video-analysis pipeline: per-shot enrichment (is_real_world/
 * keywords/image_request), chunked to ~15 shots per API call (never spanning a scene
 * boundary, so each chunk has one consistent title/scene_type for its prompt). Each chunk
 * retries independently with backoff; a chunk that ultimately fails is recorded in the
 * pipeline's `shot_chunks` progress ledger and does NOT abort the rest of the job — the
 * controller exposes a "retry failed chunks only" entry point via $onlyChunkIndices.
 */
class EnrichVideoShotsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries = 1;

    private const CHUNK_SIZE = 15;
    private const RETRY_DELAYS = [5, 15, 45];

    public function __construct(
        public readonly int $audioBookId,
        /** @var array<int,int>|null null = fresh full run; array of global chunk indices = retry only those */
        public readonly ?array $onlyChunkIndices = null
    ) {}

    public function handle(VideoSceneAnalysisService $service): void
    {
        $audioBook = AudioBook::find($this->audioBookId);
        $pipeline = AudiobookVideoPipeline::firstOrCreate(['audio_book_id' => $this->audioBookId]);

        if (!$audioBook) {
            $pipeline->update(['status' => 'failed', 'error_message' => 'Không tìm thấy audiobook.']);
            return;
        }

        $scenes = AudiobookVideoScene::where('audio_book_id', $this->audioBookId)->orderBy('scene_index')->get();
        if ($scenes->isEmpty()) {
            $pipeline->update(['status' => 'failed', 'error_message' => 'Chưa có cảnh nào để tạo shot.']);
            return;
        }

        $pipeline->update(['current_stage' => 'shot_enrichment', 'last_heartbeat_at' => now()]);
        $contextHint = (string) ($pipeline->context_hint ?? '');

        // Computed once per scene (not once per chunk) and reused across that scene's
        // chunks — Story Bible/Character Bible data resolved into a fixed, value-only text
        // block (see VideoSceneAnalysisService::buildStableContextBlock()).
        $stableContextByScene = [];
        $stableContextFor = function (AudiobookVideoScene $scene) use ($service, &$stableContextByScene): string {
            if (!array_key_exists($scene->id, $stableContextByScene)) {
                $stableContextByScene[$scene->id] = $scene->story_bible_id
                    ? $service->buildStableContextBlock($scene)
                    : '';
            }
            return $stableContextByScene[$scene->id];
        };
        $bibleByScene = [];
        $bibleFor = function (AudiobookVideoScene $scene) use (&$bibleByScene): ?\App\Models\AudiobookStoryBible {
            if (!array_key_exists($scene->id, $bibleByScene)) {
                $bibleByScene[$scene->id] = $scene->story_bible_id ? $scene->storyBible : null;
            }
            return $bibleByScene[$scene->id];
        };

        $isRetryOnly = $this->onlyChunkIndices !== null;

        [$chunkPlan, $chunkShots] = $isRetryOnly
            ? $this->reconstructChunkPlan($pipeline)
            : $this->buildFreshChunkPlan($service, $scenes);

        if (empty($chunkPlan)) {
            $pipeline->update(['status' => 'failed', 'error_message' => 'Không có shot nào để xử lý.']);
            return;
        }

        foreach ($chunkPlan as $chunkMeta) {
            $globalIndex = $chunkMeta['index'];

            if ($isRetryOnly && !in_array($globalIndex, $this->onlyChunkIndices, true)) {
                continue;
            }
            // Idempotent resume for a FRESH pass: a chunk already 'done' is skipped so a
            // naive re-dispatch doesn't redo work. This does NOT apply in retry-only mode —
            // there, the caller (e.g. "retry failed chunks", or story-direction:regenerate-
            // stale forcing a re-run of a chunk made stale by a Story Bible version change)
            // has already decided exactly which chunks need reprocessing; a chunk explicitly
            // named in $onlyChunkIndices is reprocessed regardless of its current status.
            if (!$isRetryOnly && $chunkMeta['status'] === 'done') {
                continue;
            }

            $items = $chunkShots[$globalIndex] ?? [];
            if (empty($items)) {
                continue;
            }
            $scene = $items[0]['scene'];
            $shotChunkInput = array_map(fn($it) => ['index' => $it['local_index'], 'text' => $it['text']], $items);
            $bible = $bibleFor($scene);

            // Without this, a continuous event split across a chunk boundary (this chunk
            // starting mid-event) has zero visibility into what the previous chunk already
            // established — the model then invents a brand-new setting/character wardrobe
            // from nothing instead of continuing it. Read from the DB (not an in-run map) so
            // this works identically whether the previous chunk ran in this job execution or
            // a prior one.
            $previousContext = $bible ? $this->buildCarryOverContext($service, $chunkPlan, $globalIndex, $scene->id) : '';

            $lastError = null;
            $success = false;
            $attempts = 0;

            foreach (array_merge([0], self::RETRY_DELAYS) as $delay) {
                if ($delay > 0) {
                    sleep($delay);
                }
                $attempts++;

                try {
                    $enrichResult = $service->enrichShotsChunk($shotChunkInput, $audioBook, $scene->title, $scene->scene_type, $contextHint, [
                        'book_id' => $this->audioBookId,
                        'scene_id' => $scene->id,
                        'chunk_index' => $globalIndex,
                        'job_attempt' => $attempts,
                    ], $stableContextFor($scene), $bible, $previousContext);
                    $enriched = $enrichResult['shots'];

                    foreach ($items as $it) {
                        $data = $enriched[$it['local_index']] ?? null;
                        $it['shot']->update([
                            'keywords' => $data['keywords'] ?? [$scene->scene_type],
                            'image_request' => $data['image_request'] ?? null,
                            'is_real_world' => $data['is_real_world'] ?? true,
                            'enrichment_status' => 'enriched',
                            'prompt_version' => VideoSceneAnalysisService::PROMPT_VERSION,
                            'story_bible_version_used' => $scene->story_bible_version_used,
                            'enriched_at' => now(),
                        ]);
                    }

                    if ($bible) {
                        $chunkShotModels = [];
                        $enrichmentByShotIndex = [];
                        foreach ($items as $it) {
                            $chunkShotModels[] = $it['shot'];
                            $enrichmentByShotIndex[$it['shot']->shot_index] = $enriched[$it['local_index']] ?? [];
                        }
                        $service->persistChunkContext($chunkShotModels, $bible, $enrichResult['chunk_context'], $enrichmentByShotIndex);
                    }

                    $success = true;
                    break;
                } catch (\Throwable $e) {
                    $lastError = $e->getMessage();
                    Log::warning('EnrichVideoShotsJob: chunk attempt failed', [
                        'audio_book_id' => $this->audioBookId,
                        'scene_id' => $scene->id,
                        'chunk' => $globalIndex,
                        'attempt' => $attempts,
                        'error_type' => get_class($e),
                        'error' => $lastError,
                    ]);
                }
            }

            if (!$success) {
                foreach ($items as $it) {
                    $it['shot']->update(['enrichment_status' => 'failed']);
                }
            }

            $this->updateChunkStatus($pipeline, $globalIndex, $success ? 'done' : 'failed', $attempts, $lastError);
        }

        // Avatar placement needs the complete, final shot list — only run it after a fresh
        // full pass, not a partial "retry failed chunks" run (which may leave other chunks
        // from the original run still failed).
        if (!$isRetryOnly) {
            $this->applyAvatarPlacement($service, $scenes);
        }

        $finalChunks = $pipeline->fresh()->shot_chunks ?? [];
        $hasFailed = collect($finalChunks)->contains(fn($c) => $c['status'] === 'failed');

        $pipeline->update([
            'status' => $hasFailed ? 'analyzed_with_errors' : 'analyzed',
            'current_stage' => 'done',
            'last_heartbeat_at' => now(),
        ]);
    }

    /**
     * Fresh run: pure-PHP shot splitting (no AI) + idempotent placeholder row creation for
     * every shot, then group into ≤15-shot chunks scoped within each scene.
     *
     * @return array{0:array<int,array{index:int,scene_id:int,status:string,attempts:int,error:?string,shot_ids:array<int,int>}>,1:array<int,array<int,array{scene:AudiobookVideoScene,shot:AudiobookVideoShot,local_index:int,text:string}>>}
     */
    private function buildFreshChunkPlan(VideoSceneAnalysisService $service, $scenes): array
    {
        $chunkPlan = [];
        $chunkShots = [];
        $globalIndex = 0;

        foreach ($scenes as $scene) {
            $sentences = $service->splitIntoSentences($scene->script_text);
            $shotTexts = $service->groupSentencesIntoShots($sentences);
            if (empty($shotTexts)) {
                $shotTexts = [$scene->script_text];
            }

            $shotModels = [];
            foreach ($shotTexts as $i => $text) {
                $shotModels[] = AudiobookVideoShot::updateOrCreate(
                    ['video_scene_id' => $scene->id, 'shot_index' => $i + 1],
                    [
                        'sentence_text' => $text,
                        'estimated_duration_seconds' => $service->estimateDurationSeconds($text, 5),
                        'status' => 'pending',
                    ]
                );
            }

            foreach (array_chunk($shotModels, self::CHUNK_SIZE) as $group) {
                $items = [];
                $alreadyEnriched = true;
                foreach ($group as $localI => $shotModel) {
                    $items[] = [
                        'scene' => $scene,
                        'shot' => $shotModel,
                        'local_index' => $localI + 1,
                        'text' => $shotModel->sentence_text,
                    ];
                    $isCurrent = $shotModel->enrichment_status === 'enriched'
                        && $shotModel->prompt_version === VideoSceneAnalysisService::PROMPT_VERSION
                        // A shot enriched under an older Story Bible version is stale too,
                        // even if prompt_version itself hasn't changed — its stable-context
                        // block was built from data that's since been superseded.
                        && $shotModel->story_bible_version_used === $scene->story_bible_version_used;
                    if (!$isCurrent) {
                        $alreadyEnriched = false;
                    }
                }

                // A chunk whose shots ALL already carry an 'enriched' status at the CURRENT
                // prompt_version AND the scene's CURRENT story_bible_version_used is skipped
                // entirely on re-run — updateOrCreate() above never touches these columns, so
                // they survive re-splitting. This is what makes a naive re-dispatch (stale
                // worker, manual retry) cost zero extra OpenAI calls, while a PROMPT_VERSION
                // bump or a Story Bible regenerate correctly forces re-enrichment of stale
                // shots instead of relying on keywords/image_request non-emptiness as a proxy.
                $chunkPlan[] = [
                    'index' => $globalIndex,
                    'scene_id' => $scene->id,
                    'status' => $alreadyEnriched ? 'done' : 'pending',
                    'attempts' => 0,
                    'error' => null,
                    'shot_ids' => array_map(fn($it) => $it['shot']->id, $items),
                ];
                $chunkShots[$globalIndex] = $items;
                $globalIndex++;
            }
        }

        AudiobookVideoPipeline::where('audio_book_id', $this->audioBookId)->update(['shot_chunks' => $chunkPlan]);

        return [$chunkPlan, $chunkShots];
    }

    /**
     * Retry-failed-chunks path: rebuild the same working shape from the persisted plan +
     * existing shot rows, without touching pure-PHP splitting or creating anything new.
     */
    private function reconstructChunkPlan(AudiobookVideoPipeline $pipeline): array
    {
        $chunkPlan = $pipeline->shot_chunks ?? [];
        if (empty($chunkPlan)) {
            return [[], []];
        }

        $allShotIds = collect($chunkPlan)->flatMap(fn($c) => $c['shot_ids'])->all();
        $shotsById = AudiobookVideoShot::with('scene')->whereIn('id', $allShotIds)->get()->keyBy('id');

        $chunkShots = [];
        foreach ($chunkPlan as $chunkMeta) {
            $items = [];
            foreach ($chunkMeta['shot_ids'] as $localI => $shotId) {
                $shot = $shotsById->get($shotId);
                if (!$shot) {
                    continue;
                }
                $items[] = [
                    'scene' => $shot->scene,
                    'shot' => $shot,
                    'local_index' => $localI + 1,
                    'text' => $shot->sentence_text,
                ];
            }
            $chunkShots[$chunkMeta['index']] = $items;
        }

        return [$chunkPlan, $chunkShots];
    }

    /**
     * Finds the chunk immediately preceding $globalIndex WITHIN THE SAME SCENE (chunks never
     * span a scene boundary) and summarizes its last shot's already-persisted local context
     * for carry-over — reads from the DB, not an in-run cache, so this works identically
     * whether that previous chunk was processed in this job execution or a prior one (e.g.
     * a "retry failed chunks" run that only reprocesses one chunk).
     */
    private function buildCarryOverContext(VideoSceneAnalysisService $service, array $chunkPlan, int $globalIndex, int $sceneId): string
    {
        $previousChunkMeta = null;
        foreach ($chunkPlan as $chunkMeta) {
            if ($chunkMeta['index'] === $globalIndex - 1 && $chunkMeta['scene_id'] === $sceneId) {
                $previousChunkMeta = $chunkMeta;
                break;
            }
        }
        if (!$previousChunkMeta || empty($previousChunkMeta['shot_ids'])) {
            return '';
        }

        $lastShotId = end($previousChunkMeta['shot_ids']);
        $lastShot = AudiobookVideoShot::find($lastShotId);
        if (!$lastShot || $lastShot->narrative_mode === null) {
            return ''; // previous chunk was never enriched with local context — nothing to carry over
        }

        return $service->summarizeChunkContextForCarryOver($lastShot);
    }

    private function updateChunkStatus(AudiobookVideoPipeline $pipeline, int $globalIndex, string $status, int $attempts, ?string $error): void
    {
        $pipeline->refresh();
        $chunks = $pipeline->shot_chunks ?? [];

        foreach ($chunks as &$chunk) {
            if ($chunk['index'] === $globalIndex) {
                $chunk['status'] = $status;
                $chunk['attempts'] = $attempts;
                $chunk['error'] = $error;
                break;
            }
        }
        unset($chunk);

        $pipeline->update(['shot_chunks' => $chunks, 'last_heartbeat_at' => now()]);
    }

    private function applyAvatarPlacement(VideoSceneAnalysisService $service, $scenes): void
    {
        $shots = AudiobookVideoShot::whereIn('video_scene_id', $scenes->pluck('id'))
            ->with('scene')
            ->get()
            ->sortBy([['video_scene_id', 'asc'], ['shot_index', 'asc']])
            ->values();

        $flat = $shots->map(fn($shot) => [
            'shot' => $shot,
            'is_emotional_climax' => (bool) $shot->scene->is_emotional_climax,
            'duration' => $shot->estimated_duration_seconds,
        ])->all();

        $totalDuration = array_sum(array_column($flat, 'duration'));
        $avatarFlags = $service->computeAvatarPlacement($flat, $totalDuration);

        foreach ($flat as $i => $entry) {
            $entry['shot']->update(['is_avatar_segment' => $avatarFlags[$i] ?? false]);
        }
    }
}
