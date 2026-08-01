<?php

namespace App\Http\Controllers;

use App\Models\AudioBook;
use App\Models\AudiobookVideoScene;
use App\Models\AudiobookVideoShot;
use App\Services\MotionDirectionService;
use App\Services\MotionRenderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Approval-first AI-selected motion (Ken Burns zoompan) + shot-transition endpoints — mirrors
 * AudioDirectionController's shape (generate/select/reject/approve/lock/unlock, same
 * status+approval-trail convention) but scoped to a single shot with 2 slots ('motion',
 * 'transition') instead of 5 scene/shot audio slots, and with no "candidates/library" concept —
 * every render here is fresh and deterministic given its (whitelisted) inputs, never reused.
 *
 * The AI (MotionDirectionService) only ever returns a value from a fixed whitelist; PHP
 * (MotionRenderService) is the only thing that ever builds an ffmpeg command. This controller
 * is the boundary that wires the two together and persists the (already-validated) result —
 * it never itself touches a shell command.
 */
class MotionDirectionController extends Controller
{
    public function __construct(
        private readonly MotionDirectionService $motionDirection,
        private readonly MotionRenderService $motionRender
    ) {}

    // ---- Motion ----

    public function generateMotion(Request $request, AudioBook $audioBook, AudiobookVideoScene $scene, AudiobookVideoShot $shot)
    {
        $this->assertShotBelongs($audioBook, $scene, $shot);

        return $this->doMotion($shot, $request, force: false);
    }

    public function regenerateMotion(Request $request, AudioBook $audioBook, AudiobookVideoScene $scene, AudiobookVideoShot $shot)
    {
        $this->assertShotBelongs($audioBook, $scene, $shot);

        return $this->doMotion($shot, $request, force: true);
    }

    public function rejectMotion(Request $request, AudioBook $audioBook, AudiobookVideoScene $scene, AudiobookVideoShot $shot)
    {
        $this->assertShotBelongs($audioBook, $scene, $shot);

        return $this->doReject($shot, 'motion');
    }

    public function approveMotion(Request $request, AudioBook $audioBook, AudiobookVideoScene $scene, AudiobookVideoShot $shot)
    {
        $this->assertShotBelongs($audioBook, $scene, $shot);

        return $this->doApprove($shot, 'motion');
    }

    public function lockMotion(Request $request, AudioBook $audioBook, AudiobookVideoScene $scene, AudiobookVideoShot $shot)
    {
        $this->assertShotBelongs($audioBook, $scene, $shot);

        return $this->doLock($shot, 'motion');
    }

    public function unlockMotion(Request $request, AudioBook $audioBook, AudiobookVideoScene $scene, AudiobookVideoShot $shot)
    {
        $this->assertShotBelongs($audioBook, $scene, $shot);

        return $this->doUnlock($shot, 'motion');
    }

    // ---- Transition (into this shot, from the previous one in book order) ----

    public function generateTransition(Request $request, AudioBook $audioBook, AudiobookVideoScene $scene, AudiobookVideoShot $shot)
    {
        $this->assertShotBelongs($audioBook, $scene, $shot);

        return $this->doTransition($shot, $request, force: false);
    }

    public function regenerateTransition(Request $request, AudioBook $audioBook, AudiobookVideoScene $scene, AudiobookVideoShot $shot)
    {
        $this->assertShotBelongs($audioBook, $scene, $shot);

        return $this->doTransition($shot, $request, force: true);
    }

    public function rejectTransition(Request $request, AudioBook $audioBook, AudiobookVideoScene $scene, AudiobookVideoShot $shot)
    {
        $this->assertShotBelongs($audioBook, $scene, $shot);

        return $this->doReject($shot, 'transition');
    }

    public function approveTransition(Request $request, AudioBook $audioBook, AudiobookVideoScene $scene, AudiobookVideoShot $shot)
    {
        $this->assertShotBelongs($audioBook, $scene, $shot);

        return $this->doApprove($shot, 'transition');
    }

