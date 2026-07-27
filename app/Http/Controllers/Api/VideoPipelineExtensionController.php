<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\AudioBookVideoPipelineController;
use App\Http\Controllers\Controller;
use App\Jobs\ArchiveAssetToLibraryJob;
use App\Models\AudioBook;
use App\Models\AudiobookVideoShot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Sanctum-token-authenticated endpoints for the companion Chrome extension (chrome-extension/
 * directory) — lets the user hand off a manually-downloaded Storyblocks clip to the correct
 * shot without leaving the browser. Kept separate from AudioBookVideoPipelineController
 * (session-authed, same-origin) since these are cross-origin, token-authed requests from a
 * chrome-extension:// context.
 */
class VideoPipelineExtensionController extends Controller
{
    /**
     * The shot the user was last browsing keywords for (set by
     * AudioBookVideoPipelineController::setActiveTarget() when a keyword chip is clicked).
     */
    public function activeTarget(Request $request)
    {
        $target = Cache::get(AudioBookVideoPipelineController::activeTargetCacheKey($request->user()->id));

        return response()->json(['success' => true, 'target' => $target]);
    }

    /**
     * Books with an active video pipeline, for the extension's manual shot-override picker.
     */
    public function books(Request $request)
    {
        $books = AudioBook::query()
            ->whereHas('videoPipeline')
            ->select(['id', 'title'])
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response()->json(['success' => true, 'books' => $books]);
    }

    /**
     * Scenes + shots (with keywords/status) for one book, for the manual override picker.
     */
    public function shots(Request $request, AudioBook $audioBook)
    {
        $scenes = $audioBook->videoScenes()
            ->with(['shots' => function ($q) {
                $q->select(['id', 'video_scene_id', 'shot_index', 'sentence_text', 'keywords', 'status', 'is_avatar_segment']);
            }])
            ->select(['id', 'audio_book_id', 'scene_index', 'title'])
            ->orderBy('scene_index')
            ->get();

        return response()->json(['success' => true, 'scenes' => $scenes]);
    }

    /**
     * The actual clip handoff: the extension uploads the file the user just downloaded from
     * Storyblocks. Saved into the shot's normal local folder (same convention as every other
     * source), marked ready, then archived into the reusable library exactly like every other
     * resolved asset this pipeline produces.
     */
    public function ingest(Request $request, AudiobookVideoShot $shot)
    {
        if ($shot->is_avatar_segment) {
            return response()->json(['success' => false, 'message' => 'Shot này là đoạn avatar, không nhận footage ngoài.'], 422);
        }

        $request->validate([
            'video' => 'required|file|mimes:mp4,mov,webm|max:512000', // 500MB
        ]);

        $scene = $shot->scene;
        $audioBookId = $scene->audio_book_id;
        $ext = strtolower($request->file('video')->getClientOriginalExtension()) ?: 'mp4';
        $uuid = (string) Str::uuid();
        $relativeDir = "audiobooks/{$audioBookId}/video-pipeline/scenes/{$scene->id}/shots/{$shot->id}";

        $uploadedPath = $request->file('video')->storeAs($relativeDir, "storyblocks_{$uuid}.{$ext}", 'public');
        $path = $ext === 'mp4' ? $uploadedPath : $this->transcodeToMp4($uploadedPath, $relativeDir, $uuid);

        $shot->update([
            'status' => 'ready',
            'resolved_source' => 'storyblocks',
            'resolved_asset_path' => $path,
            // No AI scoring involved — the user manually curated this from their own
            // Storyblocks account, treated as a trusted high-quality pick.
            'resolved_score' => 90,
            'error_message' => null,
        ]);

        ArchiveAssetToLibraryJob::dispatch($shot->id, 'storyblocks', null, null);

        return response()->json(['success' => true, 'shot' => $shot->fresh()]);
    }

    /**
     * Storyblocks downloads are often .mov (sometimes with codecs Chrome's <video> tag won't
     * play at all) — transcode everything that isn't already .mp4 to standard H.264/AAC .mp4
     * so preview/modal/zip-download all behave exactly like every other source in this
     * pipeline, which is always .mp4. Falls back to the original file if ffmpeg fails, rather
     * than losing the upload entirely.
     */
    private function transcodeToMp4(string $uploadedRelativePath, string $relativeDir, string $uuid): string
    {
        $sourceAbsolute = storage_path('app/public/' . $uploadedRelativePath);
        $outputRelative = "{$relativeDir}/storyblocks_{$uuid}.mp4";
        $outputAbsolute = storage_path('app/public/' . $outputRelative);

        $ffmpegPath = (string) config('services.ffmpeg.path', 'ffmpeg');
        $command = sprintf(
            '%s -y -i %s -c:v libx264 -preset veryfast -crf 20 -c:a aac -movflags +faststart %s 2>&1',
            escapeshellarg($ffmpegPath),
            escapeshellarg($sourceAbsolute),
            escapeshellarg($outputAbsolute)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($outputAbsolute) || filesize($outputAbsolute) === 0) {
            return $uploadedRelativePath;
        }

        @unlink($sourceAbsolute);

        return $outputRelative;
    }
}
