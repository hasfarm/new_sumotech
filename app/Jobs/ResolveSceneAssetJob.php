<?php

namespace App\Jobs;

use App\Models\AudiobookVideoShot;
use App\Services\SceneAssetResolverService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Step 2 of the AI fallback: animate an already-generated/approved still image into a
 * short clip. Runs as a queued job because Kling/Seedance can take minutes to complete —
 * unlike the image generation step (Flux, ~5-10s), which runs synchronously in the
 * controller.
 */
class ResolveSceneAssetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries = 1;

    public function __construct(
        public readonly int $shotId,
        public readonly string $imageRelativePath,
        public readonly string $provider = 'seedance'
    ) {}

    public function handle(SceneAssetResolverService $resolver): void
    {
        $shot = AudiobookVideoShot::find($this->shotId);
        if (!$shot) {
            return;
        }

        $shot->update(['status' => 'resolving', 'error_message' => null]);

        try {
            $path = $resolver->animateImage($shot, $this->imageRelativePath, $this->provider);

            $shot->update([
                'status' => 'ready',
                'resolved_source' => 'ai_video',
                'resolved_asset_path' => $path,
            ]);

            ArchiveAssetToLibraryJob::dispatch($shot->id, 'ai_video', null);
        } catch (\Throwable $e) {
            Log::error('ResolveSceneAssetJob failed', ['shot_id' => $this->shotId, 'error' => $e->getMessage()]);
            // Keep the already-approved still image around on failure — fall back to
            // 'image_ready' rather than 'failed' so the user doesn't lose the preview.
            $shot->update(['status' => 'image_ready', 'error_message' => $e->getMessage()]);
        }
    }
}