    public function lockTransition(Request $request, AudioBook $audioBook, AudiobookVideoScene $scene, AudiobookVideoShot $shot)
    {
        $this->assertShotBelongs($audioBook, $scene, $shot);

        return $this->doLock($shot, 'transition');
    }

    public function unlockTransition(Request $request, AudioBook $audioBook, AudiobookVideoScene $scene, AudiobookVideoShot $shot)
    {
        $this->assertShotBelongs($audioBook, $scene, $shot);

        return $this->doUnlock($shot, 'transition');
    }

    // ---- Shared implementation ----

    private function doMotion(AudiobookVideoShot $shot, Request $request, bool $force): \Illuminate\Http\JsonResponse
    {
        $this->assertMutable($shot, 'motion');

        if ($shot->is_avatar_segment || $shot->isResolvedAssetVideo()) {
            return response()->json(['success' => false, 'message' => 'Shot này đã là video (MC hoặc nguồn video) — không áp dụng motion cho ảnh tĩnh.'], 422);
        }

        $duration = (float) ($shot->estimated_duration_seconds ?: 5.0);
        $manualPreset = $force ? null : $request->input('preset');

        if ($manualPreset) {
            if (!in_array($manualPreset, MotionDirectionService::MOTION_PRESETS, true)) {
                return response()->json(['success' => false, 'message' => 'Preset không hợp lệ.'], 422);
            }
            $preset = $manualPreset;
            $focusX = max(0.0, min(1.0, (float) $request->input('focus_x', 0.5)));
            $focusY = max(0.0, min(1.0, (float) $request->input('focus_y', 0.5)));
            $intensity = max(0.0, min(1.0, (float) $request->input('intensity', 0.3)));
            $reason = 'Chọn thủ công.';
        } else {
            $bytes = Storage::disk('public')->get($shot->resolved_asset_path);
            if ($bytes === null) {
                return response()->json(['success' => false, 'message' => 'Không đọc được ảnh nguồn của shot này.'], 422);
            }
            $mime = $this->mimeTypeFor($shot->resolved_asset_path);
            $previousContext = $this->buildPreviousMotionContext($shot);
            $contextHint = (string) ($shot->scene->audioBook->videoPipeline->context_hint ?? '');

            $analysis = $this->motionDirection->analyzeShot($shot, $bytes, $mime, false, $previousContext, $contextHint);
            $preset = $analysis['motion_preset'];
            $focusX = $analysis['focus_x'];
            $focusY = $analysis['focus_y'];
            $intensity = $analysis['motion_intensity'];
            $reason = $analysis['motion_reason'];
        }

        $assetPath = $this->motionRender->renderShotMotion($shot, $preset, $focusX, $focusY, $intensity, $duration);

        $shot->update([
            'motion_preset' => $preset,
            'motion_focus_x' => $focusX,
            'motion_focus_y' => $focusY,
            'motion_intensity' => $intensity,
            'motion_duration_seconds' => $duration,
            'motion_reason' => $reason,
            'motion_asset_path' => $assetPath,
            'motion_prompt_version' => MotionDirectionService::MOTION_PROMPT_VERSION,
            'motion_status' => 'generated',
            'motion_selected_by' => Auth::id(),
            'motion_approved_by' => null,
            'motion_approved_at' => null,
        ]);

        return response()->json(['success' => true, 'shot' => $this->slotPayload($shot->fresh(), 'motion')]);
    }

