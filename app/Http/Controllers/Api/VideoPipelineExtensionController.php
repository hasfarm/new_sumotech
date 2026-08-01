<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\AudioBookVideoPipelineController;
use App\Http\Controllers\AudioDirectionController;
use App\Http\Controllers\Controller;
use App\Jobs\ArchiveAssetToLibraryJob;
use App\Models\AudioBook;
use App\Models\AudiobookVideoScene;
use App\Models\AudiobookVideoShot;
use App\Services\AssetLibrary\R2StorageService;
use App\Services\AudioAssetLibraryService;
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
    public function __construct(
        private readonly AudioAssetLibraryService $audioLibrary,
        private readonly R2StorageService $r2Storage
    ) {}

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

    /**
     * The audio slot the user was last browsing for (set by
     * AudioDirectionController::setActiveAudioTargetForScene()/ForShot()).
     */
    public function activeAudioTarget(Request $request)
    {
        $target = Cache::get(AudioDirectionController::activeAudioTargetCacheKey($request->user()->id));

        return response()->json(['success' => true, 'target' => $target]);
    }

    /**
     * The audio equivalent of ingest(): the extension uploads a manually-downloaded Storyblocks
     * clip for the CURRENTLY CACHED audio target (scene ambience/music baseline, or shot
     * sfx/override — whichever slot the user was browsing for). Unlike ingest() for video (which
     * marks the shot 'ready' immediately, a trusted auto-approve), this deliberately archives
     * the clip as just another library candidate and attaches it to the slot with status
     * 'generated' — NEVER 'approved'/'locked' — the user must still explicitly review/approve
     * it via the Audio panel, per the approval-first design.
     */
    public function ingestAudio(Request $request)
    {
        $target = Cache::get(AudioDirectionController::activeAudioTargetCacheKey($request->user()->id));
        if (!$target) {
            return response()->json(['success' => false, 'message' => 'Chưa xác định được audio slot đích — hãy bấm "Tìm trên Storyblocks" cho đúng slot trong app trước.'], 422);
        }

        $request->validate([
            'audio' => 'required|file|mimes:mp3,wav,m4a,ogg,aac|max:51200', // 50MB
        ]);

        $slot = $target['slot'];
        if ($target['target_type'] === 'scene') {
            $model = AudiobookVideoScene::findOrFail($target['scene_id']);
        } else {
            $model = AudiobookVideoShot::findOrFail($target['shot_id']);
        }

        $file = $request->file('audio');
        $ext = strtolower($file->getClientOriginalExtension()) ?: 'mp3';
        $uuid = (string) Str::uuid();
        $tempRelative = $file->storeAs('tmp', "storyblocks_audio_{$uuid}.{$ext}", 'local');
        $tempAbsolute = storage_path('app/' . $tempRelative);

        try {
            $duration = $this->probeAudioDuration($tempAbsolute);

            $r2Path = "audio_library/{$slot}/" . date('Y') . "/{$uuid}.{$ext}";
            $this->r2Storage->putFile($tempAbsolute, $r2Path);

            $asset = $this->audioLibrary->archive(
                category: $slot,
                provider: 'storyblocks',
                originSource: 'storyblocks',
                r2Path: $r2Path,
                prompt: $target['prompt'] !== '' ? $target['prompt'] : "{$slot} (Storyblocks manual)",
                keywords: [],
                durationSeconds: $duration,
                isLoopable: $slot !== 'sfx',
                licenseLabel: 'Storyblocks (theo giấy phép tài khoản Storyblocks của người dùng)',
                sourceBookId: $target['audio_book_id'] ?? null
            );
        } finally {
            @unlink($tempAbsolute);
        }

        $update = [
            "{$slot}_asset_id" => $asset->id,
            "{$slot}_status" => 'generated',
            "{$slot}_selected_by" => $request->user()->id,
            "{$slot}_approved_by" => null,
            "{$slot}_approved_at" => null,
        ];
        if ($model instanceof AudiobookVideoShot && in_array($slot, ['ambience', 'music'], true)) {
            $update["{$slot}_override"] = true;
        }
        $model->update($update);

        return response()->json(['success' => true, 'asset_id' => $asset->id]);
    }

    private function probeAudioDuration(string $path): ?float
    {
        $ffprobePath = (string) config('services.ffmpeg.ffprobe_path', 'ffprobe');
        $command = sprintf(
            '%s -v error -show_entries format=duration -of csv=p=0 %s 2>&1',
            escapeshellarg($ffprobePath),
            escapeshellarg($path)
        );
        exec($command, $output, $returnCode);
        if ($returnCode === 0 && !empty($output[0]) && is_numeric(trim($output[0]))) {
            return round((float) trim($output[0]), 3);
        }

        return null;
    }
}
