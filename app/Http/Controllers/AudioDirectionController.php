<?php

namespace App\Http\Controllers;

use App\Jobs\BulkGenerateSceneAmbienceJob;
use App\Jobs\BulkGenerateSceneMusicJob;
use App\Jobs\BulkGenerateShotAmbienceOverrideJob;
use App\Jobs\BulkGenerateShotMusicOverrideJob;
use App\Jobs\BulkGenerateShotSfxJob;
use App\Models\AudioBook;
use App\Models\AudiobookAudioAsset;
use App\Models\AudiobookVideoPipeline;
use App\Models\AudiobookVideoScene;
use App\Models\AudiobookVideoShot;
use App\Services\AssetLibrary\R2StorageService;
use App\Services\AudioAssetLibraryService;
use App\Services\VideoSceneAnalysisService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Approval-first audio direction endpoints for the video pipeline studio: candidate review,
 * select/generate/regenerate/reject/approve/lock/unlock for each of the 5 audio slots (scene
 * ambience/music baseline; shot sfx + ambience/music override). Deliberately generic over
 * "target" (AudiobookVideoScene or AudiobookVideoShot) since both models share the exact same
 * {slot}_asset_id/{slot}_status/{slot}_selected_by/... column convention per slot — see
 * AudiobookVideoShot::resolvedAmbience()/resolvedMusic() for how a shot falls back to its
 * scene's baseline when it has no override of its own.
 */
class AudioDirectionController extends Controller
{
    private const SCENE_SLOTS = ['ambience', 'music'];
    private const SHOT_SLOTS = ['sfx', 'ambience', 'music'];

    public function __construct(
        private readonly AudioAssetLibraryService $audioLibrary,
        private readonly R2StorageService $r2
    ) {}

    /**
     * "✨ Tạo tất cả" next to the "🆕 Cần tạo" audio filter — dispatches the 5 bulk jobs
     * (scene ambience baseline, scene music baseline, shot sfx, shot ambience override, shot
     * music override) that together cover every "needs_creation" item, mirroring how
     * BulkGenerateShotImagesJob/BulkGenerateNarrationTtsJob are triggered. Shot-level overrides
     * were deliberately excluded at first (they're the AI Director explicitly flagging a
     * genuine divergence from the scene, originally meant to always get a closer look before
     * generating) — included now since the user confirmed they'd rather have "Tạo tất cả"
     * cover them too and review afterward via the approval queue, same as everything else. Each
     * job independently no-ops if already running or has nothing pending — safe to call even if
     * some categories have zero work left.
     */
    public function bulkGenerateAudio(AudioBook $audioBook)
    {
        $pipeline = AudiobookVideoPipeline::firstOrCreate(['audio_book_id' => $audioBook->id]);

        $alreadyRunning = collect([
            $pipeline->bulk_scene_ambience_status,
            $pipeline->bulk_scene_music_status,
            $pipeline->bulk_shot_sfx_status,
            $pipeline->bulk_shot_ambience_override_status,
            $pipeline->bulk_shot_music_override_status,
        ])->contains(fn ($s) => $s && ($s['status'] ?? null) === 'running');

        if ($alreadyRunning) {
            return response()->json(['success' => true, 'already_running' => true]);
        }

        BulkGenerateSceneAmbienceJob::dispatch($audioBook->id);
        BulkGenerateSceneMusicJob::dispatch($audioBook->id);
        BulkGenerateShotSfxJob::dispatch($audioBook->id);
        BulkGenerateShotAmbienceOverrideJob::dispatch($audioBook->id);
        BulkGenerateShotMusicOverrideJob::dispatch($audioBook->id);

        return response()->json(['success' => true, 'already_running' => false]);
    }

    // ---- Scene-scoped endpoints (ambience/music baseline) ----

