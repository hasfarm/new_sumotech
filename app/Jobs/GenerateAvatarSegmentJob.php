<?php

namespace App\Jobs;

use App\Models\AudiobookVideoShot;
use App\Services\AvatarSegmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateAvatarSegmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries = 1;

    public function __construct(public readonly int $shotId) {}

    public function handle(AvatarSegmentService $avatarService): void
    {
        $shot = AudiobookVideoShot::find($this->shotId);
        if (!$shot) {
            return;
        }

        $shot->update(['status' => 'resolving', 'error_message' => null]);

        try {
            $path = $avatarService->generateSegment($shot);

            $shot->update([
                'status' => 'ready',
                'resolved_source' => 'avatar',
                'avatar_video_path' => $path,
            ]);
        } catch (\Throwable $e) {
            Log::error('GenerateAvatarSegmentJob failed', ['shot_id' => $this->shotId, 'error' => $e->getMessage()]);
            $shot->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
        }
    }
}
