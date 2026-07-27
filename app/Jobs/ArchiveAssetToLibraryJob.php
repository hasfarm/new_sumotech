<?php

namespace App\Jobs;

use App\Models\AudiobookVideoShot;
use App\Services\AssetLibrary\AssetLibraryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Archives a shot's just-won asset (stock download, AI image, or AI video) into the
 * reusable library — R2 upload + MySQL metadata row + Qdrant embedding. Queued and
 * fire-and-forget so R2/embedding latency never blocks the user-facing resolve/animate
 * response. Never dispatched when the shot's asset ALREADY came from the library (nothing
 * new to archive in that case — see AudioBookVideoPipelineController::searchShot()).
 */
class ArchiveAssetToLibraryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 1;

    public function __construct(
        public readonly int $shotId,
        public readonly string $originSource,
        public readonly ?string $externalId = null,
        public readonly ?string $licenseLabel = null
    ) {}

    public function handle(AssetLibraryService $service): void
    {
        $shot = AudiobookVideoShot::find($this->shotId);
        if (!$shot || !$shot->resolved_asset_path) {
            return;
        }

        $localPath = storage_path('app/public/' . $shot->resolved_asset_path);
        if (!file_exists($localPath)) {
            return;
        }

        try {
            $asset = $service->archive(
                $shot,
                $localPath,
                $this->originSource,
                (float) ($shot->resolved_score ?? 75),
                $this->externalId,
                $this->licenseLabel
            );

            $shot->update(['resolved_library_asset_id' => $asset->id]);
        } catch (\Throwable $e) {
            // Archiving is a best-effort background enhancement — never fail the shot over it.
            Log::warning('ArchiveAssetToLibraryJob failed', [
                'shot_id' => $this->shotId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