    private function doTransition(AudiobookVideoShot $shot, Request $request, bool $force): \Illuminate\Http\JsonResponse
    {
        $this->assertMutable($shot, 'transition');

        $prevShot = $this->resolvePreviousShot($shot);
        if (!$prevShot) {
            return response()->json(['success' => false, 'message' => 'Đây là shot đầu tiên của sách — không có chuyển cảnh vào.'], 422);
        }

        $manualPreset = $force ? null : $request->input('preset');

        if ($manualPreset) {
            if (!in_array($manualPreset, MotionDirectionService::TRANSITION_PRESETS, true)) {
                return response()->json(['success' => false, 'message' => 'Preset không hợp lệ.'], 422);
            }
            $preset = $manualPreset;
            $intensity = max(0.0, min(1.0, (float) $request->input('intensity', 0.4)));
            $reason = 'Chọn thủ công.';
        } else {
            [$bytes, $mime] = $this->resolveAnalysisImage($shot);
            $previousContext = $this->buildPreviousMotionContext($shot);
            $contextHint = (string) ($shot->scene->audioBook->videoPipeline->context_hint ?? '');

            $analysis = $this->motionDirection->analyzeShot($shot, $bytes, $mime, true, $previousContext, $contextHint);
            $preset = $analysis['transition_preset'] ?? 'cut';
            $intensity = $analysis['transition_intensity'];
            $reason = $analysis['transition_reason'] ?? '';
        }

        $duration = round(0.6 + $intensity * 0.8, 2); // xfade duration, ~0.6-1.4s
        $assetPath = $this->motionRender->renderTransitionPreview($prevShot, $shot, $preset, $duration);

        $shot->update([
            'transition_preset' => $preset,
            'transition_intensity' => $intensity,
            'transition_duration_seconds' => $duration,
            'transition_reason' => $reason,
            'transition_asset_path' => $assetPath,
            'transition_prompt_version' => MotionDirectionService::MOTION_PROMPT_VERSION,
            'transition_status' => 'generated',
            'transition_selected_by' => Auth::id(),
            'transition_approved_by' => null,
            'transition_approved_at' => null,
        ]);

        return response()->json(['success' => true, 'shot' => $this->slotPayload($shot->fresh(), 'transition')]);
    }

    private function doReject(AudiobookVideoShot $shot, string $slot): \Illuminate\Http\JsonResponse
    {
        $this->assertMutable($shot, $slot);

        $shot->update([
            "{$slot}_status" => 'rejected',
            "{$slot}_selected_by" => null,
            "{$slot}_approved_by" => null,
            "{$slot}_approved_at" => null,
        ]);

        return response()->json(['success' => true]);
    }

    private function doApprove(AudiobookVideoShot $shot, string $slot): \Illuminate\Http\JsonResponse
    {
        if (!$shot->{"{$slot}_asset_path"}) {
            return response()->json(['success' => false, 'message' => 'Chưa có bản render để duyệt.'], 422);
        }
        $this->assertMutable($shot, $slot);

        $shot->update([
            "{$slot}_status" => 'approved',
            "{$slot}_approved_by" => Auth::id(),
            "{$slot}_approved_at" => now(),
        ]);

        return response()->json(['success' => true]);
    }

    private function doLock(AudiobookVideoShot $shot, string $slot): \Illuminate\Http\JsonResponse
    {
        if (!$shot->{"{$slot}_asset_path"}) {
            return response()->json(['success' => false, 'message' => 'Chưa có bản render để khóa.'], 422);
        }
        $this->assertMutable($shot, $slot);

        $now = now();
        $update = ["{$slot}_status" => 'locked', "{$slot}_locked_by" => Auth::id(), "{$slot}_locked_at" => $now];
        if (!$shot->{"{$slot}_approved_at"}) {
            $update["{$slot}_approved_by"] = Auth::id();
            $update["{$slot}_approved_at"] = $now;
        }
        $shot->update($update);

        return response()->json(['success' => true]);
    }

    private function doUnlock(AudiobookVideoShot $shot, string $slot): \Illuminate\Http\JsonResponse
    {
        $shot->update([
            "{$slot}_status" => 'approved',
            "{$slot}_locked_by" => null,
            "{$slot}_locked_at" => null,
        ]);

        return response()->json(['success' => true]);
    }

    private function assertMutable(AudiobookVideoShot $shot, string $slot): void
    {
        if ($shot->{"{$slot}_status"} === 'locked') {
            abort(409, 'Slot này đã được khóa (locked) — mở khóa trước khi thay đổi.');
        }
    }