    public function sceneCandidates(Request $request, AudioBook $audioBook, AudiobookVideoScene $scene, string $slot)
    {
        $this->assertSceneBelongs($audioBook, $scene);
        $this->assertSlot($slot, self::SCENE_SLOTS);

        return response()->json(['success' => true, 'candidates' => $this->candidatesFor($scene, $slot)]);
    }

    public function sceneSelect(Request $request, AudioBook $audioBook, AudiobookVideoScene $scene, string $slot)
    {
        $this->assertSceneBelongs($audioBook, $scene);
        $this->assertSlot($slot, self::SCENE_SLOTS);

        return $this->doSelect($scene, $slot, $request);
    }

    public function sceneGenerate(Request $request, AudioBook $audioBook, AudiobookVideoScene $scene, string $slot)
    {
        $this->assertSceneBelongs($audioBook, $scene);
        $this->assertSlot($slot, self::SCENE_SLOTS);

        return $this->doGenerate($scene, $slot, $request, $audioBook);
    }

    public function sceneReject(Request $request, AudioBook $audioBook, AudiobookVideoScene $scene, string $slot)
    {
        $this->assertSceneBelongs($audioBook, $scene);
        $this->assertSlot($slot, self::SCENE_SLOTS);

        return $this->doReject($scene, $slot);
    }

    public function sceneApprove(Request $request, AudioBook $audioBook, AudiobookVideoScene $scene, string $slot)
    {
        $this->assertSceneBelongs($audioBook, $scene);
        $this->assertSlot($slot, self::SCENE_SLOTS);

        return $this->doApprove($scene, $slot);
    }

    public function sceneLock(Request $request, AudioBook $audioBook, AudiobookVideoScene $scene, string $slot)
    {
        $this->assertSceneBelongs($audioBook, $scene);
        $this->assertSlot($slot, self::SCENE_SLOTS);

        return $this->doLock($scene, $slot);
    }

    public function sceneUnlock(Request $request, AudioBook $audioBook, AudiobookVideoScene $scene, string $slot)
    {
        $this->assertSceneBelongs($audioBook, $scene);
        $this->assertSlot($slot, self::SCENE_SLOTS);

        return $this->doUnlock($scene, $slot);
    }

    // ---- Shot-scoped endpoints (sfx always; ambience/music = override of the scene baseline) ----

    public function shotCandidates(Request $request, AudioBook $audioBook, AudiobookVideoScene $scene, AudiobookVideoShot $shot, string $slot)
    {
        $this->assertShotBelongs($audioBook, $scene, $shot);
        $this->assertSlot($slot, self::SHOT_SLOTS);

        return response()->json(['success' => true, 'candidates' => $this->candidatesFor($shot, $slot)]);
    }

    public function shotSelect(Request $request, AudioBook $audioBook, AudiobookVideoScene $scene, AudiobookVideoShot $shot, string $slot)
    {
        $this->assertShotBelongs($audioBook, $scene, $shot);
        $this->assertSlot($slot, self::SHOT_SLOTS);

        return $this->doSelect($shot, $slot, $request);
    }

    public function shotGenerate(Request $request, AudioBook $audioBook, AudiobookVideoScene $scene, AudiobookVideoShot $shot, string $slot)
    {
        $this->assertShotBelongs($audioBook, $scene, $shot);
        $this->assertSlot($slot, self::SHOT_SLOTS);

        return $this->doGenerate($shot, $slot, $request, $audioBook);
    }

    public function shotReject(Request $request, AudioBook $audioBook, AudiobookVideoScene $scene, AudiobookVideoShot $shot, string $slot)
    {
        $this->assertShotBelongs($audioBook, $scene, $shot);
        $this->assertSlot($slot, self::SHOT_SLOTS);

        return $this->doReject($shot, $slot);
    }

    public function shotApprove(Request $request, AudioBook $audioBook, AudiobookVideoScene $scene, AudiobookVideoShot $shot, string $slot)
    {
        $this->assertShotBelongs($audioBook, $scene, $shot);
        $this->assertSlot($slot, self::SHOT_SLOTS);

        return $this->doApprove($shot, $slot);
    }

