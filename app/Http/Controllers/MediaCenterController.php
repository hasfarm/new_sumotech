<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeMediaCenterProjectJob;
use App\Jobs\GenerateMediaCenterSentenceAssetJob;
use App\Models\MediaCenterProject;
use App\Models\MediaCenterSentence;
use App\Services\GeminiImageService;
use App\Services\MediaCenterAnalyzeService;
use App\Services\TTSService;
use App\Services\TranslationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class MediaCenterController extends Controller
{
    public function index(Request $request)
    {
        $projects = MediaCenterProject::query()
            ->when(Auth::check(), function ($query) {
                $query->where(function ($q) {
                    $q->where('user_id', Auth::id())
                        ->orWhereNull('user_id');
                });
            })
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'title', 'status', 'updated_at', 'main_character_name', 'settings_json']);

        if ($projects->isNotEmpty()) {
            $projectIds = $projects->pluck('id')->map(static fn($id) => (int) $id)->all();

            $assetProjectIds = MediaCenterSentence::query()
                ->whereIn('media_center_project_id', $projectIds)
                ->where(function ($query) {
                    $query->where(function ($q) {
                        $q->whereNotNull('tts_audio_path')
                            ->where('tts_audio_path', '!=', '');
                    })->orWhere(function ($q) {
                        $q->whereNotNull('image_path')
                            ->where('image_path', '!=', '');
                    });
                })
                ->distinct()
                ->pluck('media_center_project_id')
                ->map(static fn($id) => (int) $id)
                ->all();

            $hasGeneratedAssetLookup = array_fill_keys($assetProjectIds, true);

            $projects->each(function (MediaCenterProject $candidate) use ($hasGeneratedAssetLookup): void {
                $workflow = $this->resolveProjectWorkflowStatus($candidate, isset($hasGeneratedAssetLookup[(int) $candidate->id]));
                $candidate->setAttribute('workflow_status_key', $workflow['key']);
                $candidate->setAttribute('workflow_status_label', $workflow['label']);
                $candidate->setAttribute('workflow_status_badge_class', $workflow['badge_class']);
            });
        }

        $selectedId = (int) $request->query('project_id', 0);
        if (!$projects->contains('id', $selectedId) && $projects->isNotEmpty()) {
            $selectedId = (int) $projects->first()->id;
        }

        $selectedProject = $selectedId > 0
            ? MediaCenterProject::with('sentences')->find($selectedId)
            : null;

        if ($selectedProject) {
            $this->hydrateSentenceImageGalleryPaths($selectedProject);
        }

        $workspaceStats = $selectedProject
            ? $this->buildWorkspaceStats($selectedProject)
            : null;

        return view('media_center.index', [
            'projects' => $projects,
            'selectedProject' => $selectedProject,
            'selectedId' => $selectedId,
            'workspaceStats' => $workspaceStats,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => 'nullable|string|max:200',
            'source_text' => 'required|string|min:20',
            'language' => 'nullable|string|max:12',
            'story_era' => 'nullable|string|max:120',
            'story_genre' => 'nullable|string|max:120',
            'world_context' => 'nullable|string|max:1000',
            'forbidden_elements' => 'nullable|string|max:1000',
            'image_aspect_ratio' => 'nullable|string|in:16:9,9:16,1:1',
            'image_style' => 'nullable|string|max:120',
        ]);

        $sourceText = trim((string) $data['source_text']);
        $sentences = $this->splitSentences($sourceText);

        if (empty($sentences)) {
            return response()->json([
                'success' => false,
                'message' => 'Không tách được câu từ văn bản đã nhập.',
            ], 422);
        }

        $project = MediaCenterProject::create([
            'user_id' => Auth::id(),
            'title' => trim((string) ($data['title'] ?? '')) ?: ('Media Center ' . now()->format('Y-m-d H:i')),
            'source_text' => $sourceText,
            'language' => trim((string) ($data['language'] ?? 'vi')) ?: 'vi',
            'settings_json' => [
                'story_era' => trim((string) ($data['story_era'] ?? '')),
                'story_genre' => trim((string) ($data['story_genre'] ?? '')),
                'world_context' => trim((string) ($data['world_context'] ?? '')),
                'forbidden_elements' => trim((string) ($data['forbidden_elements'] ?? '')),
                'image_aspect_ratio' => trim((string) ($data['image_aspect_ratio'] ?? '16:9')) ?: '16:9',
                'image_style' => trim((string) ($data['image_style'] ?? 'Cinematic')) ?: 'Cinematic',
                'use_character_reference' => false,
            ],
            'status' => 'draft',
        ]);

        $rows = [];
        foreach ($sentences as $idx => $sentence) {
            $rows[] = [
                'media_center_project_id' => $project->id,
                'sentence_index' => $idx + 1,
                'sentence_text' => $sentence,
                'tts_text' => $sentence,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        MediaCenterSentence::insert($rows);

        return response()->json([
            'success' => true,
            'project_id' => $project->id,
            'redirect_url' => route('media-center.index', ['project_id' => $project->id]),
        ]);
    }

    public function updateProject(Request $request, MediaCenterProject $project): JsonResponse
    {
        $this->authorizeProject($project);

        $data = $request->validate([
            'title' => 'nullable|string|max:200',
            'source_text' => 'required|string|min:20',
            'language' => 'nullable|string|max:12',
            'story_era' => 'nullable|string|max:120',
            'story_genre' => 'nullable|string|max:120',
            'world_context' => 'nullable|string|max:1000',
            'forbidden_elements' => 'nullable|string|max:1000',
            'image_aspect_ratio' => 'nullable|string|in:16:9,9:16,1:1',
            'image_style' => 'nullable|string|max:120',
            'rebuild_sentences' => 'nullable|boolean',
        ]);

        $sourceText = trim((string) $data['source_text']);
        $rebuild = (bool) ($data['rebuild_sentences'] ?? false);
        $sourceChanged = $sourceText !== (string) $project->source_text;

        $settings = is_array($project->settings_json) ? $project->settings_json : [];
        $settings['story_era'] = trim((string) ($data['story_era'] ?? ''));
        $settings['story_genre'] = trim((string) ($data['story_genre'] ?? ''));
        $settings['world_context'] = trim((string) ($data['world_context'] ?? ''));
        $settings['forbidden_elements'] = trim((string) ($data['forbidden_elements'] ?? ''));
        $settings['image_aspect_ratio'] = trim((string) ($data['image_aspect_ratio'] ?? ($settings['image_aspect_ratio'] ?? '16:9'))) ?: '16:9';
        $settings['image_style'] = trim((string) ($data['image_style'] ?? ($settings['image_style'] ?? 'Cinematic'))) ?: 'Cinematic';

        $project->forceFill([
            'title' => trim((string) ($data['title'] ?? '')) ?: $project->title,
            'source_text' => $sourceText,
            'language' => trim((string) ($data['language'] ?? 'vi')) ?: 'vi',
            'settings_json' => $settings,
        ])->save();

        if ($rebuild && $sourceChanged) {
            $sentences = $this->splitSentences($sourceText);
            if (empty($sentences)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Không tách được câu sau khi cập nhật source text.',
                ], 422);
            }

            $project->sentences()->delete();

            $rows = [];
            foreach ($sentences as $idx => $sentence) {
                $rows[] = [
                    'media_center_project_id' => $project->id,
                    'sentence_index' => $idx + 1,
                    'sentence_text' => $sentence,
                    'tts_text' => $sentence,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            MediaCenterSentence::insert($rows);

            $project->forceFill([
                'status' => 'draft',
                'main_character_name' => null,
                'main_character_profile' => null,
                'characters_json' => null,
            ])->save();

            $settings = is_array($project->settings_json) ? $project->settings_json : [];
            foreach ($this->extractMainCharacterReferenceImagePaths($project) as $refPath) {
                $this->deletePublicStorageFile($refPath);
            }
            unset($settings['main_character_reference_images'], $settings['main_character_identity_lock']);
            $settings['use_character_reference'] = false;

            $project->forceFill([
                'settings_json' => $settings,
            ])->save();
        }

        return response()->json([
            'success' => true,
            'project_id' => $project->id,
            'redirect_url' => route('media-center.index', ['project_id' => $project->id]),
        ]);
    }

    public function destroy(MediaCenterProject $project): JsonResponse
    {
        $this->authorizeProject($project);

        $projectId = (int) $project->id;
        $sentences = $project->sentences()->get(['tts_audio_path', 'image_path', 'metadata_json']);

        foreach ($sentences as $sentence) {
            $this->deletePublicStorageFile((string) ($sentence->tts_audio_path ?? ''));

            foreach ($this->extractImagePathsFromSentence($sentence) as $imagePath) {
                $this->deletePublicStorageFile($imagePath);
            }
        }

        $project->sentences()->delete();
        $project->delete();

        Storage::disk('public')->deleteDirectory('media_center/' . $projectId);

        return response()->json([
            'success' => true,
            'redirect_url' => route('media-center.index'),
        ]);
    }

    public function analyze(Request $request, MediaCenterProject $project): JsonResponse
    {
        $this->authorizeProject($project);

        $total = $project->sentences()->count();
        $settings = is_array($project->settings_json) ? $project->settings_json : [];
        $settings['analyze'] = [
            'status' => 'queued',
            'progress' => 0,
            'message' => 'Đã đưa vào hàng đợi phân tích...',
            'processed_sentences' => 0,
            'total_sentences' => $total,
            'error' => null,
            'updated_at' => now()->toIso8601String(),
            'finished_at' => null,
        ];

        $project->forceFill([
            'status' => 'analyzing',
            'settings_json' => $settings,
        ])->save();

        AnalyzeMediaCenterProjectJob::dispatch((int) $project->id);

        return response()->json([
            'success' => true,
            'queued' => true,
            'project_id' => $project->id,
        ]);
    }

    public function analyzeProgress(Request $request, MediaCenterProject $project): JsonResponse
    {
        $this->authorizeProject($project);

        $settings = is_array($project->settings_json) ? $project->settings_json : [];
        $analyze = is_array($settings['analyze'] ?? null) ? $settings['analyze'] : [];

        return response()->json([
            'success' => true,
            'analyze' => [
                'status' => (string) ($analyze['status'] ?? 'idle'),
                'progress' => (int) ($analyze['progress'] ?? 0),
                'message' => (string) ($analyze['message'] ?? 'Chưa bắt đầu.'),
                'processed_sentences' => (int) ($analyze['processed_sentences'] ?? 0),
                'total_sentences' => (int) ($analyze['total_sentences'] ?? $project->sentences()->count()),
                'error' => $analyze['error'] ?? null,
                'updated_at' => $analyze['updated_at'] ?? null,
                'finished_at' => $analyze['finished_at'] ?? null,
            ],
            'project_status' => (string) ($project->status ?? 'draft'),
        ]);
    }

    public function queueHealth(Request $request): JsonResponse
    {
        $defaultConnection = (string) config('queue.default', 'sync');

        if ($defaultConnection !== 'database') {
            return response()->json([
                'success' => true,
                'status' => 'online',
                'label' => 'Online',
                'message' => 'Queue connection khong phai database, bo monitor theo jobs table.',
                'pending_total' => 0,
                'stale_pending' => 0,
                'connection' => $defaultConnection,
            ]);
        }

        if (!Schema::hasTable('jobs')) {
            return response()->json([
                'success' => true,
                'status' => 'offline',
                'label' => 'Offline',
                'message' => 'Khong tim thay bang jobs, queue worker khong the theo doi.',
                'pending_total' => 0,
                'stale_pending' => 0,
                'connection' => $defaultConnection,
            ]);
        }

        $pendingTotal = (int) DB::table('jobs')->count();
        $staleThreshold = now()->subMinutes(2);
        $stalePending = (int) DB::table('jobs')
            ->where('created_at', '<', $staleThreshold->timestamp)
            ->count();

        $status = $stalePending > 0 ? 'offline' : 'online';
        $label = $status === 'online' ? 'Online' : 'Offline';
        $message = $status === 'online'
            ? 'Queue worker dang xu ly binh thuong.'
            : 'Co job bi treo qua 2 phut, vui long kiem tra worker/queue config.';

        return response()->json([
            'success' => true,
            'status' => $status,
            'label' => $label,
            'message' => $message,
            'pending_total' => $pendingTotal,
            'stale_pending' => $stalePending,
            'connection' => $defaultConnection,
        ]);
    }

    public function updateSentence(Request $request, MediaCenterProject $project, MediaCenterSentence $sentence): JsonResponse
    {
        $this->authorizeSentence($project, $sentence);

        $data = $request->validate([
            'tts_text' => 'nullable|string|max:2000',
            'image_prompt' => 'nullable|string|max:4000',
            'video_prompt' => 'nullable|string|max:4000',
            'character_notes' => 'nullable|string|max:2000',
            'tts_provider' => 'nullable|string|max:30',
            'image_provider' => 'nullable|string|max:30',
            'tts_voice_gender' => 'nullable|string|max:20',
            'tts_voice_name' => 'nullable|string|max:120',
            'tts_speed' => 'nullable|numeric|min:0.5|max:2',
        ]);

        $sentence->fill($data);
        $sentence->save();

        return response()->json([
            'success' => true,
            'sentence' => $sentence->fresh(),
        ]);
    }

    public function updateCharacterInfo(Request $request, MediaCenterProject $project, MediaCenterAnalyzeService $analyzeService): JsonResponse
    {
        $this->authorizeProject($project);

        $data = $request->validate([
            'main_character_name' => 'nullable|string|max:180',
            'main_character_profile' => 'nullable|string|max:6000',
            'characters_json' => 'nullable',
        ]);

        $characters = $data['characters_json'] ?? [];

        if (is_string($characters)) {
            $decoded = json_decode($characters, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $characters = $decoded;
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'characters_json phải là JSON hợp lệ (array).',
                ], 422);
            }
        }

        if (!is_array($characters)) {
            return response()->json([
                'success' => false,
                'message' => 'characters_json phải là mảng.',
            ], 422);
        }

        $normalizedCharacters = [];
        foreach ($characters as $character) {
            if (!is_array($character)) {
                continue;
            }

            $name = trim((string) ($character['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $normalizedCharacters[] = [
                'name' => $name,
                'gender' => trim((string) ($character['gender'] ?? 'unspecified')),
                'race' => trim((string) ($character['race'] ?? 'Asian')),
                'skin_tone' => trim((string) ($character['skin_tone'] ?? 'light-medium')),
                'appearance' => trim((string) ($character['appearance'] ?? '')),
                'wardrobe' => trim((string) ($character['wardrobe'] ?? '')),
            ];
        }

        $project->forceFill([
            'main_character_name' => trim((string) ($data['main_character_name'] ?? '')) ?: null,
            'main_character_profile' => trim((string) ($data['main_character_profile'] ?? '')) ?: null,
            'characters_json' => $normalizedCharacters,
        ])->save();

        $updatedSentences = $this->rebuildAllSentencePlansForProject($project, $analyzeService);

        return response()->json([
            'success' => true,
            'updated_sentences' => $updatedSentences,
            'project' => $project->fresh(['sentences']),
        ]);
    }

    public function cleanupProjectPrompts(Request $request, MediaCenterProject $project, MediaCenterAnalyzeService $analyzeService): JsonResponse
    {
        $this->authorizeProject($project);

        $totalSentences = (int) $project->sentences()->count();
        $updatedSentences = $this->rebuildAllSentencePlansForProject($project, $analyzeService);

        return response()->json([
            'success' => true,
            'message' => $updatedSentences > 0
                ? ('Da cleanup prompt cu va cap nhat ' . $updatedSentences . '/' . $totalSentences . ' cau theo rule moi.')
                : 'Khong co cau nao can cap nhat theo rule moi.',
            'total_sentences' => $totalSentences,
            'updated_sentences' => $updatedSentences,
            'project' => $project->fresh(['sentences']),
        ]);
    }

    public function updateWorldProfile(Request $request, MediaCenterProject $project): JsonResponse
    {
        $this->authorizeProject($project);

        $data = $request->validate([
            'story_era' => 'nullable|string|max:120',
            'story_genre' => 'nullable|string|max:120',
            'world_context' => 'nullable|string|max:1000',
            'forbidden_elements' => 'nullable|string|max:1000',
            'image_aspect_ratio' => 'nullable|string|in:16:9,9:16,1:1',
            'image_style' => 'nullable|string|max:120',
        ]);

        $settings = is_array($project->settings_json) ? $project->settings_json : [];
        $settings['story_era'] = trim((string) ($data['story_era'] ?? ''));
        $settings['story_genre'] = trim((string) ($data['story_genre'] ?? ''));
        $settings['world_context'] = trim((string) ($data['world_context'] ?? ''));
        $settings['forbidden_elements'] = trim((string) ($data['forbidden_elements'] ?? ''));
        $settings['image_aspect_ratio'] = trim((string) ($data['image_aspect_ratio'] ?? ($settings['image_aspect_ratio'] ?? '16:9'))) ?: '16:9';
        $settings['image_style'] = trim((string) ($data['image_style'] ?? ($settings['image_style'] ?? 'Cinematic'))) ?: 'Cinematic';

        $project->forceFill([
            'settings_json' => $settings,
        ])->save();

        return response()->json([
            'success' => true,
            'project' => $project->fresh(),
        ]);
    }

    public function updateMediaSettings(Request $request, MediaCenterProject $project): JsonResponse
    {
        $this->authorizeProject($project);

        $data = $request->validate([
            'tts_provider' => 'nullable|string|max:30',
            'voice_gender' => 'nullable|string|max:20',
            'voice_name' => 'nullable|string|max:120',
            'speed' => 'nullable|numeric|min:0.5|max:2',
            'image_provider' => 'nullable|string|max:40',
            'animation_provider' => 'nullable|string|max:30',
            'image_aspect_ratio' => 'nullable|string|in:16:9,9:16,1:1',
            'image_style' => 'nullable|string|max:120',
            'use_character_reference' => 'nullable|boolean',
        ]);

        $ttsProvider = trim((string) ($data['tts_provider'] ?? 'google')) ?: 'google';
        $voiceGender = trim((string) ($data['voice_gender'] ?? 'female')) ?: 'female';
        $voiceName = trim((string) ($data['voice_name'] ?? '')) ?: null;
        $speed = (float) ($data['speed'] ?? 1.0);
        $imageProvider = $this->normalizeImageProvider((string) ($data['image_provider'] ?? 'gemini'));
        $animationProvider = $this->normalizeAnimationProvider((string) ($data['animation_provider'] ?? 'kling'));
        $settings = is_array($project->settings_json) ? $project->settings_json : [];
        $imageAspectRatio = trim((string) ($data['image_aspect_ratio'] ?? ($settings['image_aspect_ratio'] ?? '16:9'))) ?: '16:9';
        if (!in_array($imageAspectRatio, ['16:9', '9:16', '1:1'], true)) {
            $imageAspectRatio = '16:9';
        }
        $imageStyle = trim((string) ($data['image_style'] ?? ($settings['image_style'] ?? 'Cinematic'))) ?: 'Cinematic';
        $useCharacterReference = (bool) ($data['use_character_reference'] ?? false);

        $updated = $project->sentences()->update([
            'tts_provider' => $ttsProvider,
            'tts_voice_gender' => $voiceGender,
            'tts_voice_name' => $voiceName,
            'tts_speed' => $speed,
            'image_provider' => $imageProvider,
            'updated_at' => now(),
        ]);

        $settings['image_aspect_ratio'] = $imageAspectRatio;
        $settings['image_style'] = $imageStyle;
        $settings['media_defaults'] = [
            'tts_provider' => $ttsProvider,
            'voice_gender' => $voiceGender,
            'voice_name' => $voiceName,
            'speed' => $speed,
            'image_provider' => $imageProvider,
            'animation_provider' => $animationProvider,
            'image_aspect_ratio' => $imageAspectRatio,
            'image_style' => $imageStyle,
            'use_character_reference' => $useCharacterReference,
        ];
        $settings['use_character_reference'] = $useCharacterReference;

        $project->forceFill([
            'settings_json' => $settings,
        ])->save();

        return response()->json([
            'success' => true,
            'updated_sentences' => $updated,
            'use_character_reference' => $useCharacterReference,
            'project' => $project->fresh(['sentences']),
        ]);
    }

    public function generateImageStylePreviews(Request $request, MediaCenterProject $project, GeminiImageService $imageService): JsonResponse
    {
        $this->authorizeProject($project);

        $data = $request->validate([
            'style' => 'nullable|string|max:120',
        ]);

        $settings = is_array($project->settings_json) ? $project->settings_json : [];
        $styles = $this->getSupportedImageStyles();
        $selectedStyle = trim((string) ($data['style'] ?? ''));
        if ($selectedStyle !== '' && !in_array($selectedStyle, $styles, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Style khong hop le.',
            ], 422);
        }
        $targetStyles = $selectedStyle !== '' ? [$selectedStyle] : $styles;
        $scene = $project->sentences()->orderBy('sentence_index')->first();

        if (!$scene) {
            return response()->json([
                'success' => false,
                'message' => 'Project chua co cau nao de tao anh minh hoa.',
            ], 422);
        }

        $scenePrompt = trim((string) ($scene->image_prompt ?: $scene->sentence_text ?: ''));
        if ($scenePrompt === '') {
            return response()->json([
                'success' => false,
                'message' => 'Cau 1 chua co noi dung de tao anh minh hoa.',
            ], 422);
        }

        $aspectRatio = trim((string) ($settings['image_aspect_ratio'] ?? '16:9'));
        if (!in_array($aspectRatio, ['16:9', '9:16', '1:1'], true)) {
            $aspectRatio = '16:9';
        }

        $existingPreviewsRaw = is_array($settings['image_style_previews'] ?? null)
            ? $settings['image_style_previews']
            : [];
        $existingPreviews = [];
        foreach ($existingPreviewsRaw as $styleKey => $item) {
            if (!is_array($item)) {
                continue;
            }
            $styleName = trim((string) ($item['style'] ?? $styleKey));
            if ($styleName === '') {
                continue;
            }
            $existingPreviews[$styleName] = $item;
        }

        $provider = 'gemini-nano-banana-pro';
        $generated = [];
        $errors = [];

        foreach ($targetStyles as $style) {
            $oldPath = isset($existingPreviews[$style])
                ? trim((string) ($existingPreviews[$style]['path'] ?? ''))
                : '';

            $prompt = $this->buildSceneStylePreviewPrompt($project, (int) $scene->sentence_index, $scenePrompt, $style);
            $relativePath = $this->buildImageStylePreviewPath((int) $project->id, $style);
            $absolutePath = storage_path('app/public/' . $relativePath);

            $result = $imageService->generateImage($prompt, $absolutePath, $aspectRatio, $provider);
            if (!(bool) ($result['success'] ?? false)) {
                $errors[] = [
                    'style' => $style,
                    'error' => (string) ($result['error'] ?? 'Tao anh minh hoa that bai.'),
                ];
                continue;
            }

            if ($oldPath !== '') {
                $this->deletePublicStorageFile($oldPath);
            }

            $generated[$style] = [
                'style' => $style,
                'path' => $relativePath,
                'provider' => $provider,
                'source_sentence_index' => (int) $scene->sentence_index,
                'source_sentence_id' => (int) $scene->id,
                'aspect_ratio' => $aspectRatio,
                'generated_at' => now()->toIso8601String(),
            ];

            $this->addProjectUsageCost($project, 'image', $this->estimateImageCostUsd($provider));
        }

        if (empty($generated)) {
            return response()->json([
                'success' => false,
                'message' => 'Khong tao duoc anh minh hoa cho style da chon.',
                'errors' => $errors,
            ], 422);
        }

        $previewMap = [];
        foreach ($existingPreviews as $style => $item) {
            if (!is_array($item)) {
                continue;
            }

            $previewMap[$style] = [
                'style' => $style,
                'path' => (string) ($item['path'] ?? ''),
                'provider' => (string) ($item['provider'] ?? $provider),
                'source_sentence_index' => (int) ($item['source_sentence_index'] ?? (int) $scene->sentence_index),
                'source_sentence_id' => (int) ($item['source_sentence_id'] ?? 0),
                'aspect_ratio' => (string) ($item['aspect_ratio'] ?? $aspectRatio),
                'generated_at' => (string) ($item['generated_at'] ?? now()->toIso8601String()),
            ];
        }

        foreach ($generated as $style => $item) {
            $previewMap[$style] = [
                'style' => $style,
                'path' => (string) ($item['path'] ?? ''),
                'provider' => (string) ($item['provider'] ?? $provider),
                'source_sentence_index' => (int) ($item['source_sentence_index'] ?? (int) $scene->sentence_index),
                'source_sentence_id' => (int) ($item['source_sentence_id'] ?? 0),
                'aspect_ratio' => (string) ($item['aspect_ratio'] ?? $aspectRatio),
                'generated_at' => (string) ($item['generated_at'] ?? now()->toIso8601String()),
            ];
        }

        $settings['image_style_previews'] = $previewMap;
        $project->forceFill([
            'settings_json' => $settings,
        ])->save();

        return response()->json([
            'success' => true,
            'generated' => count($previewMap),
            'provider' => $provider,
            'source_sentence_index' => (int) $scene->sentence_index,
            'errors' => $errors,
            'image_style_previews' => $this->formatImageStylePreviewsForResponse($previewMap),
            'project' => $project->fresh(),
        ]);
    }

    public function deleteImageStylePreview(Request $request, MediaCenterProject $project): JsonResponse
    {
        $this->authorizeProject($project);

        $data = $request->validate([
            'style' => 'required|string|max:120',
        ]);

        $style = trim((string) ($data['style'] ?? ''));
        $supportedStyles = $this->getSupportedImageStyles();
        if (!in_array($style, $supportedStyles, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Style khong hop le.',
            ], 422);
        }

        $settings = is_array($project->settings_json) ? $project->settings_json : [];
        $raw = is_array($settings['image_style_previews'] ?? null)
            ? $settings['image_style_previews']
            : [];

        $target = strtolower(trim($style));
        $found = false;
        $deletedPath = '';
        $remaining = [];

        foreach ($raw as $styleKey => $item) {
            if (!is_array($item)) {
                continue;
            }

            $itemStyle = trim((string) ($item['style'] ?? $styleKey));
            if ($itemStyle === '') {
                continue;
            }

            if (strtolower($itemStyle) === $target) {
                $found = true;
                $deletedPath = trim((string) ($item['path'] ?? ''));
                continue;
            }

            $remaining[$itemStyle] = $item;
        }

        if (!$found) {
            return response()->json([
                'success' => false,
                'message' => 'Khong tim thay preview cua style nay de xoa.',
            ], 404);
        }

        if ($deletedPath !== '') {
            $this->deletePublicStorageFile($deletedPath);
        }

        $settings['image_style_previews'] = $remaining;
        $project->forceFill([
            'settings_json' => $settings,
        ])->save();

        return response()->json([
            'success' => true,
            'deleted_style' => $style,
            'image_style_previews' => $this->formatImageStylePreviewsForResponse($remaining),
            'project' => $project->fresh(),
        ]);
    }

    public function generateMainCharacterReferences(Request $request, MediaCenterProject $project, GeminiImageService $imageService): JsonResponse
    {
        $this->authorizeProject($project);

        $data = $request->validate([
            'provider'         => 'nullable|string|max:40',
            'shot_type'        => 'nullable|string|in:face,main_costume,alt_costume',
            'alt_costume_desc' => 'nullable|string|max:400',
        ]);

        $provider      = $this->normalizeImageProvider((string) ($data['provider'] ?? 'gemini'));
        $shotType      = trim((string) ($data['shot_type'] ?? 'face'));
        $altCostumeDesc = trim((string) ($data['alt_costume_desc'] ?? ''));

        if (!in_array($shotType, ['face', 'main_costume', 'alt_costume'], true)) {
            $shotType = 'face';
        }

        $name    = trim((string) ($project->main_character_name ?? ''));
        $profile = trim((string) ($project->main_character_profile ?? ''));

        if ($name === '' && $profile === '') {
            return response()->json([
                'success' => false,
                'message' => 'Vui long nhap ten hoac mo ta nhan vat chinh truoc khi tao reference.',
            ], 422);
        }

        if ($shotType === 'alt_costume' && $altCostumeDesc === '') {
            return response()->json([
                'success' => false,
                'message' => 'Vui long nhap mo ta trang phuc 2 (alt_costume_desc).',
            ], 422);
        }

        $settings    = is_array($project->settings_json) ? $project->settings_json : [];
        $style       = trim((string) ($settings['image_style'] ?? 'Cinematic')) ?: 'Cinematic';
        $era         = trim((string) ($settings['story_era'] ?? ''));
        $genre       = trim((string) ($settings['story_genre'] ?? ''));
        $worldContext = trim((string) ($settings['world_context'] ?? ''));
        $forbidden   = trim((string) ($settings['forbidden_elements'] ?? ''));

        // Resolve default wardrobe for main_costume shot
        $charData       = is_array($settings['main_character_data'] ?? null) ? $settings['main_character_data'] : [];
        $defaultWardrobe = trim((string) ($charData['wardrobe'] ?? ''));
        if ($defaultWardrobe === '') {
            $defaultWardrobe = $this->parseProfileField($profile, 'Wardrobe');
        }

        $existingRefs = is_array($settings['main_character_reference_images'] ?? null)
            ? $settings['main_character_reference_images']
            : [];

        // Separate refs into: preserved (keep) vs stale (delete)
        $preservedRefs = [];
        $staleAiPaths  = [];
        foreach ($existingRefs as $ref) {
            if (!is_array($ref)) {
                continue;
            }
            $path   = trim((string) ($ref['path'] ?? ''));
            if ($path === '') {
                continue;
            }
            $source      = trim((string) ($ref['source'] ?? ''));
            $type        = trim((string) ($ref['type'] ?? ''));
            $costumeType = trim((string) ($ref['costume_type'] ?? ''));
            $isUploaded  = $source === 'manual_upload' || str_starts_with($type, 'upload');

            if ($isUploaded) {
                $preservedRefs[] = $ref;
                continue;
            }

            // Determine whether to stale this particular AI shot based on shot_type
            $shouldStale = match ($shotType) {
                'face'         => $type === 'face',
                'main_costume' => in_array($type, ['half_body', 'full_body'], true)
                                  && ($costumeType === 'main' || $costumeType === ''),
                'alt_costume'  => in_array($type, ['half_body', 'full_body'], true)
                                  && $costumeType === 'alt',
                default        => false,
            };

            if ($shouldStale) {
                $staleAiPaths[] = $path;
            } else {
                $preservedRefs[] = $ref;
            }
        }

        foreach ($staleAiPaths as $oldPath) {
            $this->deletePublicStorageFile($oldPath);
        }

        // Find current face ref absolute path (needed for costume shots)
        $faceRefAbsolutePath = '';
        foreach ($preservedRefs as $ref) {
            if (is_array($ref) && trim((string) ($ref['type'] ?? '')) === 'face') {
                $faceRefPath = trim((string) ($ref['path'] ?? ''));
                if ($faceRefPath !== '') {
                    $faceRefAbsolutePath = storage_path('app/public/' . $faceRefPath);
                }
                break;
            }
        }

        if (in_array($shotType, ['main_costume', 'alt_costume'], true) && $faceRefAbsolutePath === '') {
            return response()->json([
                'success' => false,
                'message' => 'Chua co anh mat de tham chieu. Vui long tao anh mat truoc.',
            ], 422);
        }

        $identityLock = $this->buildMainCharacterIdentityLock($project);
        $visualLock   = $this->buildMainCharacterVisualLockFromUploadedRefs(array_filter($preservedRefs, static fn($r) => is_array($r) && (trim((string) ($r['source'] ?? '')) === 'manual_upload' || str_starts_with(trim((string) ($r['type'] ?? '')), 'upload'))));
        if ($visualLock !== '') {
            $identityLock = trim($identityLock . "\n\n" . $visualLock);
        }
        $baseContext = $this->buildPromptContextBlock($style, $era, $genre, $worldContext, $forbidden);

        // Build generation plan based on shot_type
        $costumeForPrompt = match ($shotType) {
            'main_costume' => $defaultWardrobe !== '' ? $defaultWardrobe : 'default costume as described in character profile',
            'alt_costume'  => $altCostumeDesc,
            default        => '',
        };
        $costumeLabel = match ($shotType) {
            'main_costume' => $costumeForPrompt,
            'alt_costume'  => $altCostumeDesc,
            default        => '',
        };
        $costumeTypeKey = match ($shotType) {
            'main_costume' => 'main',
            'alt_costume'  => 'alt',
            default        => '',
        };

        $referencePlan = match ($shotType) {
            'face' => [
                [
                    'type'  => 'face',
                    'ratio' => '1:1',
                    'shot'  => 'close-up portrait of face and shoulders only, plain neutral background. No other characters.',
                    'use_face_ref' => false,
                ],
            ],
            'main_costume', 'alt_costume' => [
                [
                    'type'  => 'half_body',
                    'ratio' => '9:16',
                    'shot'  => 'medium shot from waist up showing full costume detail, plain or simple background',
                    'use_face_ref' => true,
                ],
                [
                    'type'  => 'full_body',
                    'ratio' => '9:16',
                    'shot'  => 'full body standing pose head to toe, plain or simple background',
                    'use_face_ref' => true,
                ],
            ],
            default => [],
        };

        $generatedRefs = [];
        $errors        = [];

        foreach ($referencePlan as $item) {
            $type         = (string) $item['type'];
            $ratio        = (string) $item['ratio'];
            $shot         = (string) $item['shot'];
            $useFaceRef   = (bool) ($item['use_face_ref'] ?? false);

            $costumeInstruction = $costumeForPrompt !== ''
                ? "Wearing: {$costumeForPrompt}. Keep ALL other identity traits identical to the face reference."
                : '';

            $faceRefInstruction = $useFaceRef
                ? "CRITICAL: The provided image is the face reference for this character. Reproduce the EXACT same face, skin tone, age impression, hairstyle, and eye shape in this shot."
                : '';

            $prompt = trim(implode("\n\n", array_filter([
                $baseContext,
                $identityLock,
                $faceRefInstruction,
                $costumeInstruction,
                "Reference shot: {$shot}. Keep facial identity, hairstyle, age cues, and skin tone fully consistent.",
            ])));

            $relativePath = $this->buildMainCharacterReferenceImagePath((int) $project->id, $type . ($costumeTypeKey !== '' ? '_' . $costumeTypeKey : ''));
            $absolutePath = storage_path('app/public/' . $relativePath);

            if ($useFaceRef) {
                $result = $imageService->generateImageWithFaceRef($prompt, $absolutePath, $ratio, $faceRefAbsolutePath);
            } else {
                $result = $imageService->generateImage($prompt, $absolutePath, $ratio, $provider);
            }

            if (!(bool) ($result['success'] ?? false)) {
                $errors[] = [
                    'type'  => $type,
                    'error' => (string) ($result['error'] ?? 'Tao anh reference that bai.'),
                ];
                continue;
            }

            $refEntry = [
                'type'         => $type,
                'path'         => $relativePath,
                'ratio'        => $ratio,
                'prompt'       => $prompt,
                'source'       => 'ai_generated',
                'shot_type'    => $shotType,
                'created_at'   => now()->toIso8601String(),
            ];
            if ($costumeTypeKey !== '') {
                $refEntry['costume_type']  = $costumeTypeKey;
                $refEntry['costume_label'] = $costumeLabel;
            }

            $generatedRefs[] = $refEntry;
            $this->addProjectUsageCost($project, 'image', $this->estimateImageCostUsd($provider));
        }

        if (empty($generatedRefs)) {
            return response()->json([
                'success' => false,
                'message' => 'Khong tao duoc anh reference nao cho nhan vat chinh.',
                'errors'  => $errors,
            ], 422);
        }

        $settings['main_character_reference_images'] = array_values(array_merge($preservedRefs, $generatedRefs));
        $settings['main_character_identity_lock']    = $identityLock;
        $settings['use_character_reference']         = (bool) ($settings['use_character_reference'] ?? true);

        $project->forceFill(['settings_json' => $settings])->save();

        $allRefs = $this->formatMainCharacterReferencesForResponse($settings['main_character_reference_images'] ?? []);

        return response()->json([
            'success'           => true,
            'generated'         => count($generatedRefs),
            'preserved_uploaded' => count(array_filter($preservedRefs, static fn($r) => is_array($r) && trim((string) ($r['source'] ?? '')) === 'manual_upload')),
            'shot_type'         => $shotType,
            'errors'            => $errors,
            'identity_lock'     => $identityLock,
            'references'        => $this->formatMainCharacterReferencesForResponse($generatedRefs),
            'all_references'    => $allRefs,
            'project'           => $project->fresh(),
        ]);
    }

    public function uploadMainCharacterReferences(Request $request, MediaCenterProject $project): JsonResponse
    {
        $this->authorizeProject($project);

        $data = $request->validate([
            'references' => 'required|array|min:1|max:12',
            'references.*' => 'required|image|mimes:jpg,jpeg,png,webp|max:10240',
        ]);

        $settings = is_array($project->settings_json) ? $project->settings_json : [];
        $existingRefs = is_array($settings['main_character_reference_images'] ?? null)
            ? $settings['main_character_reference_images']
            : [];

        $uploadedRefs = [];
        foreach (($data['references'] ?? []) as $index => $uploadedFile) {
            if (!$uploadedFile || !$uploadedFile->isValid()) {
                continue;
            }

            $extension = strtolower((string) ($uploadedFile->getClientOriginalExtension() ?: 'png'));
            if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $extension = 'png';
            }

            $relativePath = $this->buildMainCharacterUploadedReferenceImagePath((int) $project->id, $extension);
            $stored = Storage::disk('public')->putFileAs(
                dirname($relativePath),
                $uploadedFile,
                basename($relativePath)
            );

            if (!$stored) {
                continue;
            }

            $uploadedRefs[] = [
                'type' => 'upload_' . ($index + 1),
                'path' => $relativePath,
                'source' => 'manual_upload',
                'original_name' => (string) $uploadedFile->getClientOriginalName(),
                'created_at' => now()->toIso8601String(),
            ];
        }

        if (empty($uploadedRefs)) {
            return response()->json([
                'success' => false,
                'message' => 'Khong upload duoc reference nao. Vui long thu lai voi file hop le.',
            ], 422);
        }

        $settings['main_character_reference_images'] = array_values(array_merge($existingRefs, $uploadedRefs));
        $project->forceFill([
            'settings_json' => $settings,
        ])->save();

        $allRefs = $this->formatMainCharacterReferencesForResponse($settings['main_character_reference_images'] ?? []);

        return response()->json([
            'success' => true,
            'uploaded' => count($uploadedRefs),
            'references' => $this->formatMainCharacterReferencesForResponse($uploadedRefs),
            'all_references' => $allRefs,
            'project' => $project->fresh(),
        ]);
    }

    public function deleteMainCharacterReference(Request $request, MediaCenterProject $project): JsonResponse
    {
        $this->authorizeProject($project);

        $data = $request->validate([
            'reference_path' => 'required|string|max:500',
        ]);

        $referencePath = trim((string) ($data['reference_path'] ?? ''));
        if (!$this->isMainCharacterReferencePathAllowed($project, $referencePath)) {
            return response()->json([
                'success' => false,
                'message' => 'Duong dan reference khong hop le.',
            ], 422);
        }

        $settings = is_array($project->settings_json) ? $project->settings_json : [];
        $rawRefs = is_array($settings['main_character_reference_images'] ?? null)
            ? $settings['main_character_reference_images']
            : [];

        $found = false;
        $remainingRefs = [];
        foreach ($rawRefs as $ref) {
            if (!is_array($ref)) {
                continue;
            }

            $path = trim((string) ($ref['path'] ?? ''));
            if ($path === $referencePath) {
                $found = true;
                continue;
            }

            $remainingRefs[] = $ref;
        }

        if (!$found) {
            return response()->json([
                'success' => false,
                'message' => 'Khong tim thay reference can xoa.',
            ], 404);
        }

        $this->deletePublicStorageFile($referencePath);

        $settings['main_character_reference_images'] = array_values($remainingRefs);
        $project->forceFill([
            'settings_json' => $settings,
        ])->save();

        $allRefs = $this->formatMainCharacterReferencesForResponse($settings['main_character_reference_images'] ?? []);

        return response()->json([
            'success' => true,
            'remaining' => count($remainingRefs),
            'all_references' => $allRefs,
            'project' => $project->fresh(),
        ]);
    }

    public function generateSentenceTts(Request $request, MediaCenterProject $project, MediaCenterSentence $sentence, TTSService $ttsService): JsonResponse
    {
        $this->authorizeSentence($project, $sentence);

        $data = $request->validate([
            'provider' => 'nullable|string|max:30',
            'voice_gender' => 'nullable|string|max:20',
            'voice_name' => 'nullable|string|max:120',
            'speed' => 'nullable|numeric|min:0.5|max:2',
        ]);

        $provider = trim((string) ($data['provider'] ?? $sentence->tts_provider ?: 'google'));
        $voiceGender = trim((string) ($data['voice_gender'] ?? $sentence->tts_voice_gender ?: 'female'));
        $voiceName = trim((string) ($data['voice_name'] ?? $sentence->tts_voice_name ?? '')) ?: null;
        $speed = (float) ($data['speed'] ?? ($sentence->tts_speed ?: 1.0));

        $storagePath = $ttsService->generateAudio(
            (string) ($sentence->tts_text ?: $sentence->sentence_text),
            (int) $sentence->sentence_index,
            $voiceGender,
            $voiceName,
            $provider,
            null,
            null,
            $speed
        );

        $ttsChars = mb_strlen((string) ($sentence->tts_text ?: $sentence->sentence_text));
        $this->addProjectUsageCost($project, 'audio', $this->estimateTtsCostUsd($provider, $ttsChars));

        $sentence->forceFill([
            'tts_provider' => $provider,
            'tts_voice_gender' => $voiceGender,
            'tts_voice_name' => $voiceName,
            'tts_speed' => $speed,
            'tts_audio_path' => $storagePath,
        ])->save();

        return response()->json([
            'success' => true,
            'audio_path' => $storagePath,
            'audio_url' => $this->storagePathToUrl($storagePath),
            'sentence' => $sentence->fresh(),
        ]);
    }

    public function generateSentenceImage(Request $request, MediaCenterProject $project, MediaCenterSentence $sentence): JsonResponse
    {
        $this->authorizeSentence($project, $sentence);

        $data = $request->validate([
            'provider' => 'nullable|string|max:30',
            'reference_sentence_ids' => 'nullable|array|max:12',
            'reference_sentence_ids.*' => 'integer|min:1',
            'include_character_references' => 'nullable|boolean',
        ]);

        $provider = $this->normalizeImageProvider((string) ($data['provider'] ?? $sentence->image_provider ?: 'gemini'));
        $basePrompt = trim((string) ($sentence->image_prompt ?: ''));
        if ($basePrompt === '') {
            return response()->json([
                'success' => false,
                'message' => 'Câu này chưa có image prompt.',
            ], 422);
        }

        $metadata = is_array($sentence->metadata_json) ? $sentence->metadata_json : [];
        $referenceSentenceIds = $this->filterReferenceSentenceIdsForImage($project, $sentence, $data['reference_sentence_ids'] ?? []);
        $settings = is_array($project->settings_json) ? $project->settings_json : [];
        $hasCharacterRefs = false;
        $rawCharacterRefs = is_array($settings['main_character_reference_images'] ?? null)
            ? $settings['main_character_reference_images']
            : [];
        foreach ($rawCharacterRefs as $ref) {
            if (!is_array($ref)) {
                continue;
            }

            if (trim((string) ($ref['path'] ?? '')) !== '') {
                $hasCharacterRefs = true;
                break;
            }
        }
        $includeCharacterReferences = array_key_exists('include_character_references', $data)
            ? (bool) $data['include_character_references']
            : (array_key_exists('use_character_reference', $settings)
                ? ((bool) $settings['use_character_reference'] || $hasCharacterRefs)
                : $hasCharacterRefs);
        $metadata['image_reference_sentence_ids'] = $referenceSentenceIds;
        $metadata['include_character_references'] = $includeCharacterReferences;
        $metadata = $this->setSentenceGenerationState($metadata, 'image', 'queued', 'Da vao hang doi tao anh.', [
            'provider' => $provider,
            'queued_at' => now()->toIso8601String(),
            'reference_sentence_ids' => $referenceSentenceIds,
            'include_character_references' => $includeCharacterReferences,
        ]);

        $sentence->forceFill([
            'metadata_json' => $metadata,
        ])->save();

        GenerateMediaCenterSentenceAssetJob::dispatch(
            (int) $project->id,
            (int) $sentence->id,
            'image',
            $provider,
            [
                'reference_sentence_ids' => $referenceSentenceIds,
                'include_character_references' => $includeCharacterReferences,
            ]
        );

        return response()->json([
            'success' => true,
            'queued' => true,
            'message' => 'Da dua vao hang doi tao anh (toi da 5 worker dong thoi).',
            'reference_sentence_ids' => $referenceSentenceIds,
            'include_character_references' => $includeCharacterReferences,
            'sentence' => $sentence->fresh(),
        ]);
    }

    public function generateSentenceAnimation(Request $request, MediaCenterProject $project, MediaCenterSentence $sentence): JsonResponse
    {
        $this->authorizeSentence($project, $sentence);

        $data = $request->validate([
            'provider' => 'nullable|string|max:30',
            'mode' => 'nullable|string|max:40',
            'camera_angle' => 'nullable|string|max:80',
            'instruction' => 'nullable|string|max:2000',
            'selected_image_paths' => 'nullable|array',
            'selected_image_paths.*' => 'string|max:500',
        ]);

        $provider = $this->normalizeAnimationProvider((string) ($data['provider'] ?? 'kling'));
        $mode = $this->normalizeAnimationMode((string) ($data['mode'] ?? 'image-to-motion'));
        $cameraAngle = $this->normalizeCameraAngle((string) ($data['camera_angle'] ?? 'auto'));
        $instruction = trim((string) ($data['instruction'] ?? ''));

        $metadata = is_array($sentence->metadata_json) ? $sentence->metadata_json : [];
        $generation = is_array($metadata['generation'] ?? null) ? $metadata['generation'] : [];
        $animationGeneration = is_array($generation['animation'] ?? null) ? $generation['animation'] : [];
        $currentStatus = strtolower(trim((string) ($animationGeneration['status'] ?? '')));
        if (in_array($currentStatus, ['queued', 'running'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Animation cho cau nay dang duoc tao. Vui long doi job hien tai hoan tat.',
                'status' => $currentStatus,
            ], 409);
        }

        $availableImagePaths = $this->extractImagePathsFromSentence($sentence);
        $selectedImagePaths = $this->filterSelectedImagePaths($project, $sentence, $data['selected_image_paths'] ?? [], $availableImagePaths);

        if (empty($selectedImagePaths)) {
            $fallback = $availableImagePaths[0] ?? trim((string) ($sentence->image_path ?? ''));
            if ($fallback !== '') {
                $selectedImagePaths[] = $fallback;
            }
        }

        if ($mode !== 'image-to-story-sequence' && count($selectedImagePaths) > 1) {
            return response()->json([
                'success' => false,
                'message' => 'Mode nay chi ho tro 1 anh. Hay chon 1 anh hoac doi sang Story Sequence.',
            ], 422);
        }

        $sourceImagePath = $selectedImagePaths[0] ?? '';

        if ($sourceImagePath === '') {
            return response()->json([
                'success' => false,
                'message' => 'Câu này chưa có ảnh để tạo animation.',
            ], 422);
        }

        $metadata = $this->setSentenceGenerationState($metadata, 'animation', 'queued', 'Da vao hang doi tao animation.', [
            'provider' => $provider,
            'progress' => 0,
            'mode' => $mode,
            'camera_angle' => $cameraAngle,
            'selected_images' => $selectedImagePaths,
            'instruction' => $instruction,
            'queued_at' => now()->toIso8601String(),
        ]);

        $sentence->forceFill([
            'metadata_json' => $metadata,
        ])->save();

        GenerateMediaCenterSentenceAssetJob::dispatch(
            (int) $project->id,
            (int) $sentence->id,
            'animation',
            $provider,
            [
                'mode' => $mode,
                'camera_angle' => $cameraAngle,
                'instruction' => $instruction,
                'selected_image_paths' => $selectedImagePaths,
            ]
        );

        return response()->json([
            'success' => true,
            'queued' => true,
            'mode' => $mode,
            'camera_angle' => $cameraAngle,
            'selected_images' => $selectedImagePaths,
            'message' => 'Da dua vao hang doi tao animation (toi da 5 worker dong thoi).',
            'sentence' => $sentence->fresh(),
        ]);
    }

    public function suggestSentenceAnimationPlan(Request $request, MediaCenterProject $project, MediaCenterSentence $sentence): JsonResponse
    {
        $this->authorizeSentence($project, $sentence);

        $data = $request->validate([
            'mode' => 'nullable|string|max:40',
            'camera_angle' => 'nullable|string|max:80',
            'instruction' => 'nullable|string|max:2000',
            'selected_image_paths' => 'nullable|array',
            'selected_image_paths.*' => 'string|max:500',
            'current_video_prompt' => 'nullable|string|max:12000',
        ]);

        $apiKey = (string) config('services.gemini.api_key', '');
        if ($apiKey === '') {
            return response()->json([
                'success' => false,
                'message' => 'Chua cau hinh GEMINI_API_KEY de AI de xuat motion.',
            ], 422);
        }

        $mode = $this->normalizeAnimationMode((string) ($data['mode'] ?? 'image-to-motion'));
        $cameraAngle = $this->normalizeCameraAngle((string) ($data['camera_angle'] ?? 'auto'));
        $instruction = trim((string) ($data['instruction'] ?? ''));
        $currentVideoPrompt = trim((string) ($data['current_video_prompt'] ?? ($sentence->video_prompt ?? '')));

        $availableImagePaths = $this->extractImagePathsFromSentence($sentence);
        $selectedImagePaths = $this->filterSelectedImagePaths($project, $sentence, $data['selected_image_paths'] ?? [], $availableImagePaths);
        if (empty($selectedImagePaths) && !empty($availableImagePaths)) {
            $selectedImagePaths = [reset($availableImagePaths)];
        }

        if (empty($selectedImagePaths)) {
            return response()->json([
                'success' => false,
                'message' => 'Khong co anh hop le de AI phan tich.',
            ], 422);
        }

        $settings = is_array($project->settings_json) ? $project->settings_json : [];
        $contextBlock = implode("\n", array_filter([
            'Story era: ' . trim((string) ($settings['story_era'] ?? '')),
            'Story genre: ' . trim((string) ($settings['story_genre'] ?? '')),
            'World context: ' . trim((string) ($settings['world_context'] ?? '')),
            'Forbidden elements: ' . trim((string) ($settings['forbidden_elements'] ?? '')),
            'Main character: ' . trim((string) ($project->main_character_name ?? '')),
            'Main character profile: ' . trim((string) ($project->main_character_profile ?? '')),
        ], static fn($line) => trim((string) $line) !== ''));

        $systemPrompt = 'You are a cinematic motion director for image-to-video generation.';
        $systemPrompt .= ' Analyze the provided image(s), then suggest grounded motion that preserves the exact visual identity and composition.';
        $systemPrompt .= ' Never add objects/characters not present in the image.';
        $systemPrompt .= ' Return ONLY valid JSON with keys: suggested_motion_en, refined_prompt_en, refined_prompt_vi, camera_plan.';

        $userPrompt = "Animation mode: {$mode}\n";
        $userPrompt .= "Preferred camera angle: {$cameraAngle}\n";
        if ($instruction !== '') {
            $userPrompt .= "User request: {$instruction}\n";
        }
        if ($currentVideoPrompt !== '') {
            $userPrompt .= "Current video prompt: {$currentVideoPrompt}\n";
        }
        $userPrompt .= "Locked context:\n{$contextBlock}\n";
        $userPrompt .= "Output JSON schema: {\"suggested_motion_en\":\"...\",\"refined_prompt_en\":\"...\",\"refined_prompt_vi\":\"...\",\"camera_plan\":\"...\"}";

        $parts = [
            ['text' => $systemPrompt . "\n\n" . $userPrompt],
        ];

        foreach (array_slice($selectedImagePaths, 0, 4) as $path) {
            $geminiPart = $this->buildGeminiInlineImagePart($path);
            if ($geminiPart !== null) {
                $parts[] = $geminiPart;
            }
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . urlencode($apiKey);
        $response = Http::timeout(90)->post($url, [
            'contents' => [[
                'parts' => $parts,
            ]],
            'generationConfig' => [
                'temperature' => 0.45,
                'maxOutputTokens' => 1800,
            ],
        ]);

        if (!$response->successful()) {
            return response()->json([
                'success' => false,
                'message' => 'AI suggest motion that bai: HTTP ' . $response->status(),
            ], 422);
        }

        $rawText = (string) data_get($response->json(), 'candidates.0.content.parts.0.text', '');
        $parsed = $this->extractJsonObject($rawText);

        $suggestedMotion = trim((string) ($parsed['suggested_motion_en'] ?? ''));
        $refinedPromptEn = trim((string) ($parsed['refined_prompt_en'] ?? ''));
        $refinedPromptVi = trim((string) ($parsed['refined_prompt_vi'] ?? ''));
        $cameraPlan = trim((string) ($parsed['camera_plan'] ?? ''));

        if ($refinedPromptEn === '') {
            return response()->json([
                'success' => false,
                'message' => 'AI khong tra ve refined_prompt_en hop le.',
            ], 422);
        }

        $this->addProjectUsageCost(
            $project,
            'ai_generation',
            $this->estimateAiGenerationCostUsd(mb_strlen($currentVideoPrompt) + mb_strlen($instruction) + (count($selectedImagePaths) * 200))
        );

        return response()->json([
            'success' => true,
            'mode' => $mode,
            'camera_angle' => $cameraAngle,
            'selected_images' => $selectedImagePaths,
            'suggested_motion_en' => $suggestedMotion,
            'refined_prompt_en' => $refinedPromptEn,
            'refined_prompt_vi' => $refinedPromptVi,
            'camera_plan' => $cameraPlan,
        ]);
    }

    public function sentenceGenerationStatus(Request $request, MediaCenterProject $project, MediaCenterSentence $sentence): JsonResponse
    {
        $this->authorizeSentence($project, $sentence);

        $metadata = is_array($sentence->metadata_json) ? $sentence->metadata_json : [];
        $generation = is_array($metadata['generation'] ?? null) ? $metadata['generation'] : [];
        $animation = is_array($generation['animation'] ?? null) ? $generation['animation'] : [];
        $image = is_array($generation['image'] ?? null) ? $generation['image'] : [];
        $animationItemsRaw = is_array($metadata['animations'] ?? null) ? $metadata['animations'] : [];
        $animationItems = [];
        foreach ($animationItemsRaw as $item) {
            if (!is_array($item)) {
                continue;
            }

            $path = trim((string) ($item['path'] ?? ''));
            if ($path === '') {
                continue;
            }

            $animationItems[] = [
                'path' => $path,
                'url' => Storage::url($path),
                'provider' => trim((string) ($item['provider'] ?? '')),
                'mode' => trim((string) ($item['mode'] ?? '')),
                'frame_index' => (int) ($item['frame_index'] ?? 0),
                'frame_total' => (int) ($item['frame_total'] ?? 1),
            ];
        }

        return response()->json([
            'success' => true,
            'generation' => [
                'animation' => $animation,
                'image' => $image,
            ],
            'animation_items' => $animationItems,
            'sentence' => [
                'id' => (int) $sentence->id,
                'sentence_index' => (int) $sentence->sentence_index,
            ],
        ]);
    }

    public function translateSentenceField(Request $request, MediaCenterProject $project, MediaCenterSentence $sentence, TranslationService $translationService): JsonResponse
    {
        $this->authorizeSentence($project, $sentence);

        $data = $request->validate([
            'text' => 'required|string|max:20000',
        ]);

        $sourceText = trim((string) $data['text']);
        if ($sourceText === '') {
            return response()->json([
                'success' => false,
                'message' => 'Không có nội dung để dịch.',
            ], 422);
        }

        $translated = $translationService->translateText($sourceText, 'en', 'vi');
        $this->addProjectUsageCost($project, 'ai_generation', $this->estimateAiGenerationCostUsd(mb_strlen($sourceText)));

        return response()->json([
            'success' => true,
            'translated_text' => $translated,
        ]);
    }

    public function rewriteSentenceFieldBilingual(Request $request, MediaCenterProject $project, MediaCenterSentence $sentence, TranslationService $translationService): JsonResponse
    {
        $this->authorizeSentence($project, $sentence);

        $data = $request->validate([
            'field' => 'required|string|in:notes,image,video',
            'source_text' => 'required|string|max:20000',
            'translated_text' => 'nullable|string|max:20000',
            'instruction' => 'required|string|max:2000',
        ]);

        $field = trim((string) $data['field']);
        $alsoRewriteVideo = $field === 'image';
        $sourceText = trim((string) $data['source_text']);
        $translatedText = trim((string) ($data['translated_text'] ?? ''));
        $instruction = trim((string) $data['instruction']);

        if ($sourceText === '' || $instruction === '') {
            return response()->json([
                'success' => false,
                'message' => 'Thiếu nội dung nguồn hoặc yêu cầu chỉnh sửa.',
            ], 422);
        }

        $apiKey = (string) config('services.gemini.api_key', '');
        if ($apiKey === '') {
            return response()->json([
                'success' => false,
                'message' => 'Chưa cấu hình GEMINI_API_KEY để AI viết lại.',
            ], 422);
        }

        $fieldTitle = match ($field) {
            'notes' => 'Character notes',
            'image' => 'Prompt tạo ảnh',
            'video' => 'Prompt tạo video',
            default => 'Nội dung',
        };

        $settings = is_array($project->settings_json) ? $project->settings_json : [];
        $systemPrompt = "Bạn là biên tập viên AI cho Media Center. Nhiệm vụ: viết lại nội dung theo yêu cầu người dùng nhưng PHẢI giữ nguyên bối cảnh và setting đã định.";
        $systemPrompt .= "\nYêu cầu bắt buộc:";
        $systemPrompt .= "\n1) Không phá vỡ thời đại, thể loại, bối cảnh, nhân vật và các yếu tố cấm.";
        $systemPrompt .= "\n2) Giữ mục tiêu trường nội dung gốc ({$fieldTitle}).";
        if ($alsoRewriteVideo) {
            $systemPrompt .= "\n2.1) Vì đang chỉnh Prompt tạo ảnh, PHẢI tạo thêm Prompt tạo video mới bám sát prompt ảnh vừa viết lại.";
        }
        $systemPrompt .= "\n3) Trả về JSON hợp lệ, không markdown, không giải thích thêm.";
        $systemPrompt .= $alsoRewriteVideo
            ? "\n4) JSON phải có 4 key: rewritten_en, rewritten_vi, related_video_en, related_video_vi."
            : "\n4) JSON phải có 2 key: rewritten_en, rewritten_vi.";

        $contextLines = [
            'Story era: ' . trim((string) ($settings['story_era'] ?? '')),
            'Story genre: ' . trim((string) ($settings['story_genre'] ?? '')),
            'World context: ' . trim((string) ($settings['world_context'] ?? '')),
            'Forbidden elements: ' . trim((string) ($settings['forbidden_elements'] ?? '')),
            'Image style: ' . trim((string) ($settings['image_style'] ?? '')),
            'Image aspect ratio: ' . trim((string) ($settings['image_aspect_ratio'] ?? '')),
            'Main character name: ' . trim((string) ($project->main_character_name ?? '')),
            'Main character profile: ' . trim((string) ($project->main_character_profile ?? '')),
        ];

        $characters = is_array($project->characters_json) ? $project->characters_json : [];
        $characterNames = [];
        foreach ($characters as $character) {
            if (!is_array($character)) {
                continue;
            }
            $name = trim((string) ($character['name'] ?? ''));
            if ($name !== '') {
                $characterNames[] = $name;
            }
        }
        if (!empty($characterNames)) {
            $contextLines[] = 'Supporting characters: ' . implode(', ', $characterNames);
        }

        $contextBlock = implode("\n", array_filter($contextLines, static fn($line) => trim((string) $line) !== ''));

        $userPrompt = "Field: {$fieldTitle}\n";
        $userPrompt .= "Original English:\n{$sourceText}\n\n";
        if ($translatedText !== '') {
            $userPrompt .= "Current Vietnamese translation:\n{$translatedText}\n\n";
        }
        $userPrompt .= "User rewrite request:\n{$instruction}\n\n";
        $userPrompt .= "Locked project context:\n{$contextBlock}\n\n";
        if ($alsoRewriteVideo) {
            $userPrompt .= "Current video prompt (EN):\n" . trim((string) ($sentence->video_prompt ?? '')) . "\n\n";
            $currentVideoVi = trim((string) (data_get($sentence->metadata_json, 'translations.video.vi', '')));
            if ($currentVideoVi !== '') {
                $userPrompt .= "Current video prompt (VI):\n{$currentVideoVi}\n\n";
            }
            $userPrompt .= "Output JSON schema:\n{\"rewritten_en\":\"...\",\"rewritten_vi\":\"...\",\"related_video_en\":\"...\",\"related_video_vi\":\"...\"}";
        } else {
            $userPrompt .= "Output JSON schema:\n{\"rewritten_en\":\"...\",\"rewritten_vi\":\"...\"}";
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . urlencode($apiKey);

        $response = Http::timeout(60)->post($url, [
            'contents' => [[
                'parts' => [[
                    'text' => $systemPrompt . "\n\n" . $userPrompt,
                ]],
            ]],
            'generationConfig' => [
                'temperature' => 0.4,
                'maxOutputTokens' => 1400,
            ],
        ]);

        if (!$response->successful()) {
            return response()->json([
                'success' => false,
                'message' => 'AI rewrite thất bại: HTTP ' . $response->status(),
            ], 422);
        }

        $payload = $response->json();
        $rawText = (string) data_get($payload, 'candidates.0.content.parts.0.text', '');
        $parsed = $this->extractJsonObject($rawText);

        $rewrittenEn = trim((string) ($parsed['rewritten_en'] ?? ''));
        $rewrittenVi = trim((string) ($parsed['rewritten_vi'] ?? ''));
        $relatedVideoEn = trim((string) ($parsed['related_video_en'] ?? ''));
        $relatedVideoVi = trim((string) ($parsed['related_video_vi'] ?? ''));

        if ($rewrittenEn === '' || $rewrittenVi === '') {
            return response()->json([
                'success' => false,
                'message' => 'AI không trả về đủ rewritten_en và rewritten_vi.',
            ], 422);
        }

        if ($alsoRewriteVideo) {
            if ($relatedVideoEn === '') {
                $relatedVideoEn = 'Cinematic moving shot based on this scene: ' . $rewrittenEn;
            }
            if ($relatedVideoVi === '') {
                $relatedVideoVi = $translationService->translateText($relatedVideoEn, 'en', 'vi');
            }
        }

        $column = match ($field) {
            'notes' => 'character_notes',
            'image' => 'image_prompt',
            'video' => 'video_prompt',
            default => null,
        };

        if ($column === null) {
            return response()->json([
                'success' => false,
                'message' => 'Field không hợp lệ.',
            ], 422);
        }

        $metadata = is_array($sentence->metadata_json) ? $sentence->metadata_json : [];
        $translations = is_array($metadata['translations'] ?? null) ? $metadata['translations'] : [];
        $translations[$field] = [
            'vi' => $rewrittenVi,
            'updated_at' => now()->toIso8601String(),
        ];
        if ($alsoRewriteVideo) {
            $translations['video'] = [
                'vi' => $relatedVideoVi,
                'updated_at' => now()->toIso8601String(),
            ];
        }
        $metadata['translations'] = $translations;

        $updates = [
            $column => $rewrittenEn,
            'metadata_json' => $metadata,
        ];

        if ($alsoRewriteVideo) {
            $updates['video_prompt'] = $relatedVideoEn;
        }

        $sentence->forceFill($updates)->save();
        $this->addProjectUsageCost($project, 'ai_generation', $this->estimateAiGenerationCostUsd(mb_strlen($sourceText) + mb_strlen($instruction)));

        return response()->json([
            'success' => true,
            'field' => $field,
            'rewritten_en' => $rewrittenEn,
            'rewritten_vi' => $rewrittenVi,
            'related_video_en' => $alsoRewriteVideo ? $relatedVideoEn : null,
            'related_video_vi' => $alsoRewriteVideo ? $relatedVideoVi : null,
            'sentence' => $sentence->fresh(),
        ]);
    }

    public function translateMainCharacterProfile(Request $request, MediaCenterProject $project, TranslationService $translationService): JsonResponse
    {
        $this->authorizeProject($project);

        $data = $request->validate([
            'text' => 'required|string|max:20000',
        ]);

        $sourceText = trim((string) ($data['text'] ?? ''));
        if ($sourceText === '') {
            return response()->json([
                'success' => false,
                'message' => 'Khong co noi dung de dich.',
            ], 422);
        }

        $translated = $translationService->translateText($sourceText, 'en', 'vi');
        $this->addProjectUsageCost($project, 'ai_generation', $this->estimateAiGenerationCostUsd(mb_strlen($sourceText)));

        $settings = is_array($project->settings_json) ? $project->settings_json : [];
        $translations = is_array($settings['translations'] ?? null) ? $settings['translations'] : [];
        $translations['main_character_profile'] = [
            'en' => $sourceText,
            'vi' => $translated,
            'updated_at' => now()->toIso8601String(),
        ];
        $settings['translations'] = $translations;

        $project->forceFill([
            'settings_json' => $settings,
        ])->save();

        return response()->json([
            'success' => true,
            'translated_text' => $translated,
            'project' => $project->fresh(),
        ]);
    }

    public function rewriteMainCharacterProfileBilingual(Request $request, MediaCenterProject $project, TranslationService $translationService): JsonResponse
    {
        $this->authorizeProject($project);

        $data = $request->validate([
            'source_text' => 'required|string|max:20000',
            'translated_text' => 'nullable|string|max:20000',
            'instruction' => 'required|string|max:2000',
        ]);

        $sourceText = trim((string) ($data['source_text'] ?? ''));
        $translatedText = trim((string) ($data['translated_text'] ?? ''));
        $instruction = trim((string) ($data['instruction'] ?? ''));

        if ($sourceText === '' || $instruction === '') {
            return response()->json([
                'success' => false,
                'message' => 'Thieu noi dung mo ta hoac yeu cau chinh sua.',
            ], 422);
        }

        $apiKey = (string) config('services.gemini.api_key', '');
        if ($apiKey === '') {
            return response()->json([
                'success' => false,
                'message' => 'Chua cau hinh GEMINI_API_KEY de AI viet lai.',
            ], 422);
        }

        $settings = is_array($project->settings_json) ? $project->settings_json : [];
        $systemPrompt = "Ban la bien tap vien AI cho Media Center. Nhiem vu: viet lai Main character profile theo yeu cau nguoi dung.";
        $systemPrompt .= "\nYeu cau bat buoc:";
        $systemPrompt .= "\n1) Giu nguyen boi canh, thoi dai, the loai, tinh cach cot loi cua nhan vat.";
        $systemPrompt .= "\n2) Khong tao mau thuan voi world context va forbidden elements.";
        $systemPrompt .= "\n3) Tra ve JSON hop le, khong markdown, khong giai thich them.";
        $systemPrompt .= "\n4) JSON phai co 2 key: rewritten_en, rewritten_vi.";

        $contextLines = [
            'Story era: ' . trim((string) ($settings['story_era'] ?? '')),
            'Story genre: ' . trim((string) ($settings['story_genre'] ?? '')),
            'World context: ' . trim((string) ($settings['world_context'] ?? '')),
            'Forbidden elements: ' . trim((string) ($settings['forbidden_elements'] ?? '')),
            'Image style: ' . trim((string) ($settings['image_style'] ?? '')),
            'Main character name: ' . trim((string) ($project->main_character_name ?? '')),
        ];
        $contextBlock = implode("\n", array_filter($contextLines, static fn($line) => trim((string) $line) !== ''));

        $userPrompt = "Field: Main character profile\n";
        $userPrompt .= "Original English:\n{$sourceText}\n\n";
        if ($translatedText !== '') {
            $userPrompt .= "Current Vietnamese translation:\n{$translatedText}\n\n";
        }
        $userPrompt .= "User rewrite request:\n{$instruction}\n\n";
        $userPrompt .= "Locked project context:\n{$contextBlock}\n\n";
        $userPrompt .= "Output JSON schema:\n{\"rewritten_en\":\"...\",\"rewritten_vi\":\"...\"}";

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . urlencode($apiKey);

        $response = Http::timeout(60)->post($url, [
            'contents' => [[
                'parts' => [[
                    'text' => $systemPrompt . "\n\n" . $userPrompt,
                ]],
            ]],
            'generationConfig' => [
                'temperature' => 0.4,
                'maxOutputTokens' => 1200,
            ],
        ]);

        if (!$response->successful()) {
            return response()->json([
                'success' => false,
                'message' => 'AI rewrite that bai: HTTP ' . $response->status(),
            ], 422);
        }

        $payload = $response->json();
        $rawText = (string) data_get($payload, 'candidates.0.content.parts.0.text', '');
        $parsed = $this->extractJsonObject($rawText);

        $rewrittenEn = trim((string) ($parsed['rewritten_en'] ?? ''));
        $rewrittenVi = trim((string) ($parsed['rewritten_vi'] ?? ''));

        if ($rewrittenEn === '' || $rewrittenVi === '') {
            return response()->json([
                'success' => false,
                'message' => 'AI khong tra ve du rewritten_en va rewritten_vi.',
            ], 422);
        }

        $translations = is_array($settings['translations'] ?? null) ? $settings['translations'] : [];
        $translations['main_character_profile'] = [
            'en' => $rewrittenEn,
            'vi' => $rewrittenVi,
            'updated_at' => now()->toIso8601String(),
        ];
        $settings['translations'] = $translations;

        $project->forceFill([
            'main_character_profile' => $rewrittenEn,
            'settings_json' => $settings,
        ])->save();
        $this->addProjectUsageCost($project, 'ai_generation', $this->estimateAiGenerationCostUsd(mb_strlen($sourceText) + mb_strlen($instruction)));

        return response()->json([
            'success' => true,
            'rewritten_en' => $rewrittenEn,
            'rewritten_vi' => $rewrittenVi,
            'project' => $project->fresh(),
        ]);
    }

    public function deleteSentenceImage(Request $request, MediaCenterProject $project, MediaCenterSentence $sentence): JsonResponse
    {
        $this->authorizeSentence($project, $sentence);

        $data = $request->validate([
            'image_path' => 'required|string|max:500',
        ]);

        $imagePath = trim((string) $data['image_path']);
        if (!$this->isSentenceImagePathAllowed($project, $sentence, $imagePath)) {
            return response()->json([
                'success' => false,
                'message' => 'Đường dẫn ảnh không hợp lệ cho câu này.',
            ], 422);
        }

        $allPaths = $this->extractImagePathsFromSentence($sentence);
        if (!in_array($imagePath, $allPaths, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy ảnh trong danh sách của câu.',
            ], 404);
        }

        $this->deletePublicStorageFile($imagePath);

        $remaining = array_values(array_filter($allPaths, static fn($path) => $path !== $imagePath));

        $metadata = is_array($sentence->metadata_json) ? $sentence->metadata_json : [];
        $metadata['image_paths'] = $remaining;

        $sentence->forceFill([
            'image_path' => $remaining[0] ?? null,
            'metadata_json' => $metadata,
        ])->save();

        return response()->json([
            'success' => true,
            'remaining_paths' => $remaining,
            'sentence' => $sentence->fresh(),
        ]);
    }

    public function deleteSentenceAnimation(Request $request, MediaCenterProject $project, MediaCenterSentence $sentence): JsonResponse
    {
        $this->authorizeSentence($project, $sentence);

        $data = $request->validate([
            'animation_path' => 'required|string|max:500',
        ]);

        $animationPath = trim((string) ($data['animation_path'] ?? ''));
        if (!$this->isSentenceAnimationPathAllowed($project, $sentence, $animationPath)) {
            return response()->json([
                'success' => false,
                'message' => 'Duong dan clip animation khong hop le cho cau nay.',
            ], 422);
        }

        $metadata = is_array($sentence->metadata_json) ? $sentence->metadata_json : [];
        $animationItems = is_array($metadata['animations'] ?? null) ? $metadata['animations'] : [];

        $found = false;
        $remainingItems = [];
        foreach ($animationItems as $item) {
            if (!is_array($item)) {
                continue;
            }

            $path = trim((string) ($item['path'] ?? ''));
            if ($path === $animationPath) {
                $found = true;
                continue;
            }

            $remainingItems[] = $item;
        }

        if (!$found) {
            return response()->json([
                'success' => false,
                'message' => 'Khong tim thay clip animation can xoa trong danh sach cau nay.',
            ], 404);
        }

        $this->deletePublicStorageFile($animationPath);

        $metadata['animations'] = array_values($remainingItems);
        $sentence->forceFill([
            'metadata_json' => $metadata,
        ])->save();

        $animationItemsResponse = [];
        foreach ($metadata['animations'] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $path = trim((string) ($item['path'] ?? ''));
            if ($path === '') {
                continue;
            }

            $animationItemsResponse[] = [
                'path' => $path,
                'url' => Storage::url($path),
                'provider' => trim((string) ($item['provider'] ?? '')),
                'mode' => trim((string) ($item['mode'] ?? '')),
                'frame_index' => (int) ($item['frame_index'] ?? 0),
                'frame_total' => (int) ($item['frame_total'] ?? 1),
            ];
        }

        return response()->json([
            'success' => true,
            'remaining' => count($animationItemsResponse),
            'animation_items' => $animationItemsResponse,
            'sentence' => $sentence->fresh(),
        ]);
    }

    public function generateAll(Request $request, MediaCenterProject $project, TTSService $ttsService): JsonResponse
    {
        $this->authorizeProject($project);

        $data = $request->validate([
            'run_tts' => 'nullable|boolean',
            'run_images' => 'nullable|boolean',
            'tts_provider' => 'nullable|string|max:30',
            'image_provider' => 'nullable|string|max:30',
            'voice_gender' => 'nullable|string|max:20',
            'voice_name' => 'nullable|string|max:120',
            'speed' => 'nullable|numeric|min:0.5|max:2',
            'sentence_ids' => 'nullable|array',
            'sentence_ids.*' => 'integer|min:1',
        ]);

        $runTts = (bool) ($data['run_tts'] ?? true);
        $runImages = (bool) ($data['run_images'] ?? true);

        $updated = 0;
        $errors = [];

        $selectedSentenceIds = [];
        if (is_array($data['sentence_ids'] ?? null)) {
            $selectedSentenceIds = array_values(array_unique(array_map(static fn($id) => (int) $id, $data['sentence_ids'])));
            $selectedSentenceIds = array_values(array_filter($selectedSentenceIds, static fn($id) => $id > 0));
        }

        $sentencesQuery = $project->sentences();
        if (!empty($selectedSentenceIds)) {
            $sentencesQuery->whereIn('id', $selectedSentenceIds);
        }

        $sentences = $sentencesQuery->get();
        if ($sentences->isEmpty()) {
            return response()->json([
                'success' => false,
                'updated' => 0,
                'errors' => [],
                'message' => !empty($selectedSentenceIds)
                    ? 'Khong tim thay cau hop le trong danh sach duoc chon.'
                    : 'Project chua co cau de xu ly.',
            ], 422);
        }
        $settings = is_array($project->settings_json) ? $project->settings_json : [];
        $aspectRatio = trim((string) ($settings['image_aspect_ratio'] ?? '16:9'));
        if (!in_array($aspectRatio, ['16:9', '9:16', '1:1'], true)) {
            $aspectRatio = '16:9';
        }

        foreach ($sentences as $sentence) {
            try {
                if ($runTts) {
                    $ttsProvider = trim((string) ($data['tts_provider'] ?? $sentence->tts_provider ?: 'google'));
                    $voiceGender = trim((string) ($data['voice_gender'] ?? $sentence->tts_voice_gender ?: 'female'));
                    $voiceName = trim((string) ($data['voice_name'] ?? $sentence->tts_voice_name ?? '')) ?: null;
                    $speed = (float) ($data['speed'] ?? ($sentence->tts_speed ?: 1.0));

                    $storagePath = $ttsService->generateAudio(
                        (string) ($sentence->tts_text ?: $sentence->sentence_text),
                        (int) $sentence->sentence_index,
                        $voiceGender,
                        $voiceName,
                        $ttsProvider,
                        null,
                        null,
                        $speed
                    );

                    $sentence->tts_provider = $ttsProvider;
                    $sentence->tts_voice_gender = $voiceGender;
                    $sentence->tts_voice_name = $voiceName;
                    $sentence->tts_speed = $speed;
                    $sentence->tts_audio_path = $storagePath;

                    $ttsChars = mb_strlen((string) ($sentence->tts_text ?: $sentence->sentence_text));
                    $this->addProjectUsageCost($project, 'audio', $this->estimateTtsCostUsd($ttsProvider, $ttsChars));
                }

                if ($runImages) {
                    $basePrompt = trim((string) ($sentence->image_prompt ?: ''));
                    if ($basePrompt !== '') {
                        $imgProvider = $this->normalizeImageProvider((string) ($data['image_provider'] ?? $sentence->image_provider ?: 'gemini'));

                        $metadata = is_array($sentence->metadata_json) ? $sentence->metadata_json : [];
                        $metadata = $this->setSentenceGenerationState($metadata, 'image', 'queued', 'Da vao hang doi tao anh.', [
                            'provider' => $imgProvider,
                            'queued_at' => now()->toIso8601String(),
                        ]);
                        $sentence->metadata_json = $metadata;
                        $sentence->save();

                        GenerateMediaCenterSentenceAssetJob::dispatch((int) $project->id, (int) $sentence->id, 'image', $imgProvider);
                    }
                }

                $sentence->save();
                $updated++;
            } catch (\Throwable $e) {
                $errors[] = [
                    'sentence_index' => $sentence->sentence_index,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'success' => true,
            'updated' => $updated,
            'errors' => $errors,
            'selected_count' => !empty($selectedSentenceIds) ? count($sentences) : null,
            'message' => $runImages ? 'Cac tac vu tao anh da duoc dua vao queue backend (gioi han 5 worker dong thoi).' : 'Da xu ly xong.',
            'project' => $project->fresh(['sentences']),
        ]);
    }

    public function downloadAllSentenceAssets(Request $request, MediaCenterProject $project): JsonResponse|BinaryFileResponse
    {
        $this->authorizeProject($project);

        $sentences = $project->sentences()
            ->orderBy('sentence_index')
            ->get(['id', 'sentence_index', 'tts_audio_path', 'image_path', 'metadata_json']);

        if ($sentences->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Project chua co cau nao de tai file.',
            ], 422);
        }

        $packageItems = [];
        $missingSentences = [];

        foreach ($sentences as $sentence) {
            $ttsPath = $this->resolveExistingPublicStoragePath((string) ($sentence->tts_audio_path ?? ''));

            $imagePaths = [];
            foreach ($this->extractImagePathsFromSentence($sentence) as $path) {
                $resolved = $this->resolveExistingPublicStoragePath($path);
                if ($resolved !== null && !in_array($resolved, $imagePaths, true)) {
                    $imagePaths[] = $resolved;
                }
            }

            $animationPaths = [];
            foreach ($this->extractAnimationPathsFromSentence($sentence) as $path) {
                $resolved = $this->resolveExistingPublicStoragePath($path);
                if ($resolved !== null && !in_array($resolved, $animationPaths, true)) {
                    $animationPaths[] = $resolved;
                }
            }

            $missing = [];
            if ($ttsPath === null) {
                $missing[] = 'tts';
            }
            if (empty($imagePaths)) {
                $missing[] = 'image';
            }
            if (empty($animationPaths)) {
                $missing[] = 'animation';
            }

            if (!empty($missing)) {
                $missingSentences[] = [
                    'sentence_id' => (int) $sentence->id,
                    'sentence_index' => (int) $sentence->sentence_index,
                    'missing' => $missing,
                ];
                continue;
            }

            $packageItems[] = [
                'sentence_index' => (int) $sentence->sentence_index,
                'tts_path' => $ttsPath,
                'image_paths' => $imagePaths,
                'animation_paths' => $animationPaths,
            ];
        }

        if (!empty($missingSentences)) {
            return response()->json([
                'success' => false,
                'message' => 'Co cau chua du TTS/Image/Animation, khong the tai full package.',
                'missing_sentences' => $missingSentences,
            ], 422);
        }

        if (empty($packageItems)) {
            return response()->json([
                'success' => false,
                'message' => 'Khong co file hop le de dong goi.',
            ], 422);
        }

        if (!class_exists(ZipArchive::class)) {
            return response()->json([
                'success' => false,
                'message' => 'Server chua ho tro ZipArchive, khong the tao file zip.',
            ], 500);
        }

        $tempDir = storage_path('app/temp');
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        $slug = Str::slug((string) ($project->title ?? 'media-center-project'));
        if ($slug === '') {
            $slug = 'media-center-project';
        }

        $timeTag = now()->format('Ymd_His');
        $zipBaseName = 'media-center-project-' . (int) $project->id . '-' . $slug . '-' . $timeTag . '.zip';
        $zipPath = $tempDir . DIRECTORY_SEPARATOR . $zipBaseName;

        $zip = new ZipArchive();
        $openResult = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($openResult !== true) {
            return response()->json([
                'success' => false,
                'message' => 'Khong the tao file zip de tai ve.',
            ], 500);
        }

        foreach ($packageItems as $item) {
            $sentenceIndex = (int) ($item['sentence_index'] ?? 0);
            $sentencePrefix = 's' . str_pad((string) $sentenceIndex, 3, '0', STR_PAD_LEFT) . '_';

            $ttsPath = (string) ($item['tts_path'] ?? '');
            $ttsAbsolute = storage_path('app/public/' . $ttsPath);
            if (is_file($ttsAbsolute)) {
                $zip->addFile($ttsAbsolute, 'tts/' . $sentencePrefix . basename($ttsPath));
            }

            $imagePaths = is_array($item['image_paths'] ?? null) ? $item['image_paths'] : [];
            foreach ($imagePaths as $imagePath) {
                $path = trim((string) $imagePath);
                if ($path === '') {
                    continue;
                }
                $absolute = storage_path('app/public/' . $path);
                if (!is_file($absolute)) {
                    continue;
                }
                $zip->addFile($absolute, 'images/' . $sentencePrefix . basename($path));
            }

            $animationPaths = is_array($item['animation_paths'] ?? null) ? $item['animation_paths'] : [];
            foreach ($animationPaths as $animationPath) {
                $path = trim((string) $animationPath);
                if ($path === '') {
                    continue;
                }
                $absolute = storage_path('app/public/' . $path);
                if (!is_file($absolute)) {
                    continue;
                }
                $zip->addFile($absolute, 'animations/' . $sentencePrefix . basename($path));
            }
        }

        $zip->close();

        $settings = is_array($project->settings_json) ? $project->settings_json : [];
        $nowIso = now()->toIso8601String();
        if (empty($settings['download_all_assets_first_at'])) {
            $settings['download_all_assets_first_at'] = $nowIso;
        }
        $settings['download_all_assets_last_at'] = $nowIso;
        $project->forceFill([
            'settings_json' => $settings,
        ])->save();

        return response()->download($zipPath, $zipBaseName)->deleteFileAfterSend(true);
    }

    public function regenerateWeakPrompts(Request $request, MediaCenterProject $project, MediaCenterAnalyzeService $analyzeService): JsonResponse
    {
        $this->authorizeProject($project);

        $data = $request->validate([
            'sentence_ids' => 'required|array|min:1|max:200',
            'sentence_ids.*' => 'integer|min:1',
        ]);

        $requestedIds = collect($data['sentence_ids'] ?? [])
            ->map(static fn ($id) => (int) $id)
            ->filter(static fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if (empty($requestedIds)) {
            return response()->json([
                'success' => false,
                'message' => 'Vui long chon it nhat 1 cau de regenerate prompt.',
            ], 422);
        }

        $sentences = $project->sentences()
            ->whereIn('id', $requestedIds)
            ->orderBy('sentence_index')
            ->get();

        $totalSelected = count($requestedIds);
        if ($sentences->isEmpty()) {
            return response()->json([
                'success' => true,
                'updated' => 0,
                'skipped' => 0,
                'selected_total' => $totalSelected,
                'updated_sentences' => [],
                'message' => 'Khong tim thay cau hop le trong danh sach da chon.',
            ]);
        }

        $mainCharacterSeed = [];
        if (!empty($project->main_character_name)) {
            $mainCharacterSeed['name'] = (string) $project->main_character_name;
        }

        foreach ($sentences as $candidate) {
            $metadata = is_array($candidate->metadata_json) ? $candidate->metadata_json : [];
            $metaMain = is_array($metadata['main_character'] ?? null) ? $metadata['main_character'] : [];
            if (!empty($metaMain)) {
                $mainCharacterSeed = array_merge($metaMain, $mainCharacterSeed);
                break;
            }
        }

        $updated = 0;
        $skipped = 0;
        $updatedSentences = [];

        foreach ($sentences as $sentence) {
            $result = $analyzeService->regeneratePromptForSentenceByStoryContext($project, $sentence, $mainCharacterSeed);
            $plan = is_array($result['plan'] ?? null) ? $result['plan'] : null;
            if (!$plan) {
                $skipped++;
                continue;
            }

            $changed = (bool) ($result['changed'] ?? false);
            if (!$changed) {
                $skipped++;
                continue;
            }

            $sentence->forceFill([
                'tts_text' => (string) ($plan['tts_text'] ?? $sentence->tts_text ?? $sentence->sentence_text),
                'image_prompt' => (string) ($plan['image_prompt'] ?? $sentence->image_prompt),
                'video_prompt' => (string) ($plan['video_prompt'] ?? $sentence->video_prompt),
                'character_notes' => (string) ($plan['character_notes'] ?? $sentence->character_notes),
            ])->save();

            $updated++;
            $updatedSentences[] = [
                'id' => (int) $sentence->id,
                'sentence_index' => (int) $sentence->sentence_index,
                'tts_text' => (string) ($plan['tts_text'] ?? ''),
                'image_prompt' => (string) ($plan['image_prompt'] ?? ''),
                'video_prompt' => (string) ($plan['video_prompt'] ?? ''),
                'character_notes' => (string) ($plan['character_notes'] ?? ''),
            ];
        }

        return response()->json([
            'success' => true,
            'updated' => $updated,
            'skipped' => $skipped,
            'selected_total' => $totalSelected,
            'updated_sentences' => $updatedSentences,
            'message' => $updated > 0
                ? "Da regenerate {$updated}/{$totalSelected} cau theo ngu canh cau chuyen."
                : 'Khong co cau nao thay doi sau khi regenerate theo ngu canh.',
        ]);
    }

    /**
     * @return array<int,string>
     */
    private function splitSentences(string $text): array
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($text)) ?: '';
        if ($normalized === '') {
            return [];
        }

        $chunks = preg_split('/(?<=[\.\!\?])\s+/u', $normalized) ?: [];
        $sentences = [];
        foreach ($chunks as $chunk) {
            $sentence = trim((string) $chunk);
            if ($sentence === '') {
                continue;
            }
            $sentences[] = $sentence;
        }

        return $sentences;
    }

    private function hydrateSentenceImageGalleryPaths(MediaCenterProject $project): void
    {
        $directory = 'media_center/' . (int) $project->id . '/images';
        $disk = Storage::disk('public');
        $groupedPaths = [];

        if ($disk->exists($directory)) {
            $allFiles = $disk->files($directory);
            foreach ($allFiles as $filePath) {
                $name = basename($filePath);
                if (!preg_match('/^s(\d+)_.*\.(png|jpg|jpeg|webp)$/i', $name, $matches)) {
                    continue;
                }

                $sentenceIndex = (int) ($matches[1] ?? 0);
                if ($sentenceIndex <= 0) {
                    continue;
                }

                $groupedPaths[$sentenceIndex] ??= [];
                $groupedPaths[$sentenceIndex][] = $filePath;
            }
        }

        foreach ($project->sentences as $sentence) {
            $metadata = is_array($sentence->metadata_json) ? $sentence->metadata_json : [];
            $paths = $this->normalizeImagePathsArray($metadata['image_paths'] ?? []);

            $mainPath = trim((string) ($sentence->image_path ?? ''));
            if ($mainPath !== '' && !in_array($mainPath, $paths, true)) {
                $paths[] = $mainPath;
            }

            $diskPaths = $groupedPaths[(int) $sentence->sentence_index] ?? [];
            sort($diskPaths);
            foreach ($diskPaths as $diskPath) {
                if (!in_array($diskPath, $paths, true)) {
                    $paths[] = $diskPath;
                }
            }

            $metadata['image_paths'] = $paths;
            $sentence->setAttribute('metadata_json', $metadata);
        }
    }

    // Analysis logic moved to MediaCenterAnalyzeService and AnalyzeMediaCenterProjectJob.

    private function authorizeProject(MediaCenterProject $project): void
    {
        if (Auth::check() && $project->user_id !== null && (int) $project->user_id !== (int) Auth::id()) {
            abort(403);
        }
    }

    private function authorizeSentence(MediaCenterProject $project, MediaCenterSentence $sentence): void
    {
        $this->authorizeProject($project);
        if ((int) $sentence->media_center_project_id !== (int) $project->id) {
            abort(404);
        }
    }

    private function storagePathToUrl(string $storagePath): string
    {
        if (str_starts_with($storagePath, 'public/')) {
            return '/storage/' . ltrim(substr($storagePath, 7), '/');
        }

        return Storage::url($storagePath);
    }

    private function normalizeImageProvider(string $provider): string
    {
        $normalized = strtolower(trim($provider));

        if (in_array($normalized, ['flux-1.1-pro', 'flux-1_1-pro', 'flux11pro', 'flux 1.1 pro'], true)) {
            return 'flux-1.1-pro';
        }

        if (in_array($normalized, ['flux-pro', 'flux pro'], true)) {
            return 'flux-pro';
        }

        if ($normalized === 'flux') {
            return 'flux';
        }

        if (in_array($normalized, ['gemini-nano-banana-pro', 'nano-banana-pro', 'gemini-nano-banana', 'nanobanana'], true)) {
            return 'gemini-nano-banana-pro';
        }

        return 'gemini';
    }

    private function normalizeAnimationProvider(string $provider): string
    {
        $normalized = strtolower(trim($provider));

        if (in_array($normalized, ['seedance', 'seedance-ai', 'seedance ai'], true)) {
            return 'seedance';
        }

        return 'kling';
    }

    private function normalizeAnimationMode(string $mode): string
    {
        $normalized = strtolower(trim($mode));

        return match ($normalized) {
            'image-to-cinematic-shot', 'cinematic-shot', 'cinematic shot' => 'image-to-cinematic-shot',
            'image-to-action', 'action' => 'image-to-action',
            'image-to-story-sequence', 'story-sequence', 'story sequence', 'multi-frame' => 'image-to-story-sequence',
            'image-to-character-animation', 'character-animation', 'character animation', 'lip-sync' => 'image-to-character-animation',
            default => 'image-to-motion',
        };
    }

    private function normalizeCameraAngle(string $angle): string
    {
        $normalized = strtolower(trim($angle));
        if ($normalized === '') {
            return 'auto';
        }

        $allowed = [
            'auto',
            'close-up',
            'medium-shot',
            'wide-shot',
            'over-the-shoulder',
            'low-angle',
            'high-angle',
            'top-down',
            'dolly-in',
            'dolly-out',
            'pan-left',
            'pan-right',
            'tracking-shot',
        ];

        return in_array($normalized, $allowed, true) ? $normalized : 'auto';
    }

    /**
     * @param mixed $raw
     * @param array<int,string> $fallbackPaths
     * @return array<int,string>
     */
    private function filterSelectedImagePaths(MediaCenterProject $project, MediaCenterSentence $sentence, $raw, array $fallbackPaths = []): array
    {
        $selected = [];
        if (is_array($raw)) {
            foreach ($raw as $item) {
                $path = trim((string) $item);
                if ($path === '') {
                    continue;
                }

                if (!$this->isSentenceImagePathAllowed($project, $sentence, $path)) {
                    continue;
                }

                if (!empty($fallbackPaths) && !in_array($path, $fallbackPaths, true)) {
                    continue;
                }

                if (!in_array($path, $selected, true)) {
                    $selected[] = $path;
                }
            }
        }

        return $selected;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function buildGeminiInlineImagePart(string $storagePath): ?array
    {
        $normalized = ltrim(trim($storagePath), '/');
        if ($normalized === '') {
            return null;
        }

        $absolutePath = storage_path('app/public/' . $normalized);
        if (!is_file($absolutePath)) {
            return null;
        }

        $binary = @file_get_contents($absolutePath);
        if ($binary === false || $binary === '') {
            return null;
        }

        $mimeType = mime_content_type($absolutePath) ?: 'image/png';

        return [
            'inline_data' => [
                'mime_type' => $mimeType,
                'data' => base64_encode($binary),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function extractJsonObject(string $text): array
    {
        $raw = trim($text);
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{[\s\S]*\}/', $raw, $matches) === 1) {
            $decoded = json_decode((string) $matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    private function isSentenceImagePathAllowed(MediaCenterProject $project, MediaCenterSentence $sentence, string $imagePath): bool
    {
        $path = trim($imagePath);
        if ($path === '') {
            return false;
        }

        $prefix = 'media_center/' . (int) $project->id . '/images/';
        if (!str_starts_with($path, $prefix)) {
            return false;
        }

        $fileName = basename($path);
        $pattern = '/^s' . (int) $sentence->sentence_index . '_.*\.(png|jpg|jpeg|webp)$/i';

        return (bool) preg_match($pattern, $fileName);
    }

    private function isSentenceAnimationPathAllowed(MediaCenterProject $project, MediaCenterSentence $sentence, string $animationPath): bool
    {
        $path = trim($animationPath);
        if ($path === '') {
            return false;
        }

        $prefix = 'media_center/' . (int) $project->id . '/animations/';
        if (!str_starts_with($path, $prefix)) {
            return false;
        }

        $fileName = basename($path);
        $pattern = '/^s' . (int) $sentence->sentence_index . '_.*\.(mp4|mov|webm)$/i';

        return (bool) preg_match($pattern, $fileName);
    }

    private function buildSentenceImagePath(int $projectId, int $sentenceIndex): string
    {
        $micro = str_replace('.', '', (string) microtime(true));
        $rand = bin2hex(random_bytes(4));

        return 'media_center/' . $projectId . '/images/s' . $sentenceIndex . '_' . $micro . '_' . $rand . '.png';
    }

    private function buildMainCharacterReferenceImagePath(int $projectId, string $type): string
    {
        $micro = str_replace('.', '', (string) microtime(true));
        $rand = bin2hex(random_bytes(4));
        $typeSlug = preg_replace('/[^a-z0-9\-]+/', '-', strtolower(trim($type))) ?: 'ref';

        return 'media_center/' . $projectId . '/references/main_' . $typeSlug . '_' . $micro . '_' . $rand . '.png';
    }

    private function buildMainCharacterUploadedReferenceImagePath(int $projectId, string $extension): string
    {
        $micro = str_replace('.', '', (string) microtime(true));
        $rand = bin2hex(random_bytes(4));
        $safeExt = preg_replace('/[^a-z0-9]+/', '', strtolower(trim($extension))) ?: 'png';

        return 'media_center/' . $projectId . '/references/main_upload_' . $micro . '_' . $rand . '.' . $safeExt;
    }

    /**
     * @return array<int,string>
     */
    private function getSupportedImageStyles(): array
    {
        return [
            'Cinematic',
            'Illustration / Digital Art',
            'Realistic / Photorealistic',
            'Fantasy / Epic',
            '3D / Pixar style',
            'Anime / Manga',
            'Documentary / Historical',
        ];
    }

    private function buildImageStylePreviewPath(int $projectId, string $style): string
    {
        $micro = str_replace('.', '', (string) microtime(true));
        $rand = bin2hex(random_bytes(4));
        $styleSlug = preg_replace('/[^a-z0-9\-]+/', '-', strtolower(trim($style))) ?: 'style';

        return 'media_center/' . $projectId . '/style_previews/s1_' . $styleSlug . '_' . $micro . '_' . $rand . '.png';
    }

    private function buildSceneStylePreviewPrompt(MediaCenterProject $project, int $sentenceIndex, string $scenePrompt, string $style): string
    {
        $settings = is_array($project->settings_json) ? $project->settings_json : [];
        $era = trim((string) ($settings['story_era'] ?? ''));
        $genre = trim((string) ($settings['story_genre'] ?? ''));
        $worldContext = trim((string) ($settings['world_context'] ?? ''));
        $forbidden = trim((string) ($settings['forbidden_elements'] ?? ''));
        $styleContext = $this->buildPromptContextBlock($style, $era, $genre, $worldContext, $forbidden);

        $lines = [
            'You are generating a style preview image for the Media Settings panel.',
            'This image must represent Scene ' . $sentenceIndex . ' in the selected style.',
            $styleContext,
            'Scene content: ' . trim($scenePrompt),
            'Keep scene meaning consistent; only change visual rendering style.',
            'No text, no watermark, no logo.',
            'Single frame, high quality composition.',
        ];

        return trim(implode("\n", $lines));
    }

    /**
     * @param mixed $raw
     * @return array<string,array<string,mixed>>
     */
    private function formatImageStylePreviewsForResponse($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $formatted = [];
        foreach ($raw as $styleKey => $item) {
            if (!is_array($item)) {
                continue;
            }

            $style = trim((string) ($item['style'] ?? $styleKey));
            $path = trim((string) ($item['path'] ?? ''));
            if ($style === '' || $path === '') {
                continue;
            }

            $formatted[$style] = [
                'style' => $style,
                'path' => $path,
                'url' => Storage::url($path),
                'provider' => trim((string) ($item['provider'] ?? 'gemini-nano-banana-pro')),
                'source_sentence_index' => (int) ($item['source_sentence_index'] ?? 1),
                'source_sentence_id' => (int) ($item['source_sentence_id'] ?? 0),
                'aspect_ratio' => trim((string) ($item['aspect_ratio'] ?? '16:9')),
                'generated_at' => trim((string) ($item['generated_at'] ?? '')),
            ];
        }

        return $formatted;
    }

    /**
     * @return array<int,string>
     */
    private function extractMainCharacterReferenceImagePaths(MediaCenterProject $project): array
    {
        $settings = is_array($project->settings_json) ? $project->settings_json : [];
        $rawRefs = $settings['main_character_reference_images'] ?? [];

        if (!is_array($rawRefs)) {
            return [];
        }

        $paths = [];
        foreach ($rawRefs as $ref) {
            if (!is_array($ref)) {
                continue;
            }

            $path = trim((string) ($ref['path'] ?? ''));
            if ($path === '' || !str_starts_with($path, 'media_center/' . (int) $project->id . '/references/')) {
                continue;
            }

            if (!in_array($path, $paths, true)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    private function isMainCharacterReferencePathAllowed(MediaCenterProject $project, string $path): bool
    {
        $path = trim($path);
        if ($path === '') {
            return false;
        }

        $prefix = 'media_center/' . (int) $project->id . '/references/';
        if (!str_starts_with($path, $prefix)) {
            return false;
        }

        return in_array($path, $this->extractMainCharacterReferenceImagePaths($project), true);
    }

    /**
     * @param mixed $refs
     * @return array<int,array<string,mixed>>
     */
    private function formatMainCharacterReferencesForResponse($refs): array
    {
        if (!is_array($refs)) {
            return [];
        }

        $formatted = [];
        foreach ($refs as $ref) {
            if (!is_array($ref)) {
                continue;
            }

            $path = trim((string) ($ref['path'] ?? ''));
            if ($path === '') {
                continue;
            }

            $entry = [
                'type'         => trim((string) ($ref['type'] ?? 'ref')),
                'path'         => $path,
                'url'          => Storage::url($path),
                'source'       => trim((string) ($ref['source'] ?? '')),
                'shot_type'    => trim((string) ($ref['shot_type'] ?? '')),
                'costume_type' => trim((string) ($ref['costume_type'] ?? '')),
                'costume_label' => trim((string) ($ref['costume_label'] ?? '')),
            ];
            $formatted[] = $entry;
        }

        return $formatted;
    }

    private function buildMainCharacterIdentityLock(MediaCenterProject $project): string
    {
        $name = trim((string) ($project->main_character_name ?? ''));
        $profile = trim((string) ($project->main_character_profile ?? ''));

        $lines = [
            'Main character identity lock (must remain consistent in all scenes):',
        ];

        if ($name !== '') {
            $lines[] = 'Name: ' . $name . '.';
        }

        if ($profile !== '') {
            $lines[] = 'Core appearance and personality cues: ' . $profile . '.';
        }

        $lines[] = 'Do not change face structure, hairline, eye shape, skin tone, age impression, or signature traits between images.';

        return trim(implode("\n", $lines));
    }

    /**
     * @param array<int,mixed> $refs
     */
    private function buildMainCharacterVisualLockFromUploadedRefs(array $refs): string
    {
        $apiKey = (string) config('services.gemini.api_key', '');
        if ($apiKey === '') {
            return '';
        }

        $parts = [[
            'text' => "Analyze these uploaded main-character reference images and produce a concise identity lock. Focus only on stable visual traits that must remain consistent across generated images. Include: face structure, hairstyle, age impression, skin tone, body build, signature outfit traits. Respond in plain English bullet points only.",
        ]];

        $added = 0;
        foreach ($refs as $ref) {
            if (!is_array($ref)) {
                continue;
            }

            $source = trim((string) ($ref['source'] ?? ''));
            $type = trim((string) ($ref['type'] ?? ''));
            if ($source !== 'manual_upload' && !str_starts_with($type, 'upload')) {
                continue;
            }

            $path = trim((string) ($ref['path'] ?? ''));
            if ($path === '') {
                continue;
            }

            $inlinePart = $this->buildGeminiInlineImagePart($path);
            if ($inlinePart === null) {
                continue;
            }

            $parts[] = $inlinePart;
            $added++;

            if ($added >= 3) {
                break;
            }
        }

        if ($added === 0) {
            return '';
        }

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . urlencode($apiKey);

        try {
            $response = Http::timeout(45)->post($url, [
                'contents' => [[
                    'parts' => $parts,
                ]],
                'generationConfig' => [
                    'temperature' => 0.2,
                    'maxOutputTokens' => 400,
                ],
            ]);

            if (!$response->successful()) {
                return '';
            }

            $text = trim((string) data_get($response->json(), 'candidates.0.content.parts.0.text', ''));
            if ($text === '') {
                return '';
            }

            return "Visual lock from uploaded references:\n" . $text;
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function buildPromptContextBlock(string $style, string $era, string $genre, string $worldContext, string $forbidden): string
    {
        $chunks = [];

        $chunks[] = 'Visual style: ' . ($style !== '' ? $style : 'Cinematic') . '.';
        if ($era !== '') {
            $chunks[] = 'Era: ' . $era . '.';
        }
        if ($genre !== '') {
            $chunks[] = 'Genre: ' . $genre . '.';
        }
        if ($worldContext !== '') {
            $chunks[] = 'World context: ' . $worldContext . '.';
        }
        if ($forbidden !== '') {
            $chunks[] = 'Forbidden elements: ' . $forbidden . '.';
        }

        return implode(' ', $chunks);
    }

    private function buildImagePromptWithIdentityLock(MediaCenterProject $project, string $basePrompt): string
    {
        $prompt = trim($basePrompt);
        if ($prompt === '') {
            return '';
        }

        $settings = is_array($project->settings_json) ? $project->settings_json : [];
        $enabled = (bool) ($settings['use_character_reference'] ?? false);
        if (!$enabled) {
            return $prompt;
        }

        $identityLock = trim((string) ($settings['main_character_identity_lock'] ?? ''));
        if ($identityLock === '') {
            $identityLock = $this->buildMainCharacterIdentityLock($project);
        }

        if ($identityLock === '') {
            return $prompt;
        }

        $refPaths = $this->extractMainCharacterReferenceImagePaths($project);
        $refHint = empty($refPaths)
            ? 'Use the same main character identity consistently across all generated story images.'
            : 'Use the generated main-character reference pack (face, half-body, full-body) to preserve identity consistency.';

        return trim($identityLock . "\n" . $refHint . "\n\nScene request:\n" . $prompt);
    }

    private function buildSentenceAnimationPath(int $projectId, int $sentenceIndex, string $provider): string
    {
        $micro = str_replace('.', '', (string) microtime(true));
        $rand = bin2hex(random_bytes(4));
        $providerSlug = preg_replace('/[^a-z0-9\-]+/', '-', strtolower(trim($provider))) ?: 'kling';

        return 'media_center/' . $projectId . '/animations/s' . $sentenceIndex . '_' . $providerSlug . '_' . $micro . '_' . $rand . '.mp4';
    }

    /**
     * @param array<string,mixed> $metadata
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function setSentenceGenerationState(array $metadata, string $kind, string $status, string $message, array $extra = []): array
    {
        $generation = is_array($metadata['generation'] ?? null) ? $metadata['generation'] : [];
        $generation[$kind] = array_merge([
            'status' => $status,
            'message' => $message,
            'updated_at' => now()->toIso8601String(),
        ], $extra);
        $metadata['generation'] = $generation;

        return $metadata;
    }

    /**
     * @return array<int,string>
     */
    private function extractImagePathsFromSentence(MediaCenterSentence $sentence): array
    {
        $paths = [];

        $mainPath = trim((string) ($sentence->image_path ?? ''));
        if ($mainPath !== '') {
            $paths[] = $mainPath;
        }

        $metadata = is_array($sentence->metadata_json) ? $sentence->metadata_json : [];
        $metaPaths = $this->normalizeImagePathsArray($metadata['image_paths'] ?? []);

        foreach ($metaPaths as $path) {
            if (!in_array($path, $paths, true)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * @return array<int,string>
     */
    private function extractAnimationPathsFromSentence(MediaCenterSentence $sentence): array
    {
        $metadata = is_array($sentence->metadata_json) ? $sentence->metadata_json : [];
        $rawItems = is_array($metadata['animations'] ?? null) ? $metadata['animations'] : [];

        $paths = [];
        foreach ($rawItems as $item) {
            if (!is_array($item)) {
                continue;
            }

            $path = trim((string) ($item['path'] ?? ''));
            if ($path === '' || in_array($path, $paths, true)) {
                continue;
            }

            $paths[] = $path;
        }

        return $paths;
    }

    private function resolveExistingPublicStoragePath(string $path): ?string
    {
        $normalized = trim($path);
        if ($normalized === '') {
            return null;
        }

        if (str_starts_with($normalized, '/storage/')) {
            $normalized = ltrim(substr($normalized, 8), '/');
        }

        if (str_starts_with($normalized, 'public/')) {
            $normalized = ltrim(substr($normalized, 7), '/');
        }

        if ($normalized === '' || !Storage::disk('public')->exists($normalized)) {
            return null;
        }

        return $normalized;
    }

    private function appendImagePathToMetadata(MediaCenterSentence $sentence, string $relativePath): array
    {
        $metadata = is_array($sentence->metadata_json) ? $sentence->metadata_json : [];
        $paths = $this->normalizeImagePathsArray($metadata['image_paths'] ?? []);

        $path = trim($relativePath);
        if ($path !== '' && !in_array($path, $paths, true)) {
            $paths[] = $path;
        }

        $metadata['image_paths'] = $paths;

        return $metadata;
    }

    /**
     * @param mixed $raw
     * @return array<int,string>
     */
    private function normalizeImagePathsArray($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $paths = [];
        foreach ($raw as $item) {
            $path = trim((string) $item);
            if ($path !== '' && !in_array($path, $paths, true)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildWorkspaceStats(MediaCenterProject $project): array
    {
        $sentences = $project->relationLoaded('sentences')
            ? $project->sentences
            : $project->sentences()->orderBy('sentence_index')->get();

        $sentenceCount = $sentences->count();
        $audioCount = 0;
        $imageCount = 0;
        $videoCount = 0;

        foreach ($sentences as $sentence) {
            $audioPath = $this->resolveExistingPublicStoragePath((string) ($sentence->tts_audio_path ?? ''));
            if ($audioPath !== null) {
                $audioCount++;
            }

            $imageCount += count($this->extractImagePathsFromSentence($sentence));
            $videoCount += count($this->extractAnimationPathsFromSentence($sentence));
        }

        $settings = is_array($project->settings_json) ? $project->settings_json : [];
        $costs = $this->normalizeUsageCosts($settings['usage_costs'] ?? []);

        $startedAt = $project->created_at ?: now();
        $finishedAt = null;
        $downloadAtRaw = trim((string) ($settings['download_all_assets_first_at'] ?? ''));
        if ($downloadAtRaw !== '') {
            try {
                $finishedAt = \Illuminate\Support\Carbon::parse($downloadAtRaw);
            } catch (\Throwable $e) {
                $finishedAt = null;
            }
        }

        $endAt = $finishedAt ?: now();
        if ($endAt->lt($startedAt)) {
            $endAt = $startedAt->copy();
        }
        $workDurationSeconds = (int) $startedAt->diffInSeconds($endAt);

        return [
            'sentence_count' => $sentenceCount,
            'image_count' => $imageCount,
            'video_count' => $videoCount,
            'audio_count' => $audioCount,
            'cost_image' => $costs['image'],
            'cost_video' => $costs['video'],
            'cost_audio' => $costs['audio'],
            'cost_ai_generation' => $costs['ai_generation'],
            'cost_total' => $costs['total'],
            'work_duration_seconds' => $workDurationSeconds,
            'work_duration_label' => $this->formatDurationLabel($workDurationSeconds),
            'work_started_at' => optional($startedAt)->toIso8601String(),
            'work_finished_at' => optional($finishedAt)->toIso8601String(),
            'work_finished' => $finishedAt !== null,
        ];
    }

    private function formatDurationLabel(int $seconds): string
    {
        $remaining = max(0, $seconds);
        $days = intdiv($remaining, 86400);
        $remaining -= $days * 86400;
        $hours = intdiv($remaining, 3600);
        $remaining -= $hours * 3600;
        $minutes = intdiv($remaining, 60);
        $secs = $remaining - ($minutes * 60);

        $parts = [];
        if ($days > 0) {
            $parts[] = $days . 'd';
        }
        if ($hours > 0 || !empty($parts)) {
            $parts[] = $hours . 'h';
        }
        if ($minutes > 0 || !empty($parts)) {
            $parts[] = $minutes . 'm';
        }
        $parts[] = $secs . 's';

        return implode(' ', $parts);
    }

    /**
     * @param mixed $raw
     * @return array{image:float,video:float,audio:float,ai_generation:float,total:float}
     */
    private function normalizeUsageCosts($raw): array
    {
        $costs = is_array($raw) ? $raw : [];

        $image = max(0.0, (float) ($costs['image'] ?? 0));
        $video = max(0.0, (float) ($costs['video'] ?? 0));
        $audio = max(0.0, (float) ($costs['audio'] ?? 0));
        $aiGeneration = max(0.0, (float) ($costs['ai_generation'] ?? 0));
        $total = $image + $video + $audio + $aiGeneration;

        return [
            'image' => round($image, 6),
            'video' => round($video, 6),
            'audio' => round($audio, 6),
            'ai_generation' => round($aiGeneration, 6),
            'total' => round($total, 6),
        ];
    }

    private function addProjectUsageCost(MediaCenterProject $project, string $bucket, float $amountUsd): void
    {
        $bucketKey = strtolower(trim($bucket));
        if (!in_array($bucketKey, ['image', 'video', 'audio', 'ai_generation'], true)) {
            return;
        }

        $amount = round(max(0.0, $amountUsd), 6);
        if ($amount <= 0) {
            return;
        }

        $project->refresh();
        $settings = is_array($project->settings_json) ? $project->settings_json : [];
        $costs = $this->normalizeUsageCosts($settings['usage_costs'] ?? []);

        $costs[$bucketKey] = round(($costs[$bucketKey] ?? 0) + $amount, 6);
        $costs['total'] = round($costs['image'] + $costs['video'] + $costs['audio'] + $costs['ai_generation'], 6);
        $settings['usage_costs'] = $costs;

        $project->forceFill([
            'settings_json' => $settings,
        ])->save();
    }

    private function estimateTtsCostUsd(string $provider, int $characters): float
    {
        $chars = max(0, $characters);
        if ($chars <= 0) {
            return 0.0;
        }

        $normalized = strtolower(trim($provider));
        $ratePerChar = match ($normalized) {
            'openai' => 0.000015,
            'gemini' => 0.000016,
            'microsoft' => 0.000016,
            'vbee' => 0.00003,
            default => 0.000016,
        };

        return round($chars * $ratePerChar, 6);
    }

    private function estimateImageCostUsd(string $provider): float
    {
        $normalized = strtolower(trim($provider));

        return match ($normalized) {
            'flux-1.1-pro' => 0.06,
            'flux-pro' => 0.04,
            'flux' => 0.02,
            'gemini-nano-banana-pro' => 0.03,
            default => 0.02,
        };
    }

    private function estimateAnimationCostUsd(string $provider, int $clips = 1): float
    {
        $count = max(1, $clips);
        $normalized = strtolower(trim($provider));
        $unit = $normalized === 'seedance' ? 0.08 : 0.10;

        return round($count * $unit, 6);
    }

    private function estimateAiGenerationCostUsd(int $characters = 0): float
    {
        $chars = max(0, $characters);
        $base = 0.002;
        $charCost = $chars * 0.0000012;

        return round($base + $charCost, 6);
    }

    /**
     * @param mixed $raw
     * @return array<int,int>
     */
    private function normalizeIntegerIds($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $item) {
            $id = (int) $item;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param mixed $rawIds
     * @return array<int,int>
     */
    private function filterReferenceSentenceIdsForImage(MediaCenterProject $project, MediaCenterSentence $sentence, $rawIds): array
    {
        $candidateIds = $this->normalizeIntegerIds($rawIds);
        if (empty($candidateIds)) {
            return [];
        }

        $candidateSentences = MediaCenterSentence::query()
            ->where('media_center_project_id', (int) $project->id)
            ->where('id', '!=', (int) $sentence->id)
            ->whereIn('id', $candidateIds)
            ->get(['id', 'image_path', 'metadata_json']);

        $validLookup = [];
        foreach ($candidateSentences as $candidate) {
            if (!empty($this->extractImagePathsFromSentence($candidate))) {
                $validLookup[(int) $candidate->id] = true;
            }
        }

        $filtered = [];
        foreach ($candidateIds as $id) {
            if (isset($validLookup[$id])) {
                $filtered[] = $id;
            }
        }

        return $filtered;
    }

    /**
     * @return array<string,string>
     */
    private function buildMainCharacterSeedForSentenceSync(MediaCenterProject $project): array
    {
        $name = trim((string) ($project->main_character_name ?? ''));
        $profile = trim((string) ($project->main_character_profile ?? ''));

        $seed = [];
        if ($name !== '') {
            $seed['name'] = $name;
        }
        if ($profile !== '') {
            $seed['appearance'] = $profile;
            $seed['wardrobe'] = 'Keep wardrobe continuity based on profile: ' . $profile;
            $seed['style_consistency_rules'] = 'Always keep identity, face structure, age, hair and costume continuity from this profile: ' . $profile;
        }

        return $seed;
    }

    private function rebuildAllSentencePlansForProject(MediaCenterProject $project, MediaCenterAnalyzeService $analyzeService): int
    {
        $project->refresh();

        $baseMainSeed = $this->buildMainCharacterSeedForSentenceSync($project);
        $updatedSentences = 0;

        $sentences = $project->sentences()->orderBy('sentence_index')->get();
        foreach ($sentences as $sentence) {
            $metadata = is_array($sentence->metadata_json) ? $sentence->metadata_json : [];
            $existingMain = is_array($metadata['main_character'] ?? null) ? $metadata['main_character'] : [];
            $mainSeed = array_merge($existingMain, $baseMainSeed);

            $rebuilt = $analyzeService->rebuildSentencePlanForMainCharacterProfile($project, $sentence, $mainSeed);

            $newTtsText = trim((string) ($rebuilt['tts_text'] ?? ''));
            $newImagePrompt = trim((string) ($rebuilt['image_prompt'] ?? ''));
            $newVideoPrompt = trim((string) ($rebuilt['video_prompt'] ?? ''));
            $newCharacterNotes = trim((string) ($rebuilt['character_notes'] ?? ''));

            $newMetadata = $metadata;
            $newMetadata['main_character'] = is_array($rebuilt['main_character'] ?? null)
                ? $rebuilt['main_character']
                : $existingMain;

            $changed = $newTtsText !== trim((string) ($sentence->tts_text ?? ''))
                || $newImagePrompt !== trim((string) ($sentence->image_prompt ?? ''))
                || $newVideoPrompt !== trim((string) ($sentence->video_prompt ?? ''))
                || $newCharacterNotes !== trim((string) ($sentence->character_notes ?? ''))
                || $newMetadata !== $metadata;

            if (!$changed) {
                continue;
            }

            $sentence->forceFill([
                'tts_text' => $newTtsText !== '' ? $newTtsText : (string) ($sentence->sentence_text ?? ''),
                'image_prompt' => $newImagePrompt,
                'video_prompt' => $newVideoPrompt,
                'character_notes' => $newCharacterNotes,
                'metadata_json' => $newMetadata,
            ])->save();

            $updatedSentences++;
        }

        return $updatedSentences;
    }

    /**
     * @return array{key:string,label:string,badge_class:string}
     */
    private function resolveProjectWorkflowStatus(MediaCenterProject $project, bool $hasGeneratedAsset): array
    {
        $settings = is_array($project->settings_json) ? $project->settings_json : [];
        $downloadedAt = trim((string) ($settings['download_all_assets_first_at'] ?? ''));

        if ($downloadedAt !== '') {
            return [
                'key' => 'completed',
                'label' => 'Hoan thanh',
                'badge_class' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
            ];
        }

        $costs = $this->normalizeUsageCosts($settings['usage_costs'] ?? []);
        $status = strtolower(trim((string) ($project->status ?? 'draft')));
        $isActiveStatus = in_array($status, ['queued', 'analyzing', 'analyzed', 'processing', 'generating', 'rendering'], true);
        $hasStarted = $hasGeneratedAsset || ($costs['total'] > 0.0) || $isActiveStatus;

        if ($hasStarted) {
            return [
                'key' => 'in_progress',
                'label' => 'Dang lam',
                'badge_class' => 'border-amber-200 bg-amber-50 text-amber-700',
            ];
        }

        return [
            'key' => 'new',
            'label' => 'Moi',
            'badge_class' => 'border-slate-200 bg-slate-100 text-slate-700',
        ];
    }

    private function deletePublicStorageFile(string $path): void
    {
        $normalized = trim($path);
        if ($normalized === '') {
            return;
        }

        if (str_starts_with($normalized, '/storage/')) {
            $normalized = ltrim(substr($normalized, 8), '/');
        }

        if (str_starts_with($normalized, 'public/')) {
            $normalized = ltrim(substr($normalized, 7), '/');
        }

        Storage::disk('public')->delete($normalized);
    }
}
