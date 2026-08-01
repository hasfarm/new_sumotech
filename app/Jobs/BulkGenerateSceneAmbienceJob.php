<?php

namespace App\Jobs;

use App\Models\AudiobookVideoPipeline;
use App\Models\AudiobookVideoScene;
use App\Services\AudioAssetLibraryService;
use App\Services\VideoSceneAnalysisService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * "✨ Tạo tất cả" for scene-level AMBIENCE baselines — bulk-fills every scene flagged
 * needs_ambience=true whose ambience_status is still pending/rejected (never touches
 * approved/generated/locked — see AudioDirectionController::assertMutable()'s same
 * invariant). Same resumable/progress-tracked shape as BulkGenerateNarrationTtsJob: search-first
 * (fingerprint/Qdrant via AudioAssetLibraryService::findMatch()) before ever calling
 * ElevenLabs, exactly like a single manual "✨ Tạo" click would.
 */
class BulkGenerateSceneAmbienceJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 10800;
    public int $tries = 1;
    public int $uniqueFor = 10800;

    public function __construct(public readonly int $audioBookId) {}

    public function uniqueId(): string
    {
        return (string) $this->audioBookId;
    }

    public function handle(AudioAssetLibraryService $audioLibrary): void
    {
        $pipeline = AudiobookVideoPipeline::firstOrCreate(['audio_book_id' => $this->audioBookId]);

        $current = $pipeline->bulk_scene_ambience_status;
        if ($current && ($current['status'] ?? null) === 'running') {
            return;
        }

        $contextHint = (string) $pipeline->context_hint;
        $total = $this->pendingScenesQuery()->count();

        $pipeline->update(['bulk_scene_ambience_status' => [
            'status' => 'running',
            'total' => $total,
            'processed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'started_at' => now()->toIso8601String(),
            'last_progress_at' => now()->toIso8601String(),
            'last_error' => null,
        ]]);

        $processed = 0;
        $succeeded = 0;
        $failed = 0;
        $failedThisRun = [];

        while (true) {
            $query = $this->pendingScenesQuery();
            if (!empty($failedThisRun)) {
                $query->whereNotIn('id', $failedThisRun);
            }
            $scene = $query->orderBy('scene_index')->first();

            if (!$scene) {
                break;
            }

            try {
                $match = $audioLibrary->findMatch('ambience', $scene->ambience_prompt, $scene->ambience_keywords ?? [], null, $contextHint);
                if ($match) {
                    $audioLibrary->recordReuse($match['asset']);
                    $asset = $match['asset'];
                } else {
                    $asset = $audioLibrary->generateAndArchive(
                        'ambience',
                        $scene->ambience_prompt,
                        $scene->ambience_keywords ?? [],
                        null,
                        $this->audioBookId,
                        VideoSceneAnalysisService::AUDIO_PROMPT_VERSION
                    );
                }

                $scene->update(['ambience_asset_id' => $asset->id, 'ambience_status' => 'generated']);
                $succeeded++;
            } catch (\Throwable $e) {
                $failedThisRun[] = $scene->id;
                $failed++;
                Log::warning('BulkGenerateSceneAmbienceJob: scene failed', [
                    'audio_book_id' => $this->audioBookId,
                    'scene_id' => $scene->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $processed++;
            $pipeline->refresh();
            $pipeline->update(['bulk_scene_ambience_status' => array_merge($pipeline->bulk_scene_ambience_status ?? [], [
                'processed' => $processed,
                'succeeded' => $succeeded,
                'failed' => $failed,
                'last_progress_at' => now()->toIso8601String(),
            ])]);
        }

        $pipeline->refresh();
        $pipeline->update(['bulk_scene_ambience_status' => array_merge($pipeline->bulk_scene_ambience_status ?? [], [
            'status' => 'done',
            'last_progress_at' => now()->toIso8601String(),
        ])]);
    }

    private function pendingScenesQuery()
    {
        return AudiobookVideoScene::where('audio_book_id', $this->audioBookId)
            ->where('needs_ambience', true)
            ->where(function ($q) {
                $q->whereNull('ambience_status')->orWhereIn('ambience_status', ['pending', 'rejected']);
            });
    }
}
