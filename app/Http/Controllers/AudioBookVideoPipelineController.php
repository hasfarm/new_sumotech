<?php

namespace App\Http\Controllers;

use App\Jobs\ArchiveAssetToLibraryJob;
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
        private readonly R2StorageService $r2Storage
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
            'scenes' => $audioBook->videoScenes()->with('shots.candidates')->get(),
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
        $selected = (!$forceAi && $shot->is_real_world) ? $shot->candidates()->where('is_selected', true)->first() : null;

        if ($selected && $selected->score_final >= ClipScoringService::SCORE_THRESHOLD) {
            try {
                $path = $this->resolverService->downloadStockAsset($shot, $selected->toArray());
            } catch (\Throwable $e) {
                $shot->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
                return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
            }

            $shot->update([
                'status' => 'ready',
                'resolved_source' => $selected->source,
                'resolved_asset_path' => $path,
                'resolved_score' => $selected->score_final,
                'error_message' => null,
            ]);

            ArchiveAssetToLibraryJob::dispatch($shot->id, $selected->source, $selected->external_id, $selected->license_label);

            return response()->json(['success' => true, 'mode' => 'stock', 'shot' => $shot->fresh()]);
        }

        $shot->update(['status' => 'resolving', 'error_message' => null]);

        try {
            $path = $this->resolverService->generateAiImage($shot);
        } catch (\Throwable $e) {
            $shot->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }

        $shot->update([
            'status' => 'image_ready',
            'resolved_source' => 'ai_image',
            'resolved_asset_path' => $path,
            'error_message' => null,
        ]);

        ArchiveAssetToLibraryJob::dispatch($shot->id, 'ai_image', null);

        return response()->json(['success' => true, 'mode' => 'ai_image', 'shot' => $shot->fresh()]);
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

        $shot->update(['status' => 'resolving', 'error_message' => null]);
        GenerateAvatarSegmentJob::dispatch($shot->id);

        return response()->json(['success' => true, 'shot' => $shot->fresh()]);
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