    public function shotLock(Request $request, AudioBook $audioBook, AudiobookVideoScene $scene, AudiobookVideoShot $shot, string $slot)
    {
        $this->assertShotBelongs($audioBook, $scene, $shot);
        $this->assertSlot($slot, self::SHOT_SLOTS);

        return $this->doLock($shot, $slot);
    }

    public function shotUnlock(Request $request, AudioBook $audioBook, AudiobookVideoScene $scene, AudiobookVideoShot $shot, string $slot)
    {
        $this->assertShotBelongs($audioBook, $scene, $shot);
        $this->assertSlot($slot, self::SHOT_SLOTS);

        return $this->doUnlock($shot, $slot);
    }

    // ---- Storyblocks manual hand-off: cache "which audio slot is the user browsing for" ----

    /**
     * Cache key shared with Api\VideoPipelineExtensionController::activeAudioTarget() —
     * mirrors AudioBookVideoPipelineController::activeTargetCacheKey() but kept SEPARATE
     * (different key, different payload shape: target_type/scene_id/shot_id/slot) since an
     * audio target can be a SCENE (ambience/music baseline) as well as a shot, unlike video's
     * shot-only target.
     */
    public static function activeAudioTargetCacheKey(int $userId): string
    {
        return "vp_active_audio_target_user_{$userId}";
    }

    public function setActiveAudioTargetForScene(Request $request, AudioBook $audioBook, AudiobookVideoScene $scene, string $slot)
    {
        $this->assertSceneBelongs($audioBook, $scene);
        $this->assertSlot($slot, self::SCENE_SLOTS);

        Cache::put(self::activeAudioTargetCacheKey(Auth::id()), [
            'audio_book_id' => $audioBook->id,
            'audio_book_title' => $audioBook->title,
            'target_type' => 'scene',
            'scene_id' => $scene->id,
            'shot_id' => null,
            'slot' => $slot,
            'prompt' => (string) $scene->{"{$slot}_prompt"},
            // See AudioBookVideoPipelineController::setActiveTarget()'s matching comment —
            // lets the extension auto-pick the Audio tab instead of defaulting to Video.
            'set_at' => now()->toIso8601String(),
        ], now()->addMinutes(15));

        return response()->json(['success' => true]);
    }

    public function setActiveAudioTargetForShot(Request $request, AudioBook $audioBook, AudiobookVideoScene $scene, AudiobookVideoShot $shot, string $slot)
    {
        $this->assertShotBelongs($audioBook, $scene, $shot);
        $this->assertSlot($slot, self::SHOT_SLOTS);

        Cache::put(self::activeAudioTargetCacheKey(Auth::id()), [
            'audio_book_id' => $audioBook->id,
            'audio_book_title' => $audioBook->title,
            'target_type' => 'shot',
            'scene_id' => $scene->id,
            'shot_id' => $shot->id,
            'slot' => $slot,
            'prompt' => (string) $shot->{"{$slot}_prompt"},
            'set_at' => now()->toIso8601String(),
        ], now()->addMinutes(15));

        return response()->json(['success' => true]);
    }

    // ---- Shared implementation (identical column convention on both models) ----

    /**
     * @return array<int,array<string,mixed>>
     */
    private function candidatesFor(Model $target, string $slot): array
    {
        $prompt = trim((string) $target->{"{$slot}_prompt"});
        if ($prompt === '') {
            return [];
        }

        $keywords = $target->{"{$slot}_keywords"} ?? [];
        $duration = $target instanceof AudiobookVideoShot ? $target->estimated_duration_seconds : null;
        $contextHint = (string) ($this->resolveBook($target)?->videoPipeline?->context_hint ?? '');

        $results = $this->audioLibrary->searchCandidates($slot, $prompt, $keywords, $duration, $contextHint);

        return array_map(fn($r) => $this->assetPayload(
            $r['asset'],
            $r['score_final'] ?? null,
            $r['score_content'] ?? null,
            $r['score_mood'] ?? null,
            $r['match_type'] ?? null
        ), $results);
    }

