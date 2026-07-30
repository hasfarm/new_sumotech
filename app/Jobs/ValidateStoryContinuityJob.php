<?php

namespace App\Jobs;

use App\Models\AudioBook;
use App\Models\AudiobookContinuityValidationRun;
use App\Models\AudiobookStoryBible;
use App\Models\AudiobookVideoScene;
use App\Models\AudiobookVideoShot;
use App\Services\ContinuityValidationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Validates already-resolved scene/shot context against the active Story Bible — never
 * re-analyzes the whole work. Ledger-tracked per scene (AudiobookContinuityValidationRun),
 * resumable, and resilient: a scene whose AI check fails does NOT abort the run or touch
 * that scene's shots' issues — it's recorded as a failed batch entry and those shots'
 * validation_status is set to 'failed' so a stuck 'regenerating' issue is never silently
 * resolved by a validation pass that couldn't actually check it.
 */
class ValidateStoryContinuityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries = 1;

    public function __construct(
        public readonly int $audioBookId,
        /** @var array<int,int>|null null = every scene in the book */
        public readonly ?array $onlySceneIds = null,
        /** @var array<int,int>|null when given, only these shot ids' state is persisted (scene-level issues still apply) */
        public readonly ?array $onlyShotIds = null
    ) {}

    public function handle(ContinuityValidationService $service): void
    {
        $audioBook = AudioBook::find($this->audioBookId);
        if (!$audioBook) {
            Log::error('ValidateStoryContinuityJob: audiobook not found', ['audio_book_id' => $this->audioBookId]);
            return;
        }

        $activeBible = AudiobookStoryBible::where('audio_book_id', $this->audioBookId)->where('is_active', true)->first();
        if (!$activeBible) {
            Log::warning('ValidateStoryContinuityJob: no active Story Bible, nothing to validate against', ['audio_book_id' => $this->audioBookId]);
            return;
        }

        $scenesQuery = AudiobookVideoScene::where('audio_book_id', $this->audioBookId);
        $shotIndicesByScene = [];

        if ($this->onlyShotIds !== null) {
            $targetShots = AudiobookVideoShot::whereIn('id', $this->onlyShotIds)->get();
            foreach ($targetShots as $shot) {
                $shotIndicesByScene[$shot->video_scene_id][] = $shot->shot_index;
            }
            $scenesQuery->whereIn('id', array_keys($shotIndicesByScene));
        } elseif ($this->onlySceneIds !== null) {
            $scenesQuery->whereIn('id', $this->onlySceneIds);
        }

        $scenes = $scenesQuery->orderBy('scene_index')->get();

        $run = AudiobookContinuityValidationRun::create([
            'audio_book_id' => $this->audioBookId,
            'status' => 'running',
            'scope' => $this->onlyShotIds !== null ? 'shots' : ($this->onlySceneIds !== null ? 'scenes' : 'full'),
            'target_scene_ids' => $this->onlySceneIds,
            'target_shot_ids' => $this->onlyShotIds,
            'continuity_validator_version' => ContinuityValidationService::VALIDATOR_VERSION,
            'batches' => $scenes->map(fn($s) => ['scene_id' => $s->id, 'status' => 'pending', 'attempts' => 0, 'error' => null])->all(),
            'total_scenes' => $scenes->count(),
            'processed_scenes' => 0,
            'started_at' => now(),
        ]);

        $processed = 0;
        foreach ($scenes as $scene) {
            $onlyIndices = $shotIndicesByScene[$scene->id] ?? null;

            try {
                $service->validateScene($scene, $activeBible, $run, $onlyIndices);
                $this->updateBatchStatus($run, $scene->id, 'done', null);
            } catch (\Throwable $e) {
                Log::error('ValidateStoryContinuityJob: scene validation failed', [
                    'audio_book_id' => $this->audioBookId,
                    'scene_id' => $scene->id,
                    'error' => $e->getMessage(),
                ]);
                $this->updateBatchStatus($run, $scene->id, 'failed', $e->getMessage());

                // Deliberately does NOT touch any issue row — a shot whose scene couldn't be
                // checked must never have a 'regenerating' issue silently marked resolved.
                $targetShots = $onlyIndices !== null
                    ? $scene->shots()->whereIn('shot_index', $onlyIndices)->get()
                    : $scene->shots;
                foreach ($targetShots as $shot) {
                    $shot->update(['validation_status' => 'failed']);
                }
            }

            $processed++;
            $run->update(['processed_scenes' => $processed]);
        }

        $run->update(['status' => 'completed', 'completed_at' => now()]);
    }

    private function updateBatchStatus(AudiobookContinuityValidationRun $run, int $sceneId, string $status, ?string $error): void
    {
        $run->refresh();
        $batches = $run->batches ?? [];

        foreach ($batches as &$batch) {
            if ($batch['scene_id'] === $sceneId) {
                $batch['status'] = $status;
                $batch['attempts'] = ($batch['attempts'] ?? 0) + 1;
                $batch['error'] = $error;
                break;
            }
        }
        unset($batch);

        $run->update(['batches' => $batches]);
    }
}
