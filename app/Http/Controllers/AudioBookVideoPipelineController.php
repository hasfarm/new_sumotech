<?php

namespace App\Http\Controllers;

use App\Jobs\BulkGenerateAvatarTtsJob;
use App\Jobs\BulkGenerateNarrationTtsJob;
use App\Jobs\BulkGenerateShotImagesJob;
use App\Jobs\EnrichVideoShotsJob;
use App\Jobs\GenerateAvatarSegmentJob;
use App\Jobs\ResolveSceneAssetJob;
use App\Jobs\SplitVideoScenesJob;
use App\Models\AudioBook;
use App\Models\AudiobookVideoPipeline;
use App\Models\AudiobookVideoScene;
use App\Models\AudiobookVideoShot;
use App\Models\AudiobookVideoShotCandidate;
use App\Services\AssetLibrary\AssetLibraryService;
use App\Services\AssetLibrary\R2StorageService;
use App\Services\AvatarSegmentService;
use App\Services\ClipScoringService;
use App\Services\SceneAssetResolverService;
use App\Services\StockFootageAggregatorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class AudioBookVideoPipelineController extends Controller
{
    public function __construct(
        private readonly StockFootageAggregatorService $aggregator,
        private readonly ClipScoringService $scoringService,
        private readonly SceneAssetResolverService $resolverService,
        private readonly AssetLibraryService $assetLibraryService,
        private readonly R2StorageService $r2Storage,
        private readonly AvatarSegmentService $avatarService
    ) {}

    /**
     * The video pipeline studio page.
     */
    public function page(AudioBook $audioBook)
    {
        return view('audiobooks.video-pipeline', compact('audioBook'));
    }

    /**
     * Current pipeline state (status + all scenes, each with its shots + candidates) for
     * the UI to poll.
     */
    public function status(AudioBook $audioBook)
    {
        return response()->json([
            'success' => true,
            'pipeline' => $audioBook->videoPipeline,
            'scenes' => $audioBook->videoScenes()
                ->with([
                    'shots.candidates',
                    'shots.sfxAsset',
                    'shots.ambienceAsset',
                    'shots.musicAsset',
                    'ambienceAsset',
                    'musicAsset',
                ])
                ->get(),
            'speaker_avatar_url' => optional($audioBook->speaker)->avatar_url,
        ]);
    }

    /**
     * Step A: kick off scene splitting + shot classification + avatar placement in the background.
     */
    public function start(Request $request, AudioBook $audioBook)
    {
        $summary = $audioBook->summary;
        if (!$summary || empty($summary->clusters)) {
            return response()->json([
                'success' => false,
                'message' => 'Sách chưa có kịch bản tóm tắt (Bước 3) để phân tích. Hãy tạo tóm tắt sách trước.',
            ], 422);
        }

        $versionId = $request->input('version_id');
        if ($versionId !== null) {
            $versionExists = collect($summary->versions ?? [])->contains('id', $versionId);
            if (!$versionExists) {
                return response()->json(['success' => false, 'message' => 'Phiên bản tóm tắt không tồn tại.'], 422);
            }
        }

        $pipeline = AudiobookVideoPipeline::updateOrCreate(
            ['audio_book_id' => $audioBook->id],
            [
                'status' => 'queued',
                'source_version_id' => $versionId,
                'total_batches' => null,
                'processed_batches' => 0,
                'error_message' => null,
            ]
        );

        SplitVideoScenesJob::dispatch($audioBook->id, $versionId);

        return response()->json(['success' => true, 'pipeline' => $pipeline]);
    }

    /**
     * Re-run only the shot-enrichment chunks that failed on the last pass — never re-does
     * the whole book. Only meaningful once the pipeline has reached shot_enrichment (or
     * finished with some chunks failed).
     */
    public function retryFailedChunks(AudioBook $audioBook)
    {
        $pipeline = $audioBook->videoPipeline;
        if (!$pipeline || empty($pipeline->shot_chunks)) {
            return response()->json(['success' => false, 'message' => 'Chưa có dữ liệu chunk để thử lại.'], 422);
        }

        $failedIndices = collect($pipeline->shot_chunks)
            ->where('status', 'failed')
            ->pluck('index')
            ->values()
            ->all();

        if (empty($failedIndices)) {
            return response()->json(['success' => false, 'message' => 'Không có chunk nào bị lỗi để thử lại.'], 422);
        }

        $pipeline->update(['status' => 'analyzing', 'current_stage' => 'shot_enrichment', 'error_message' => null]);

        EnrichVideoShotsJob::dispatch($audioBook->id, $failedIndices);

        return response()->json(['success' => true, 'retrying_chunks' => count($failedIndices)]);
    }

    /**
     * Let the user pick the visual style used for every future Flux AI-generated image in
     * this book — kept as one setting per pipeline (not per shot) so the whole book stays
     * visually consistent. Only affects images generated AFTER this is set; existing images
     * are not retroactively regenerated.
     */
    public function updateImageStyle(Request $request, AudioBook $audioBook)
    {
        $style = (string) $request->input('image_style');
        if (!in_array($style, ['illustration', 'cinematic_realistic'], true)) {
            return response()->json(['success' => false, 'message' => 'Phong cách ảnh không hợp lệ.'], 422);
        }

        $pipeline = AudiobookVideoPipeline::updateOrCreate(
            ['audio_book_id' => $audioBook->id],
            ['image_style' => $style]
        );

        return response()->json(['success' => true, 'pipeline' => $pipeline]);
    }

    /**
     * Let the user pick which API generates AI images (Flux or Grok) and which model of
     * that API to use — same per-pipeline scope as updateImageStyle(). Model is free text
     * (not a hardcoded dropdown) since provider model slugs change over time; validated only
     * for length/charset here, the actual API call is what proves it valid.
     */
    public function updateImageProvider(Request $request, AudioBook $audioBook)
    {
        $provider = (string) $request->input('image_api_provider');
        if (!in_array($provider, ['flux', 'grok'], true)) {
            return response()->json(['success' => false, 'message' => 'Nhà cung cấp API ảnh không hợp lệ.'], 422);
        }

        $model = trim((string) $request->input('image_api_model', ''));
        if ($model !== '' && !preg_match('/^[a-zA-Z0-9._\-\/]{1,64}$/', $model)) {
            return response()->json(['success' => false, 'message' => 'Tên model không hợp lệ.'], 422);
        }

        $pipeline = AudiobookVideoPipeline::updateOrCreate(
            ['audio_book_id' => $audioBook->id],
            ['image_api_provider' => $provider, 'image_api_model' => $model !== '' ? $model : null]
        );

        return response()->json(['success' => true, 'pipeline' => $pipeline]);
    }

    /**
     * Main narration voice for the whole work — same per-pipeline scope as image_style.
     * Deliberately separate from the avatar/lipsync voice below (updateAvatarTtsSettings())
     * so pairing a specific voice with the speaker's avatar image never forces that same
     * voice onto anything else.
     */
    public function updateTtsSettings(Request $request, AudioBook $audioBook)
    {
        $data = $this->validateTtsInput($request);
        if ($data instanceof \Illuminate\Http\JsonResponse) {
            return $data;
        }

        $pipeline = AudiobookVideoPipeline::updateOrCreate(
            ['audio_book_id' => $audioBook->id],
            [
                'tts_provider' => $data['provider'],
                'tts_voice_gender' => $data['gender'],
                'tts_voice_name' => $data['voice_name'],
            ]
        );

        return response()->json(['success' => true, 'pipeline' => $pipeline]);
    }

    /**
     * Avatar/lipsync voice — consumed by AvatarSegmentService::generateTts() to TTS an
     * avatar shot's narration before handing it to HeyGen. Falls back to the main pipeline
     * voice, then to the book's own regular tts_* settings, if left unset here.
     */
    public function updateAvatarTtsSettings(Request $request, AudioBook $audioBook)
    {
        $data = $this->validateTtsInput($request);
        if ($data instanceof \Illuminate\Http\JsonResponse) {
            return $data;
        }

        $pipeline = AudiobookVideoPipeline::updateOrCreate(
            ['audio_book_id' => $audioBook->id],
            [
                'avatar_tts_provider' => $data['provider'],
                'avatar_tts_voice_gender' => $data['gender'],
                'avatar_tts_voice_name' => $data['voice_name'],
            ]
        );

        return response()->json(['success' => true, 'pipeline' => $pipeline]);
    }

    /**
     * @return array{provider:?string,gender:?string,voice_name:?string}|\Illuminate\Http\JsonResponse
     */
    private function validateTtsInput(Request $request)
    {
        $provider = (string) $request->input('provider', '');
        if ($provider !== '' && !in_array($provider, ['openai', 'gemini', 'microsoft', 'vbee'], true)) {
            return response()->json(['success' => false, 'message' => 'Nhà cung cấp TTS không hợp lệ.'], 422);
        }

        $gender = (string) $request->input('voice_gender', 'female');
        if (!in_array($gender, ['male', 'female'], true)) {
            return response()->json(['success' => false, 'message' => 'Giới tính giọng đọc không hợp lệ.'], 422);
        }

        $voiceName = trim((string) $request->input('voice_name', ''));

        return [
            'provider' => $provider !== '' ? $provider : null,
            'gender' => $gender,
            'voice_name' => $voiceName !== '' ? $voiceName : null,
        ];
    }

    /**
     * Cache key shared with Api\VideoPipelineExtensionController::activeTarget() — records
     * "which shot was the user just browsing for" when they click a keyword to open a
     * Storyblocks search tab, so the Chrome extension popup can prefill the right target
     * without the user having to hunt for it manually. Short TTL since it's just a UX
     * convenience, not a durable link.
     */
    public static function activeTargetCacheKey(int $userId): string
    {
        return "vp_active_target_user_{$userId}";
    }

    /**
     * Called right before opening a Storyblocks search tab for one shot's keyword.
     */
    public function setActiveTarget(Request $request, AudioBook $audioBook, AudiobookVideoScene $scene, AudiobookVideoShot $shot)
    {
        if ($scene->audio_book_id !== $audioBook->id || $shot->video_scene_id !== $scene->id) {
            abort(404);
        }

        Cache::put(self::activeTargetCacheKey(Auth::id()), [
            'audio_book_id' => $audioBook->id,
            'audio_book_title' => $audioBook->title,
            'scene_id' => $scene->id,
            'shot_id' => $shot->id,
            'shot_text' => $shot->sentence_text,
            'keyword' => (string) $request->input('keyword', ''),
            // Lets the extension compare this against active-audio-target's own set_at and
            // auto-pick whichever tab (Video/Audio) was actually just requested, instead of
            // always defaulting to Video and looking like it "doesn't know" the audio target.
            'set_at' => now()->toIso8601String(),
        ], now()->addMinutes(15));

        return response()->json(['success' => true]);
    }

    /**
     * One-time setup step: issue a Sanctum personal access token for the Chrome extension to
     * authenticate with (Api\VideoPipelineExtensionController routes). Shown once as plaintext
     * — Sanctum only stores the hash, so it can't be retrieved again after this response.
     */
    public function issueExtensionToken(Request $request)
    {
        $user = $request->user();
        // Revoke any previous extension token so only the latest one is valid — avoids
        // silently accumulating stale tokens every time the user re-generates one.
        $user->tokens()->where('name', 'storyblocks-extension')->delete();
        $token = $user->createToken('storyblocks-extension')->plainTextToken;

        return response()->json(['success' => true, 'token' => $token]);
    }

    /**
     * Step B: search all stock sources for one shot's keywords, score every candidate,
     * and auto-select the best one (still just metadata — no download yet).
     */
    public function searchShot(AudioBook $audioBook, AudiobookVideoScene $scene, AudiobookVideoShot $shot)
    {
        if ($scene->audio_book_id !== $audioBook->id || $shot->video_scene_id !== $scene->id) {
            abort(404);
        }

        if ($shot->is_avatar_segment) {
            return response()->json([
                'success' => false,
                'message' => 'Shot này là đoạn avatar, không cần tìm nguồn thư viện.',
            ], 422);
        }

        if (!$shot->is_real_world) {
            return response()->json([
                'success' => false,
                'message' => 'Shot này được đánh giá là KHÔNG có thật ngoài đời (hư cấu/kỳ ảo) — hãy dùng "Tạo bằng AI" trực tiếp thay vì tìm thư viện.',
            ], 422);
        }

        $shot->update(['status' => 'searching', 'error_message' => null]);

        // Library-first: check whether a suitable asset already exists in the reusable
        // library (semantic match, scored via AI Vision same as external candidates) before
        // spending API calls on Pexels/Pixabay/etc. On a hit, pull the file back down from
        // R2 into this shot's normal local folder so nothing downstream (modal preview, zip
        // download) needs to know it came from the library instead of a fresh search.
        $contextHint = (string) ($audioBook->videoPipeline->context_hint ?? '');
        $libraryMatch = $this->assetLibraryService->findMatch($shot, $contextHint);

        if ($libraryMatch) {
            $asset = $libraryMatch['asset'];
            $ext = strtolower(pathinfo($asset->r2_path, PATHINFO_EXTENSION)) ?: 'bin';
            $relativePath = "audiobooks/{$audioBook->id}/video-pipeline/scenes/{$scene->id}/shots/{$shot->id}/library_{$asset->id}.{$ext}";
            $absolutePath = storage_path('app/public/' . $relativePath);

            try {
                $this->r2Storage->download($asset->r2_path, $absolutePath);
            } catch (\Throwable $e) {
                $shot->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
                return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
            }

            $this->assetLibraryService->recordReuse($asset);

            $shot->update([
                'status' => 'ready',
                'resolved_source' => 'library',
                'resolved_asset_path' => $relativePath,
                'resolved_score' => $libraryMatch['score_final'],
                'resolved_library_asset_id' => $asset->id,
                'error_message' => null,
            ]);

            return response()->json(['success' => true, 'mode' => 'library', 'shot' => $shot->fresh()]);
        }

        $rawCandidates = $this->aggregator->searchScene($shot->keywords ?? [], $scene->scene_type);

        if (empty($rawCandidates)) {
            $shot->update(['status' => 'failed', 'error_message' => 'Không tìm thấy candidate nào từ các nguồn thư viện.']);
            return response()->json(['success' => false, 'message' => 'Không tìm thấy candidate nào từ các nguồn thư viện.'], 422);
        }

        $shot->candidates()->delete();

        $sceneBrief = [
            'title' => $scene->title,
            'scene_type' => $scene->scene_type,
            'keywords' => $shot->keywords ?? [],
            'shot_text' => $shot->sentence_text,
        ];
        $contextHint = (string) ($audioBook->videoPipeline->context_hint ?? '');

        $allScores = $this->scoringService->scoreCandidates($rawCandidates, $sceneBrief, $contextHint);

        // Avoid the same clip (e.g. the same Great Wall stock video) showing up as the
        // pick for many different shots — penalize candidates already resolved elsewhere
        // in this book instead of letting them win again just because they score well for
        // every "ancient Chinese X" keyword.
        $usedElsewhere = AudiobookVideoShotCandidate::where('is_selected', true)
            ->whereHas('shot', function ($q) use ($audioBook, $shot) {
                $q->where('id', '!=', $shot->id)
                    ->where('status', 'ready')
                    ->whereHas('scene', fn($q2) => $q2->where('audio_book_id', $audioBook->id));
            })
            ->get(['source', 'external_id'])
            ->map(fn($c) => $c->source . ':' . $c->external_id)
            ->flip();

        $bestCandidate = null;
        $bestScore = -1;
        $savedCandidates = [];

        foreach ($rawCandidates as $i => $candidate) {
            $scores = $allScores[$i];

            if (isset($usedElsewhere[$candidate['source'] . ':' . $candidate['external_id']])) {
                $scores['score_final'] = max(0, $scores['score_final'] - 40);
            }

            $row = AudiobookVideoShotCandidate::create(array_merge([
                'video_shot_id' => $shot->id,
                'source' => $candidate['source'],
                'external_id' => $candidate['external_id'],
                'thumbnail_url' => $candidate['thumbnail_url'] ?? null,
                'download_url' => $candidate['download_url'] ?? null,
                'media_type' => $candidate['media_type'] ?? 'video',
                'width' => $candidate['width'] ?? null,
                'height' => $candidate['height'] ?? null,
                'duration_seconds' => $candidate['duration_seconds'] ?? null,
                'license_label' => $candidate['license_label'] ?? null,
                'raw_metadata' => $candidate['raw_metadata'] ?? [],
                'is_selected' => false,
            ], $scores));

            $savedCandidates[] = $row;

            if ($scores['score_final'] > $bestScore) {
                $bestScore = $scores['score_final'];
                $bestCandidate = $row;
            }
        }

        if ($bestCandidate) {
            $bestCandidate->update(['is_selected' => true]);
        }

        $shot->update([
            'status' => 'scored',
            'resolved_score' => $bestScore >= 0 ? $bestScore : null,
            'resolved_source' => $bestCandidate ? $bestCandidate->source : null,
        ]);

        return response()->json([
            'success' => true,
            'shot_id' => $shot->id,
            'candidates' => $savedCandidates,
            'best_score' => $bestScore,
            'meets_threshold' => $bestScore >= ClipScoringService::SCORE_THRESHOLD,
        ]);
    }

    /**
     * Let the user override the auto-selected candidate before resolving the shot.
     */
    public function selectCandidate(AudioBook $audioBook, AudiobookVideoScene $scene, AudiobookVideoShot $shot, AudiobookVideoShotCandidate $candidate)
    {
        if ($scene->audio_book_id !== $audioBook->id || $shot->video_scene_id !== $scene->id || $candidate->video_shot_id !== $shot->id) {
            abort(404);
        }

        $shot->candidates()->update(['is_selected' => false]);
        $candidate->update(['is_selected' => true]);
        $shot->update(['resolved_score' => $candidate->score_final, 'resolved_source' => $candidate->source]);

        return response()->json(['success' => true]);
    }

    /**
     * Step C: resolve the asset for a shot — a fast stock download if the selected candidate
     * meets the score threshold, otherwise generate a still image with Flux (fast, sync) for
     * the user to preview. Converting that preview into a video is a separate explicit step
     * ({@see animateShot()}) — nothing is sent to the paid/slow video API by default.
     */
    public function resolveShot(Request $request, AudioBook $audioBook, AudiobookVideoScene $scene, AudiobookVideoShot $shot)
    {
        if ($scene->audio_book_id !== $audioBook->id || $shot->video_scene_id !== $scene->id) {
            abort(404);
        }

        if ($shot->is_avatar_segment) {
            return response()->json(['success' => false, 'message' => 'Shot avatar không dùng bước resolve này.'], 422);
        }

        // "Scene này có thật ngoài đời không?" — nếu AI đã đánh giá là hư cấu/kỳ ảo,
        // bỏ qua hẳn bước tìm thư viện và đi thẳng vào AI generate, không cần candidate nào.
        $forceAi = $request->boolean('force_ai');

        try {
            $result = $this->resolverService->resolveShotOrThrow($shot, $forceAi);
        } catch (\Throwable $e) {
            $status = $e instanceof \App\Exceptions\ContentModerationException ? 'content_blocked' : 'failed';
            $shot->update(['status' => $status, 'error_message' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        return response()->json(['success' => true, 'mode' => $result['mode'], 'shot' => $result['shot']]);
    }

    /**
     * Lets the user directly edit the AI image prompt for a shot — the general fix for a
     * shot whose image_request gets rejected by the AI provider's content-moderation policy
     * (status 'content_blocked'): retrying with the exact same text just fails the same way
     * again, so the fix is changing the wording, not re-clicking "retry". Also usable any time
     * a user simply wants to steer the illustration differently, not only for blocked shots.
     */
    public function updateImageRequest(Request $request, AudioBook $audioBook, AudiobookVideoScene $scene, AudiobookVideoShot $shot)
    {
        if ($scene->audio_book_id !== $audioBook->id || $shot->video_scene_id !== $scene->id) {
            abort(404);
        }

        $data = $request->validate(['image_request' => 'required|string|max:2000']);

        $shot->update([
            'image_request' => $data['image_request'],
            // Clears the "blocked" state so the shot goes back to looking like any other
            // not-yet-generated shot — the existing "Tạo ảnh khác"/"Resolve" buttons already
            // know how to retry from there, no separate "retry" endpoint needed.
            'status' => $shot->status === 'content_blocked' ? 'pending' : $shot->status,
            'error_message' => $shot->status === 'content_blocked' ? null : $shot->error_message,
        ]);

        return response()->json(['success' => true, 'shot' => $shot->fresh()]);
    }

    /**
     * "Tự động tạo bằng AI": dispatches a background job that force-generates AI images for
     * every non-avatar shot not yet 'ready'/'image_ready', instead of the old client-side
     * while-loop — that loop silently died the instant the tab was closed/refreshed/the
     * machine slept, with zero server-side memory that a run was ever in progress. The job
     * persists live progress on the pipeline (bulk_ai_generate_status) so the page can just
     * poll status() like every other pipeline stage, and survives page reloads.
     */
    public function bulkGenerateAi(AudioBook $audioBook)
    {
        $pipeline = AudiobookVideoPipeline::firstOrCreate(['audio_book_id' => $audioBook->id]);
        $current = $pipeline->bulk_ai_generate_status;

        if ($current && ($current['status'] ?? null) === 'running') {
            return response()->json(['success' => true, 'already_running' => true, 'pipeline' => $pipeline]);
        }

        BulkGenerateShotImagesJob::dispatch($audioBook->id);

        return response()->json(['success' => true, 'already_running' => false]);
    }

    /**
     * "Create All" next to the main narration voice picker — bulk-generates TTS for every
     * non-avatar shot missing narration_audio_path, using the pipeline's main voice.
     */
    public function bulkGenerateNarrationTts(AudioBook $audioBook)
    {
        $pipeline = AudiobookVideoPipeline::firstOrCreate(['audio_book_id' => $audioBook->id]);
        $current = $pipeline->bulk_narration_tts_status;

        if ($current && ($current['status'] ?? null) === 'running') {
            return response()->json(['success' => true, 'already_running' => true, 'pipeline' => $pipeline]);
        }

        BulkGenerateNarrationTtsJob::dispatch($audioBook->id);

        return response()->json(['success' => true, 'already_running' => false]);
    }

    /**
     * "Create All" next to the avatar voice picker — bulk-generates TTS for every avatar shot
     * missing avatar_audio_path, using the pipeline's avatar voice.
     */
    public function bulkGenerateAvatarTts(AudioBook $audioBook)
    {
        $pipeline = AudiobookVideoPipeline::firstOrCreate(['audio_book_id' => $audioBook->id]);
        $current = $pipeline->bulk_avatar_tts_status;

        if ($current && ($current['status'] ?? null) === 'running') {
            return response()->json(['success' => true, 'already_running' => true, 'pipeline' => $pipeline]);
        }

        BulkGenerateAvatarTtsJob::dispatch($audioBook->id);

        return response()->json(['success' => true, 'already_running' => false]);
    }

    /**
     * Optional follow-up: convert an already-approved AI still image into a short video via
     * Kling/Seedance. Only reachable once resolveShot() produced an 'image_ready' shot.
     */
    public function animateShot(AudioBook $audioBook, AudiobookVideoScene $scene, AudiobookVideoShot $shot)
    {
        if ($scene->audio_book_id !== $audioBook->id || $shot->video_scene_id !== $scene->id) {
            abort(404);
        }

        if ($shot->status !== 'image_ready' || !$shot->resolved_asset_path) {
            return response()->json(['success' => false, 'message' => 'Shot này chưa có ảnh AI để chuyển thành video.'], 422);
        }

        $shot->update(['status' => 'resolving', 'error_message' => null]);
        ResolveSceneAssetJob::dispatch($shot->id, $shot->resolved_asset_path);

        return response()->json(['success' => true, 'shot' => $shot->fresh()]);
    }

    /**
     * Duyệt một shot 'image_ready' (ảnh AI tĩnh) làm kết quả cuối cùng LUÔN, không chuyển
     * thành video — khác với animateShot() (chuyển ảnh -> video qua Kling/Seedance, tốn phí
     * thêm). Nhiều shot dùng ảnh tĩnh là đủ, không cần chuyển động; trước đây không có cách
     * nào thoát khỏi trạng thái "chờ duyệt" mà không tốn phí video.
     */
    public function approveShot(AudioBook $audioBook, AudiobookVideoScene $scene, AudiobookVideoShot $shot)
    {
        if ($scene->audio_book_id !== $audioBook->id || $shot->video_scene_id !== $scene->id) {
            abort(404);
        }

        if ($shot->status !== 'image_ready' || !$shot->resolved_asset_path) {
            return response()->json(['success' => false, 'message' => 'Shot này chưa có ảnh để duyệt.'], 422);
        }

        $shot->update(['status' => 'ready', 'error_message' => null]);

        return response()->json(['success' => true, 'shot' => $shot->fresh()]);
    }

    /**
     * Step D: generate a real HeyGen talking-avatar clip for an avatar-flagged shot.
     */
    public function generateAvatar(AudioBook $audioBook, AudiobookVideoScene $scene, AudiobookVideoShot $shot)
    {
        if ($scene->audio_book_id !== $audioBook->id || $shot->video_scene_id !== $scene->id) {
            abort(404);
        }

        if (!$shot->is_avatar_segment) {
            return response()->json(['success' => false, 'message' => 'Shot này không được đánh dấu là đoạn avatar.'], 422);
        }

        // Fail fast with a clear, actionable message instead of dispatching a job that would
        // only discover the same problem later (AvatarSegmentService::generateLipsync() throws
        // the same two checks, but by then the shot already flashed 'resolving' for nothing).
        if (!$this->avatarService->resolveAvatarImageUrl($shot)) {
            return response()->json(['success' => false, 'message' => 'Chưa có ảnh avatar — hãy chọn hoặc upload ảnh trước.'], 422);
        }
        if (!AvatarSegmentService::avatarAudioExists($shot->avatar_audio_path)) {
            return response()->json(['success' => false, 'message' => 'Chưa có audio TTS cho đoạn này — hãy tạo TTS trước.'], 422);
        }

        $shot->update(['status' => 'resolving', 'error_message' => null]);
        GenerateAvatarSegmentJob::dispatch($shot->id);

        return response()->json(['success' => true, 'shot' => $shot->fresh()]);
    }

    /**
     * TTS-only step for one avatar shot — separate from generateAvatar() (the HeyGen lipsync
     * call) so the user can generate/regenerate the narration audio on its own, review it,
     * before ever spending a HeyGen call. Runs synchronously (same convention as
     * SceneAssetResolverService::resolveShotOrThrow()'s AI image generation) since a single
     * short TTS call is fast enough not to need a queued job.
     */
    public function generateAvatarTts(AudioBook $audioBook, AudiobookVideoScene $scene, AudiobookVideoShot $shot)
    {
        if ($scene->audio_book_id !== $audioBook->id || $shot->video_scene_id !== $scene->id) {
            abort(404);
        }
        if (!$shot->is_avatar_segment) {
            return response()->json(['success' => false, 'message' => 'Shot này không được đánh dấu là đoạn avatar.'], 422);
        }

        try {
            $this->avatarService->generateTts($shot);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        return response()->json(['success' => true, 'shot' => $shot->fresh()]);
    }

    /**
     * The avatar "library" a shot can pick from: the book's assigned Speaker's primary avatar
     * + all of its additional_images (the same pool built for the YouTube channel MC/avatar
     * management UI — see ChannelSpeaker/YouTubeChannelController's speaker endpoints).
     */
    /**
     * The avatar library a shot can pick from: EVERY speaker/MC's avatar + additional_images
     * on the book's own YouTube channel — managed once per channel (see the "MC / Avatar
     * kênh" section on the channel edit page), never created separately per book. If the book
     * happens to have an explicit speaker_id assigned, that speaker's photos are still just
     * part of this same channel-wide pool, not a separate scope.
     */
    public function avatarLibrary(AudioBook $audioBook)
    {
        $channel = $audioBook->youtubeChannel;
        if (!$channel) {
            return response()->json(['success' => true, 'has_channel' => false, 'speakers' => [], 'images' => []]);
        }

        $speakers = $channel->speakers()->orderBy('name')->get();
        $images = [];
        foreach ($speakers as $speaker) {
            if ($speaker->avatar) {
                $images[] = [
                    'path' => $speaker->avatar,
                    'url' => $speaker->avatar_url,
                    'is_primary' => true,
                    'speaker_id' => $speaker->id,
                    'speaker_name' => $speaker->name,
                ];
            }
            foreach ($speaker->additional_images ?? [] as $imagePath) {
                $images[] = [
                    'path' => $imagePath,
                    'url' => asset('storage/' . $imagePath),
                    'is_primary' => false,
                    'speaker_id' => $speaker->id,
                    'speaker_name' => $speaker->name,
                ];
            }
        }

        return response()->json([
            'success' => true,
            'has_channel' => true,
            'speakers' => $speakers->map(fn($s) => ['id' => $s->id, 'name' => $s->name])->values(),
            'images' => $images,
        ]);
    }

    /**
     * Pick one of the channel's existing avatar library images for this specific shot —
     * validated against that same channel-wide library (every speaker's photos, not just the
     * book's own assigned speaker) so an arbitrary path can't be injected.
     * image_path = null reverts the shot to the channel's default avatar.
     */
    public function selectAvatarImage(Request $request, AudioBook $audioBook, AudiobookVideoScene $scene, AudiobookVideoShot $shot)
    {
        if ($scene->audio_book_id !== $audioBook->id || $shot->video_scene_id !== $scene->id) {
            abort(404);
        }
        if (!$shot->is_avatar_segment) {
            return response()->json(['success' => false, 'message' => 'Shot này không được đánh dấu là đoạn avatar.'], 422);
        }

        $path = $request->input('image_path');
        $validPaths = $this->channelAvatarImagePaths($audioBook);

        if ($path !== null && !in_array($path, $validPaths, true)) {
            return response()->json(['success' => false, 'message' => 'Ảnh không hợp lệ.'], 422);
        }

        $shot->update(['avatar_image_path' => $path]);

        return response()->json(['success' => true, 'shot' => $shot->fresh()]);
    }

    /**
     * Upload a brand-new avatar image straight from the video-pipeline shot UI: added to one
     * of the CHANNEL's speakers' reusable additional_images library (same storage convention
     * as YouTubeChannelController::storeSpeaker()) AND immediately selected for this shot —
     * this is still channel-wide, shared with every other book on the same channel, not
     * something created separately per book.
     */
    public function uploadAvatarImage(Request $request, AudioBook $audioBook, AudiobookVideoScene $scene, AudiobookVideoShot $shot)
    {
        if ($scene->audio_book_id !== $audioBook->id || $shot->video_scene_id !== $scene->id) {
            abort(404);
        }
        if (!$shot->is_avatar_segment) {
            return response()->json(['success' => false, 'message' => 'Shot này không được đánh dấu là đoạn avatar.'], 422);
        }

        $channel = $audioBook->youtubeChannel;
        if (!$channel) {
            return response()->json(['success' => false, 'message' => 'Sách chưa gán kênh YouTube — hãy gán kênh trước khi upload ảnh avatar.'], 422);
        }

        $speakers = $channel->speakers()->orderBy('name')->get();
        $speaker = $audioBook->speaker
            ?: ($speakers->count() === 1 ? $speakers->first() : $speakers->firstWhere('id', (int) $request->input('speaker_id')));

        if (!$speaker) {
            if ($speakers->isEmpty()) {
                return response()->json(['success' => false, 'message' => 'Kênh chưa có MC (Speaker) nào — hãy tạo MC ở trang quản lý kênh YouTube trước.'], 422);
            }
            return response()->json(['success' => false, 'message' => 'Kênh có nhiều MC — vui lòng chọn MC để thêm ảnh vào.'], 422);
        }

        $request->validate(['image' => 'required|image|max:5120']);

        $path = $request->file('image')->store('speakers/' . $channel->id . '/additional', 'public');

        $additionalImages = $speaker->additional_images ?? [];
        $additionalImages[] = $path;
        $speaker->additional_images = $additionalImages;
        $speaker->save();

        $shot->update(['avatar_image_path' => $path]);

        return response()->json(['success' => true, 'path' => $path, 'shot' => $shot->fresh()]);
    }

    /** @return array<int,string> every avatar/additional image path across all of the book's channel's speakers */
    private function channelAvatarImagePaths(AudioBook $audioBook): array
    {
        $channel = $audioBook->youtubeChannel;
        if (!$channel) {
            return [];
        }

        $paths = [];
        foreach ($channel->speakers()->get() as $speaker) {
            if ($speaker->avatar) {
                $paths[] = $speaker->avatar;
            }
            foreach ($speaker->additional_images ?? [] as $imagePath) {
                $paths[] = $imagePath;
            }
        }

        return $paths;
    }

    /**
     * Final v1 deliverable: zip up every resolved shot asset + a manifest.json, so the
     * user can self-edit the video with a real NLE.
     */
    public function downloadResources(AudioBook $audioBook)
    {
        $scenes = $audioBook->videoScenes()->with('shots')->orderBy('scene_index')->get();

        if ($scenes->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'Chưa có cảnh nào để tải.'], 404);
        }

        $tmpDir = storage_path('app/tmp');
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0775, true);
        }

        $zipFileName = 'video_pipeline_book_' . $audioBook->id . '_' . now()->format('Ymd_His') . '.zip';
        $zipPath = $tmpDir . '/' . $zipFileName;

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return response()->json(['success' => false, 'message' => 'Không thể tạo file ZIP để tải.'], 500);
        }

        $manifest = [
            'audio_book_id' => $audioBook->id,
            'title' => $audioBook->title,
            'generated_at' => now()->toIso8601String(),
            'scenes' => [],
        ];

        $addedCount = 0;

        foreach ($scenes as $scene) {
            $sceneFolder = sprintf('scene_%02d_%s', $scene->scene_index, $scene->scene_type);
            $sceneManifest = [
                'scene_index' => $scene->scene_index,
                'title' => $scene->title,
                'scene_type' => $scene->scene_type,
                'estimated_duration_seconds' => $scene->estimated_duration_seconds,
                'shots' => [],
            ];

            foreach ($scene->shots as $shot) {
                $shotFolder = $sceneFolder . sprintf('/shot_%03d', $shot->shot_index);
                $assetRelativePath = $shot->is_avatar_segment ? $shot->avatar_video_path : $shot->resolved_asset_path;

                $assetEntryName = null;
                if ($assetRelativePath && Storage::disk('public')->exists($assetRelativePath)) {
                    $absPath = Storage::disk('public')->path($assetRelativePath);
                    $assetEntryName = $shotFolder . '/' . basename($assetRelativePath);
                    if ($zip->addFile($absPath, $assetEntryName)) {
                        $addedCount++;
                    }
                }

                $scriptEntryName = $shotFolder . '/text.txt';
                $zip->addFromString($scriptEntryName, $shot->sentence_text);
                $addedCount++;

                $sceneManifest['shots'][] = [
                    'shot_index' => $shot->shot_index,
                    'is_avatar_segment' => $shot->is_avatar_segment,
                    'estimated_duration_seconds' => $shot->estimated_duration_seconds,
                    'keywords' => $shot->keywords,
                    'status' => $shot->status,
                    'resolved_source' => $shot->resolved_source,
                    'resolved_score' => $shot->resolved_score,
                    'asset_file' => $assetEntryName,
                    'text_file' => $scriptEntryName,
                ];
            }

            $manifest['scenes'][] = $sceneManifest;
        }

        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $zip->close();

        if ($addedCount === 0) {
            @unlink($zipPath);
            return response()->json(['success' => false, 'message' => 'Chưa có tài nguyên nào sẵn sàng để tải.'], 422);
        }

        return response()->download($zipPath, $zipFileName, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }
}