    private function doSelect(Model $target, string $slot, Request $request)
    {
        $this->assertMutable($target, $slot);

        $assetId = (int) $request->input('asset_id');
        $asset = AudiobookAudioAsset::where('audio_category', $slot)->find($assetId);
        if (!$asset) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy audio asset.'], 404);
        }

        $this->audioLibrary->recordReuse($asset);
        $this->attachSelection($target, $slot, $asset);

        return response()->json(['success' => true, 'asset' => $this->assetPayload($asset)]);
    }

    private function doGenerate(Model $target, string $slot, Request $request, AudioBook $audioBook)
    {
        $this->assertMutable($target, $slot);

        $prompt = trim((string) $request->input('prompt', $target->{"{$slot}_prompt"}));
        if ($prompt === '') {
            return response()->json(['success' => false, 'message' => 'Chưa có audio_prompt cho slot này — chạy phân tích AI Director trước.'], 422);
        }

        $keywords = $target->{"{$slot}_keywords"} ?? [];
        $duration = $request->filled('duration_seconds')
            ? (float) $request->input('duration_seconds')
            : ($target instanceof AudiobookVideoShot ? $target->estimated_duration_seconds : null);
        $contextHint = (string) ($this->resolveBook($target)?->videoPipeline?->context_hint ?? '');
        $force = $request->boolean('force');

        if (!$force) {
            $match = $this->audioLibrary->findMatch($slot, $prompt, $keywords, $duration, $contextHint);
            if ($match) {
                $this->audioLibrary->recordReuse($match['asset']);
                $this->attachSelection($target, $slot, $match['asset']);

                return response()->json([
                    'success' => true,
                    'reused' => true,
                    'match_type' => $match['match_type'],
                    'score_final' => $match['score_final'],
                    'asset' => $this->assetPayload($match['asset']),
                ]);
            }
        }

        $asset = $this->audioLibrary->generateAndArchive(
            $slot,
            $prompt,
            $keywords,
            $duration,
            $audioBook->id,
            VideoSceneAnalysisService::AUDIO_PROMPT_VERSION
        );
        $this->attachSelection($target, $slot, $asset);

        return response()->json(['success' => true, 'reused' => false, 'asset' => $this->assetPayload($asset)]);
    }

    private function doReject(Model $target, string $slot)
    {
        $this->assertMutable($target, $slot);

        $update = [
            "{$slot}_asset_id" => null,
            "{$slot}_status" => 'rejected',
            "{$slot}_selected_by" => null,
            "{$slot}_approved_by" => null,
            "{$slot}_approved_at" => null,
        ];

        // A shot-level ambience/music slot only exists because the user (or AI Director)
        // overrode the scene's baseline — rejecting the pick with nothing to replace it means
        // reverting to inheriting the scene's baseline again, not leaving a dangling override.
        if ($target instanceof AudiobookVideoShot && in_array($slot, ['ambience', 'music'], true)) {
            $update["{$slot}_override"] = false;
        }

        $target->update($update);

        return response()->json(['success' => true]);
    }

    private function doApprove(Model $target, string $slot)
    {
        if (!$target->{"{$slot}_asset_id"}) {
            return response()->json(['success' => false, 'message' => 'Chưa chọn/tạo audio cho slot này để duyệt.'], 422);
        }
        $this->assertMutable($target, $slot);

        $target->update([
            "{$slot}_status" => 'approved',
            "{$slot}_approved_by" => Auth::id(),
            "{$slot}_approved_at" => now(),
        ]);

        return response()->json(['success' => true]);
    }

    private function doLock(Model $target, string $slot)
    {
        if (!$target->{"{$slot}_asset_id"}) {
            return response()->json(['success' => false, 'message' => 'Chưa chọn/tạo audio cho slot này để khóa.'], 422);
        }
        $this->assertMutable($target, $slot);

        $now = now();
        $update = ["{$slot}_status" => 'locked', "{$slot}_locked_by" => Auth::id(), "{$slot}_locked_at" => $now];
        // Locking implies approval — a slot can be locked directly from "generated" without a
        // separate approve click first, but the approval trail must still be filled in.
        if (!$target->{"{$slot}_approved_at"}) {
            $update["{$slot}_approved_by"] = Auth::id();
            $update["{$slot}_approved_at"] = $now;
        }
        $target->update($update);

        return response()->json(['success' => true]);
    }

    private function doUnlock(Model $target, string $slot)
    {
        $target->update([
            "{$slot}_status" => 'approved',
            "{$slot}_locked_by" => null,
            "{$slot}_locked_at" => null,
        ]);

        return response()->json(['success' => true]);
    }

    private function attachSelection(Model $target, string $slot, AudiobookAudioAsset $asset): void
    {
        $update = [
            "{$slot}_asset_id" => $asset->id,
            "{$slot}_status" => 'generated',
            "{$slot}_selected_by" => Auth::id(),
            "{$slot}_approved_by" => null,
            "{$slot}_approved_at" => null,
        ];

        if ($target instanceof AudiobookVideoShot && in_array($slot, ['ambience', 'music'], true)) {
            $update["{$slot}_override"] = true;
        }

        $target->update($update);
    }

    /**
     * Defense-in-depth: even before any bulk job exists, the API boundary itself refuses to
     * mutate a locked slot — "Asset đã locked không được bulk job hoặc stale regeneration tự
     * thay thế" applies to every write path, not just future bulk jobs.
     */
    private function assertMutable(Model $target, string $slot): void
    {
        if ($target->{"{$slot}_status"} === 'locked') {
            abort(409, 'Slot này đã được khóa (locked) — mở khóa trước khi thay đổi.');
        }
    }

    private function assertSlot(string $slot, array $allowed): void
    {
        if (!in_array($slot, $allowed, true)) {
            abort(404, "Slot âm thanh không hợp lệ: {$slot}");
        }
    }

    private function assertSceneBelongs(AudioBook $audioBook, AudiobookVideoScene $scene): void
    {
        if ($scene->audio_book_id !== $audioBook->id) {
            abort(404);
        }
    }

    private function assertShotBelongs(AudioBook $audioBook, AudiobookVideoScene $scene, AudiobookVideoShot $shot): void
    {
        if ($scene->audio_book_id !== $audioBook->id || $shot->video_scene_id !== $scene->id) {
            abort(404);
        }
    }

    private function resolveBook(Model $target): ?AudioBook
    {
        return $target instanceof AudiobookVideoShot ? $target->scene?->audioBook : $target->audioBook;
    }

    private function assetPayload(AudiobookAudioAsset $asset, ?float $score = null, ?float $scoreContent = null, ?float $scoreMood = null, ?string $matchType = null): array
    {
        return [
            'id' => $asset->id,
            'audio_category' => $asset->audio_category,
            'provider' => $asset->provider,
            'origin_source' => $asset->origin_source,
            'prompt' => $asset->prompt,
            'keywords' => $asset->keywords,
            'duration_seconds' => $asset->duration_seconds,
            'requested_duration_seconds' => $asset->requested_duration_seconds,
            'is_loopable' => $asset->is_loopable,
            'license_label' => $asset->license_label,
            'attribution' => $asset->attribution,
            'usage_count' => $asset->usage_count,
            'credits_used' => $asset->credits_used,
            'cost_usd' => $asset->cost_usd,
            'generation_latency_ms' => $asset->generation_latency_ms,
            'provider_request_id' => $asset->provider_request_id,
            'preview_url' => $this->r2->temporaryUrl($asset->r2_path, 60),
            'score_final' => $score,
            'score_content' => $scoreContent,
            'score_mood' => $scoreMood,
            'match_type' => $matchType,
        ];
    }
}
