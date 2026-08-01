<?php

namespace App\Services;

use App\Models\AudiobookVideoScene;
use App\Models\AudiobookVideoShot;
use Illuminate\Support\Collection;

/**
 * Shared shot-selection logic for the motion/transition pilot — used by BOTH
 * `motion-direction:pilot` (runs the AI + renders) and `motion-direction:pilot-export` (stitches
 * whatever those runs already produced), so a review always inspects the exact same shots that
 * were generated. Picks ~10-15 shots covering the edge cases the plan calls out: the very first
 * shot of the book, a non-first avatar/host shot, an already-video shot, up to 2 emotional-climax
 * scenes, a run of 4 consecutive still shots for preset-variety review, and the book's last shot.
 */
class MotionPilotShotSelector
{
    public function select(int $audioBookId): Collection
    {
        $scenes = AudiobookVideoScene::where('audio_book_id', $audioBookId)->orderBy('scene_index')->get();
        if ($scenes->isEmpty()) {
            return collect();
        }

        $ids = [];

        $firstScene = $scenes->first();
        $firstShot = AudiobookVideoShot::where('video_scene_id', $firstScene->id)->orderBy('shot_index')->first();
        if ($firstShot) {
            $ids[] = $firstShot->id;
        }

        $nonFirstAvatar = AudiobookVideoShot::whereIn('video_scene_id', $scenes->pluck('id'))
            ->where('is_avatar_segment', true)
            ->whereNotNull('avatar_video_path')
            ->where('id', '!=', $firstShot->id ?? 0)
            ->orderBy('id')
            ->first();
        if ($nonFirstAvatar) {
            $ids[] = $nonFirstAvatar->id;
        }

        $videoSourced = AudiobookVideoShot::whereIn('video_scene_id', $scenes->pluck('id'))
            ->where('is_avatar_segment', false)
            ->where(function ($q) {
                $q->where('resolved_asset_path', 'like', '%.mp4')
                    ->orWhere('resolved_asset_path', 'like', '%.mov')
                    ->orWhere('resolved_asset_path', 'like', '%.webm');
            })
            ->orderBy('id')
            ->first();
        if ($videoSourced) {
            $ids[] = $videoSourced->id;
        }

        foreach ($scenes->where('is_emotional_climax', true)->take(2) as $climaxScene) {
            $s = AudiobookVideoShot::where('video_scene_id', $climaxScene->id)
                ->where('is_avatar_segment', false)
                ->orderBy('shot_index')
                ->first();
            if ($s) {
                $ids[] = $s->id;
            }
        }

        // A run of 4 consecutive still shots within the largest scene, for preset-variety review.
        $run = AudiobookVideoShot::where('video_scene_id', $firstScene->id)
            ->where('is_avatar_segment', false)
            ->where(function ($q) {
                $q->whereNull('resolved_asset_path')
                    ->orWhere(function ($q2) {
                        $q2->where('resolved_asset_path', 'not like', '%.mp4')
                            ->where('resolved_asset_path', 'not like', '%.mov')
                            ->where('resolved_asset_path', 'not like', '%.webm');
                    });
            })
            ->orderBy('shot_index')
            ->skip(15)->take(4)
            ->pluck('id');
        $ids = array_merge($ids, $run->all());

        $lastScene = $scenes->last();
        $lastShot = AudiobookVideoShot::where('video_scene_id', $lastScene->id)->orderByDesc('shot_index')->first();
        if ($lastShot) {
            $ids[] = $lastShot->id;
        }

        $ids = array_values(array_unique($ids));

        return AudiobookVideoShot::whereIn('id', $ids)->orderBy('video_scene_id')->orderBy('shot_index')->get();
    }
}