    /** The immediately preceding shot in BOOK order (scene_index then shot_index), across scene boundaries — null only for the very first shot of the whole book. */
    private function resolvePreviousShot(AudiobookVideoShot $shot): ?AudiobookVideoShot
    {
        $prevInScene = AudiobookVideoShot::where('video_scene_id', $shot->video_scene_id)
            ->where('shot_index', '<', $shot->shot_index)
            ->orderByDesc('shot_index')
            ->first();
        if ($prevInScene) {
            return $prevInScene;
        }

        $prevScene = AudiobookVideoScene::where('audio_book_id', $shot->scene->audio_book_id)
            ->where('scene_index', '<', $shot->scene->scene_index)
            ->orderByDesc('scene_index')
            ->first();
        if (!$prevScene) {
            return null;
        }

        return AudiobookVideoShot::where('video_scene_id', $prevScene->id)->orderByDesc('shot_index')->first();
    }

    /** Short text note of the last shot's chosen motion preset, purely for AI variety — never re-sent as an image. */
    private function buildPreviousMotionContext(AudiobookVideoShot $shot): string
    {
        $prev = $this->resolvePreviousShot($shot);
        if (!$prev || !$prev->motion_preset) {
            return '';
        }

        return "shot trước đã chọn '{$prev->motion_preset}'";
    }

    /**
     * Whatever image bytes are usable for vision analysis of THIS shot: its own still image if
     * it has one, or a representative frame extracted from its video (avatar/animated/stock)
     * otherwise — a transition can still be meaningfully analyzed even for a video-sourced shot.
     *
     * @return array{0:string,1:string} [bytes, mimeType]
     */
    private function resolveAnalysisImage(AudiobookVideoShot $shot): array
    {
        if (!$shot->is_avatar_segment && !$shot->isResolvedAssetVideo() && $shot->resolved_asset_path) {
            $bytes = Storage::disk('public')->get($shot->resolved_asset_path);
            if ($bytes !== null) {
                return [$bytes, $this->mimeTypeFor($shot->resolved_asset_path)];
            }
        }

        $videoRelativePath = $shot->is_avatar_segment ? $shot->avatar_video_path : $shot->resolved_asset_path;
        $videoAbsolutePath = storage_path('app/public/' . $videoRelativePath);
        if (!file_exists($videoAbsolutePath)) {
            throw new \RuntimeException('Không tìm thấy nguồn hình ảnh/video để phân tích cho shot này.');
        }

        $tempJpg = sys_get_temp_dir() . '/' . Str::uuid() . '.jpg';
        $ffmpegPath = (string) config('services.ffmpeg.path', 'ffmpeg');
        $command = sprintf(
            '%s -y -ss 00:00:00.5 -i %s -vframes 1 -q:v 2 %s 2>&1',
            escapeshellarg($ffmpegPath),
            escapeshellarg($videoAbsolutePath),
            escapeshellarg($tempJpg)
        );
        exec($command, $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($tempJpg)) {
            throw new \RuntimeException('Không trích được khung hình để phân tích chuyển cảnh.');
        }

        $bytes = file_get_contents($tempJpg);
        @unlink($tempJpg);

        return [$bytes, 'image/jpeg'];
    }

    private function mimeTypeFor(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }

    private function assertShotBelongs(AudioBook $audioBook, AudiobookVideoScene $scene, AudiobookVideoShot $shot): void
    {
        if ($scene->audio_book_id !== $audioBook->id || $shot->video_scene_id !== $scene->id) {
            abort(404);
        }
    }

    private function slotPayload(AudiobookVideoShot $shot, string $slot): array
    {
        $path = $shot->{"{$slot}_asset_path"};

        return [
            'id' => $shot->id,
            'slot' => $slot,
            'preset' => $shot->{"{$slot}_preset"},
            'status' => $shot->{"{$slot}_status"},
            'reason' => $shot->{"{$slot}_reason"},
            'preview_url' => $path ? asset('storage/' . $path) : null,
        ];
    }
}
