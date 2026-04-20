<?php

namespace App\Jobs;

use App\Models\MediaCenterProject;
use App\Models\MediaCenterSentence;
use App\Services\GeminiImageService;
use App\Services\KlingAIService;
use App\Services\SeedanceAIService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class GenerateMediaCenterSentenceAssetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 3;

    /**
     * @param string $assetType image|animation
     * @param array<string,mixed> $options
     */
    public function __construct(
        public readonly int $projectId,
        public readonly int $sentenceId,
        public readonly string $assetType,
        public readonly string $provider,
        public readonly array $options = []
    ) {
        $this->onQueue('default');
    }

    public function backoff(): array
    {
        return [10, 20, 45];
    }

    public function handle(GeminiImageService $imageService, KlingAIService $klingService, SeedanceAIService $seedanceService): void
    {
        [$lock] = $this->acquireGenerationSlot();

        try {
            $project = MediaCenterProject::find($this->projectId);
            $sentence = MediaCenterSentence::find($this->sentenceId);
            if (!$project || !$sentence || (int) $sentence->media_center_project_id !== (int) $project->id) {
                return;
            }

            if ($this->assetType === 'animation') {
                $this->runAnimationJob($project, $sentence, $klingService, $seedanceService);
                return;
            }

            $this->runImageJob($project, $sentence, $imageService);
        } finally {
            $lock->release();
        }
    }

    private function runImageJob(MediaCenterProject $project, MediaCenterSentence $sentence, GeminiImageService $imageService): void
    {
        $basePrompt = trim((string) ($sentence->image_prompt ?: ''));
        if ($basePrompt === '') {
            $this->updateGenerationState($sentence, 'image', 'failed', 'Cau nay chua co image prompt.');
            return;
        }

        $provider = $this->normalizeImageProvider($this->provider ?: (string) ($sentence->image_provider ?: 'gemini'));
        $referenceSentenceIds = $this->resolveImageReferenceSentenceIds($project, $sentence);
        $includeCharacterReferences = $this->resolveIncludeCharacterReferences($project, $sentence);
        $prompt = $this->buildImagePromptWithIdentityLock($project, $sentence, $basePrompt, $referenceSentenceIds, $includeCharacterReferences);
        $settings = is_array($project->settings_json) ? $project->settings_json : [];

        $aspectRatio = trim((string) ($settings['image_aspect_ratio'] ?? '16:9'));
        if (!in_array($aspectRatio, ['16:9', '9:16', '1:1'], true)) {
            $aspectRatio = '16:9';
        }

        $relativePath = $this->buildSentenceImagePath((int) $project->id, (int) $sentence->sentence_index);
        $absolutePath = storage_path('app/public/' . $relativePath);

        $this->updateGenerationState($sentence, 'image', 'running', 'Dang tao anh...', [
            'progress' => 30,
            'provider' => $provider,
        ]);
        $result = $imageService->generateImage($prompt, $absolutePath, $aspectRatio, $provider);

        if (!(bool) ($result['success'] ?? false)) {
            $this->updateGenerationState($sentence, 'image', 'failed', (string) ($result['error'] ?? 'Tao anh that bai.'), [
                'progress' => 100,
            ]);
            return;
        }

        $metadata = is_array($sentence->metadata_json) ? $sentence->metadata_json : [];
        $paths = $this->normalizeImagePathsArray($metadata['image_paths'] ?? []);
        if (!in_array($relativePath, $paths, true)) {
            $paths[] = $relativePath;
        }
        $metadata['image_paths'] = $paths;
        $metadata['image_reference_sentence_ids'] = $referenceSentenceIds;
        $metadata['include_character_references'] = $includeCharacterReferences;
        $metadata = $this->setGenerationState($metadata, 'image', 'completed', 'Tao anh thanh cong.', [
            'provider' => $provider,
            'image_path' => $relativePath,
            'reference_sentence_ids' => $referenceSentenceIds,
            'include_character_references' => $includeCharacterReferences,
            'progress' => 100,
            'completed_at' => now()->toIso8601String(),
        ]);

        $sentence->forceFill([
            'image_provider' => $provider,
            'image_path' => $relativePath,
            'metadata_json' => $metadata,
        ])->save();

        $this->addProjectUsageCost($project, 'image', $this->estimateImageCostUsd($provider));
    }

    private function runAnimationJob(MediaCenterProject $project, MediaCenterSentence $sentence, KlingAIService $klingService, SeedanceAIService $seedanceService): void
    {
        $provider = $this->normalizeAnimationProvider($this->provider ?: 'kling');
        $mode = $this->normalizeAnimationMode((string) ($this->options['mode'] ?? 'image-to-motion'));
        $cameraAngle = $this->normalizeCameraAngle((string) ($this->options['camera_angle'] ?? 'auto'));
        $instruction = trim((string) ($this->options['instruction'] ?? ''));

        $availableImagePaths = $this->extractImagePathsFromSentence($sentence);
        $requestedPaths = $this->normalizeImagePathsArray($this->options['selected_image_paths'] ?? []);
        $selectedImagePaths = [];

        foreach ($requestedPaths as $requestedPath) {
            if (in_array($requestedPath, $availableImagePaths, true) && !in_array($requestedPath, $selectedImagePaths, true)) {
                $selectedImagePaths[] = $requestedPath;
            }
        }

        if (empty($selectedImagePaths)) {
            $fallback = $availableImagePaths[0] ?? trim((string) ($sentence->image_path ?? ''));
            if ($fallback !== '') {
                $selectedImagePaths[] = $fallback;
            }
        }

        if ($mode !== 'image-to-story-sequence' && count($selectedImagePaths) > 1) {
            $selectedImagePaths = [reset($selectedImagePaths)];
        }

        if (empty($selectedImagePaths)) {
            $this->updateGenerationState($sentence, 'animation', 'failed', 'Chua co anh de tao animation.', [
                'progress' => 100,
            ]);
            return;
        }

        $this->updateGenerationState($sentence, 'animation', 'running', 'Dang tao animation...', [
            'progress' => 5,
            'mode' => $mode,
            'camera_angle' => $cameraAngle,
            'selected_images' => $selectedImagePaths,
        ]);

        $totalFrames = count($selectedImagePaths);
        $generatedItems = [];

        foreach ($selectedImagePaths as $index => $sourceImagePath) {
            $prompt = $this->buildAnimationPrompt($sentence, $mode, $cameraAngle, $instruction, $index, $totalFrames);
            $frameLabel = $totalFrames > 1 ? ('frame ' . ($index + 1) . '/' . $totalFrames) : 'single frame';

            $frameStartProgress = 10 + (int) floor(($index / max(1, $totalFrames)) * 70);
            $this->updateGenerationState($sentence, 'animation', 'running', 'Dang tao animation ' . $frameLabel . '...', [
                'progress' => $frameStartProgress,
                'current_frame' => $index + 1,
                'total_frames' => $totalFrames,
            ]);

            $createResult = $provider === 'seedance'
                ? $seedanceService->createImageToVideoTask($sourceImagePath, $prompt !== '' ? $prompt : null, ['duration' => 5])
                : $klingService->createImageToVideoTask($sourceImagePath, $prompt !== '' ? $prompt : null, ['duration' => 5]);

            if (!(bool) ($createResult['success'] ?? false)) {
                $this->updateGenerationState($sentence, 'animation', 'failed', (string) ($createResult['error'] ?? 'Khong tao duoc animation task.'), [
                    'progress' => 100,
                ]);
                return;
            }

            $taskId = trim((string) ($createResult['task_id'] ?? ''));
            if ($taskId === '') {
                $this->updateGenerationState($sentence, 'animation', 'failed', 'Khong nhan duoc task id animation.', [
                    'progress' => 100,
                ]);
                return;
            }

            $statusResult = $this->waitForAnimationTask(
                $provider,
                $taskId,
                $klingService,
                $seedanceService,
                $sentence,
                $frameStartProgress,
                (int) floor(60 / max(1, $totalFrames))
            );
            if (!(bool) ($statusResult['success'] ?? false)) {
                $this->updateGenerationState($sentence, 'animation', 'failed', (string) ($statusResult['error'] ?? 'Animation task that bai.'), [
                    'progress' => 100,
                ]);
                return;
            }

            $videoUrl = trim((string) ($statusResult['video_url'] ?? ''));
            if ($videoUrl === '') {
                $this->updateGenerationState($sentence, 'animation', 'failed', 'Task hoan tat nhung khong co video URL.', [
                    'progress' => 100,
                ]);
                return;
            }

            $relativePath = $this->buildSentenceAnimationPath((int) $project->id, (int) $sentence->sentence_index, $provider, $index);
            $download = Http::timeout(240)->retry(2, 1000)->get($videoUrl);

            if (!$download->successful()) {
                $this->updateGenerationState($sentence, 'animation', 'failed', 'Khong tai duoc video animation tu provider.', [
                    'progress' => 100,
                ]);
                return;
            }

            Storage::disk('public')->put($relativePath, $download->body());

            $generatedItems[] = [
                'path' => $relativePath,
                'provider' => $provider,
                'task_id' => $taskId,
                'prompt' => $prompt,
                'mode' => $mode,
                'camera_angle' => $cameraAngle,
                'source_image_path' => $sourceImagePath,
                'frame_index' => $index,
                'frame_total' => $totalFrames,
                'created_at' => now()->toIso8601String(),
            ];

            $frameDoneProgress = 15 + (int) floor((($index + 1) / max(1, $totalFrames)) * 75);
            $this->updateGenerationState($sentence, 'animation', 'running', 'Da xong frame ' . ($index + 1) . '/' . $totalFrames . '.', [
                'progress' => min(95, $frameDoneProgress),
                'current_frame' => $index + 1,
                'total_frames' => $totalFrames,
            ]);
        }

        $metadata = is_array($sentence->metadata_json) ? $sentence->metadata_json : [];
        $animations = is_array($metadata['animations'] ?? null) ? $metadata['animations'] : [];
        foreach ($generatedItems as $item) {
            $animations[] = $item;
        }
        $metadata['animations'] = $animations;
        $metadata = $this->setGenerationState($metadata, 'animation', 'completed', 'Tao animation thanh cong.', [
            'provider' => $provider,
            'mode' => $mode,
            'camera_angle' => $cameraAngle,
            'selected_images' => $selectedImagePaths,
            'generated_count' => count($generatedItems),
            'progress' => 100,
            'completed_at' => now()->toIso8601String(),
        ]);

        $sentence->forceFill([
            'metadata_json' => $metadata,
        ])->save();

        $this->addProjectUsageCost($project, 'video', $this->estimateAnimationCostUsd($provider, count($generatedItems)));
    }

    /**
     * @return array{0:Lock,1:int}
     */
    private function acquireGenerationSlot(): array
    {
        $start = microtime(true);
        while ((microtime(true) - $start) < 300) {
            for ($slot = 1; $slot <= 5; $slot++) {
                $lock = Cache::lock('media_center:generation:slot:' . $slot, 1800);
                if ($lock->get()) {
                    return [$lock, $slot];
                }
            }

            sleep(2);
        }

        throw new \RuntimeException('Khong co slot generation trong thoi gian cho phep.');
    }

    /**
     * @param array<string,mixed> $extra
     */
    private function updateGenerationState(MediaCenterSentence $sentence, string $kind, string $status, string $message, array $extra = []): void
    {
        $metadata = is_array($sentence->metadata_json) ? $sentence->metadata_json : [];
        $metadata = $this->setGenerationState($metadata, $kind, $status, $message, $extra);

        $sentence->forceFill([
            'metadata_json' => $metadata,
        ])->save();
    }

    /**
     * @param array<string,mixed> $metadata
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function setGenerationState(array $metadata, string $kind, string $status, string $message, array $extra): array
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

    private function buildSentenceImagePath(int $projectId, int $sentenceIndex): string
    {
        $micro = str_replace('.', '', (string) microtime(true));
        $rand = bin2hex(random_bytes(4));

        return 'media_center/' . $projectId . '/images/s' . $sentenceIndex . '_' . $micro . '_' . $rand . '.png';
    }

    private function buildSentenceAnimationPath(int $projectId, int $sentenceIndex, string $provider, int $frameIndex = 0): string
    {
        $micro = str_replace('.', '', (string) microtime(true));
        $rand = bin2hex(random_bytes(4));
        $providerSlug = preg_replace('/[^a-z0-9\-]+/', '-', strtolower(trim($provider))) ?: 'kling';
        $frameSuffix = $frameIndex > 0 ? ('_f' . $frameIndex) : '';

        return 'media_center/' . $projectId . '/animations/s' . $sentenceIndex . '_' . $providerSlug . $frameSuffix . '_' . $micro . '_' . $rand . '.mp4';
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

    private function buildAnimationPrompt(MediaCenterSentence $sentence, string $mode, string $cameraAngle, string $instruction, int $frameIndex, int $frameTotal): string
    {
        $base = trim((string) ($sentence->video_prompt ?: $sentence->image_prompt ?: ''));
        $cameraLine = $cameraAngle === 'auto' ? 'Camera: cinematic auto.' : ('Camera: ' . $cameraAngle . '.');

        $modeLine = match ($mode) {
            'image-to-cinematic-shot' => 'Mode: Image to Cinematic Shot. Prioritize camera movement, framing, and smooth cinematic feel.',
            'image-to-action' => 'Mode: Image to Action. Generate stronger dynamic motion while preserving identity and scene continuity.',
            'image-to-story-sequence' => 'Mode: Image to Story Sequence. Build this shot as part of a multi-frame sequence with temporal continuity.',
            'image-to-character-animation' => 'Mode: Image to Character Animation. Focus on character acting, facial expression, subtle lip-sync style mouth motion and body gesture continuity.',
            default => 'Mode: Image to Motion. Animate static image with subtle natural movements.',
        };

        $frameLine = $frameTotal > 1
            ? ('Sequence frame ' . ($frameIndex + 1) . ' of ' . $frameTotal . '. Keep temporal continuity with neighboring frames.')
            : '';

        $instructionLine = $instruction !== '' ? ('User instruction: ' . $instruction) : '';
        $rules = 'Do not add new characters or objects not present in source image. Preserve face identity, costume, and scene composition.';

        return trim(implode("\n", array_filter([$modeLine, $cameraLine, $frameLine, $instructionLine, $rules, $base], static fn($v) => trim((string) $v) !== '')));
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
        $raw = is_array($settings['usage_costs'] ?? null) ? $settings['usage_costs'] : [];

        $costs = [
            'image' => max(0.0, (float) ($raw['image'] ?? 0)),
            'video' => max(0.0, (float) ($raw['video'] ?? 0)),
            'audio' => max(0.0, (float) ($raw['audio'] ?? 0)),
            'ai_generation' => max(0.0, (float) ($raw['ai_generation'] ?? 0)),
        ];

        $costs[$bucketKey] = round(($costs[$bucketKey] ?? 0) + $amount, 6);
        $settings['usage_costs'] = [
            'image' => round($costs['image'], 6),
            'video' => round($costs['video'], 6),
            'audio' => round($costs['audio'], 6),
            'ai_generation' => round($costs['ai_generation'], 6),
            'total' => round($costs['image'] + $costs['video'] + $costs['audio'] + $costs['ai_generation'], 6),
        ];

        $project->forceFill([
            'settings_json' => $settings,
        ])->save();
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

    /**
     * @return array{success:bool,video_url?:string,error?:string}
     */
    private function waitForAnimationTask(
        string $provider,
        string $taskId,
        KlingAIService $klingService,
        SeedanceAIService $seedanceService,
        MediaCenterSentence $sentence,
        int $baseProgress = 10,
        int $spanProgress = 60
    ): array {
        $maxAttempts = 36;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $statusResult = $provider === 'seedance'
                ? $seedanceService->getTaskStatus($taskId)
                : $klingService->getTaskStatus($taskId);

            if (!(bool) ($statusResult['success'] ?? false)) {
                return [
                    'success' => false,
                    'error' => (string) ($statusResult['error'] ?? 'Khong doc duoc trang thai animation task.'),
                ];
            }

            $status = strtolower(trim((string) ($statusResult['status'] ?? '')));
            $attemptProgress = $baseProgress + (int) floor((($attempt + 1) / max(1, $maxAttempts)) * $spanProgress);
            $this->updateGenerationState($sentence, 'animation', 'running', 'Animation status: ' . ($status !== '' ? $status : 'unknown'), [
                'progress' => min(95, max(1, $attemptProgress)),
            ]);

            if ($status === 'completed') {
                $videoUrl = trim((string) ($statusResult['video_url'] ?? ''));
                if ($videoUrl === '') {
                    return [
                        'success' => false,
                        'error' => 'Task completed nhung khong co video URL.',
                    ];
                }

                return [
                    'success' => true,
                    'video_url' => $videoUrl,
                ];
            }

            if ($status === 'failed') {
                return [
                    'success' => false,
                    'error' => (string) ($statusResult['error'] ?? 'Animation task failed.'),
                ];
            }

            sleep(5);
            $sentence->refresh();
        }

        return [
            'success' => false,
            'error' => 'Animation task timeout. Vui long thu lai.',
        ];
    }

    /**
     * @param array<int,int> $referenceSentenceIds
     */
    private function buildImagePromptWithIdentityLock(MediaCenterProject $project, MediaCenterSentence $sentence, string $basePrompt, array $referenceSentenceIds = [], bool $includeCharacterReferences = true): string
    {
        $prompt = trim($basePrompt);
        if ($prompt === '') {
            return '';
        }

        $settings = is_array($project->settings_json) ? $project->settings_json : [];

        $refs = is_array($settings['main_character_reference_images'] ?? null)
            ? $settings['main_character_reference_images']
            : [];

        $identityLock = trim((string) ($settings['main_character_identity_lock'] ?? ''));
        if ($identityLock === '') {
            $identityLock = $this->buildMainCharacterIdentityLock($project);
        }

        $characterRefContext = $includeCharacterReferences ? $this->buildCharacterReferenceContext($refs) : '';

        $crossSentenceContext = $this->buildCrossSentenceImageReferenceContext($project, $sentence, $referenceSentenceIds);

        // Costume override: if sentence specifies a different outfit, instruct to change costume while keeping face
        $costumeOverrideContext = $this->buildCostumeOverrideContext($sentence, $project);

        if ($characterRefContext === '' && $identityLock === '' && $crossSentenceContext === '' && $costumeOverrideContext === '') {
            return $prompt;
        }

        $promptParts = [];

        if ($characterRefContext !== '') {
            $promptParts[] = "Identity source: use attached character reference images as primary truth.\n"
                . "Keep the same person across all scenes (face, build, age cues, hairstyle).\n"
                . $characterRefContext;
        } elseif ($identityLock !== '') {
            $promptParts[] = "Identity lock (fallback):\n" . $identityLock;
        }

        if ($costumeOverrideContext !== '') {
            $promptParts[] = $costumeOverrideContext;
        }

        if ($crossSentenceContext !== '') {
            $promptParts[] = $crossSentenceContext;
        }

        $promptParts[] = 'Scene request:' . "\n" . $prompt;

        return trim(implode("\n\n", array_filter($promptParts, static fn($part) => trim((string) $part) !== '')));
    }

    private function buildCostumeOverrideContext(MediaCenterSentence $sentence, MediaCenterProject $project): string
    {
        $metadata = is_array($sentence->metadata_json) ? $sentence->metadata_json : [];
        $costumeOverride = trim((string) ($metadata['costume_override'] ?? ''));

        if ($costumeOverride === '') {
            return '';
        }

        $settings = is_array($project->settings_json) ? $project->settings_json : [];
        $charData = is_array($settings['main_character_data'] ?? null) ? $settings['main_character_data'] : [];
        $defaultWardrobe = trim((string) ($charData['wardrobe'] ?? ''));
        if ($defaultWardrobe === '') {
            $profile = trim((string) ($project->main_character_profile ?? ''));
            $defaultWardrobe = $this->parseProfileField($profile, 'Wardrobe');
        }

        $lines = [
            'Costume override for this scene: ' . $costumeOverride . '.',
            'Keep EXACTLY THE SAME face, hairstyle, skin tone, and racial features as in the identity lock.',
            'Only the clothing changes; identity is unchanged.',
        ];
        if ($defaultWardrobe !== '') {
            $lines[] = '(Default wardrobe for all other scenes: ' . $defaultWardrobe . '.)';
        }

        return implode("\n", $lines);
    }

    private function resolveIncludeCharacterReferences(MediaCenterProject $project, MediaCenterSentence $sentence): bool
    {
        if (array_key_exists('include_character_references', $this->options)) {
            return (bool) $this->options['include_character_references'];
        }

        $metadata = is_array($sentence->metadata_json) ? $sentence->metadata_json : [];
        if (array_key_exists('include_character_references', $metadata)) {
            return (bool) $metadata['include_character_references'];
        }

        $settings = is_array($project->settings_json) ? $project->settings_json : [];
        if (array_key_exists('use_character_reference', $settings)) {
            return (bool) $settings['use_character_reference'] || $this->hasCharacterReferenceImages($settings);
        }

        return $this->hasCharacterReferenceImages($settings);
    }

    /**
     * @param mixed $rawRefs
     */
    private function buildCharacterReferenceContext($rawRefs): string
    {
        if (!is_array($rawRefs) || empty($rawRefs)) {
            return '';
        }

        $lines = ['Character refs attached:'];
        $count = 0;

        foreach ($rawRefs as $ref) {
            if (!is_array($ref)) {
                continue;
            }

            $path = trim((string) ($ref['path'] ?? ''));
            if ($path === '') {
                continue;
            }

            $type = trim((string) ($ref['type'] ?? 'ref')) ?: 'ref';
            $lines[] = '- [' . $type . '] ' . basename($path);
            $count++;
        }

        if ($count <= 0) {
            return '';
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<int,int>
     */
    private function resolveImageReferenceSentenceIds(MediaCenterProject $project, MediaCenterSentence $sentence): array
    {
        $optionIds = $this->normalizeIntegerIds($this->options['reference_sentence_ids'] ?? []);
        $metadata = is_array($sentence->metadata_json) ? $sentence->metadata_json : [];
        $metadataIds = $this->normalizeIntegerIds($metadata['image_reference_sentence_ids'] ?? []);

        $candidateIds = !empty($optionIds) ? $optionIds : $metadataIds;
        if (empty($candidateIds)) {
            return [];
        }

        $candidateSentences = MediaCenterSentence::query()
            ->where('media_center_project_id', (int) $project->id)
            ->where('id', '!=', (int) $sentence->id)
            ->whereIn('id', $candidateIds)
            ->get(['id', 'image_path', 'metadata_json'])
            ->keyBy('id');

        $validLookup = [];
        foreach ($candidateSentences as $candidate) {
            if (!empty($this->extractImagePathsFromSentence($candidate))) {
                $validLookup[(int) $candidate->id] = true;
            }
        }

        $ids = [];
        foreach ($candidateIds as $id) {
            if (isset($validLookup[$id]) && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param array<int,int> $referenceSentenceIds
     */
    private function buildCrossSentenceImageReferenceContext(MediaCenterProject $project, MediaCenterSentence $sentence, array $referenceSentenceIds): string
    {
        if (empty($referenceSentenceIds)) {
            return '';
        }

        $referenceSentences = MediaCenterSentence::query()
            ->where('media_center_project_id', (int) $project->id)
            ->where('id', '!=', (int) $sentence->id)
            ->whereIn('id', $referenceSentenceIds)
            ->get(['id', 'sentence_index', 'image_path', 'metadata_json'])
            ->keyBy('id');

        if ($referenceSentences->isEmpty()) {
            return '';
        }

        $lines = ['Cross-sentence refs (continuity only):'];

        foreach ($referenceSentenceIds as $refId) {
            $refSentence = $referenceSentences->get($refId);
            if (!$refSentence) {
                continue;
            }

            $imagePaths = $this->extractImagePathsFromSentence($refSentence);
            $imageHint = !empty($imagePaths)
                ? ('image_count=' . count($imagePaths) . ', latest=' . basename((string) end($imagePaths)))
                : 'image_count=0';

            $lines[] = '- Reference sentence #' . (int) $refSentence->sentence_index . ' (' . $imageHint . ')';
        }

        return count($lines) > 1 ? implode("\n", $lines) : '';
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

    private function buildMainCharacterIdentityLock(MediaCenterProject $project): string
    {
        $settings = is_array($project->settings_json) ? $project->settings_json : [];
        $charData = is_array($settings['main_character_data'] ?? null) ? $settings['main_character_data'] : [];

        $name = trim((string) ($charData['name'] ?? $project->main_character_name ?? ''));
        $race = trim((string) ($charData['race'] ?? ''));
        $skinTone = trim((string) ($charData['skin_tone'] ?? ''));
        $appearance = trim((string) ($charData['appearance'] ?? ''));
        $wardrobe = trim((string) ($charData['wardrobe'] ?? ''));
        $styleRules = trim((string) ($charData['style_consistency_rules'] ?? ''));

        // Fallback: parse structured fields from profile text
        if ($race === '' || $wardrobe === '') {
            $profile = trim((string) ($project->main_character_profile ?? ''));
            if ($race === '') {
                $race = $this->parseProfileField($profile, 'Race');
            }
            if ($wardrobe === '') {
                $wardrobe = $this->parseProfileField($profile, 'Wardrobe');
            }
            if ($skinTone === '') {
                $skinTone = $this->parseProfileField($profile, 'Skin tone');
            }
        }

        $lines = ['Main character identity lock (fallback when no refs):'];

        if ($name !== '') {
            $lines[] = 'Name: ' . $name . '.';
        }
        if ($race !== '') {
            $lines[] = 'Race/ethnicity: ' . $race . '. Never change facial structure, race, or ethnicity across scenes — even if costume changes.';
        }
        if ($skinTone !== '') {
            $lines[] = 'Skin tone: ' . $skinTone . '.';
        }
        if ($appearance !== '') {
            $lines[] = 'Appearance: ' . $appearance . '.';
        }
        if ($wardrobe !== '') {
            $lines[] = 'Default wardrobe: ' . $wardrobe . '. Use this costume unless the scene explicitly specifies a different outfit.';
        }
        if ($styleRules !== '') {
            $lines[] = 'Style rules: ' . $styleRules . '.';
        }

        $lines[] = 'Keep facial structure, hairline, eye shape, skin tone, and age impression consistent across all generated images.';

        return trim(implode("\n", $lines));
    }

    private function parseProfileField(string $profile, string $fieldName): string
    {
        if ($profile === '') {
            return '';
        }
        $pattern = '/' . preg_quote($fieldName, '/') . ':\s*(.+)/iu';
        if (preg_match($pattern, $profile, $matches)) {
            return trim((string) ($matches[1] ?? ''));
        }

        return '';
    }

    /**
     * @param array<string,mixed> $settings
     */
    private function hasCharacterReferenceImages(array $settings): bool
    {
        $refs = is_array($settings['main_character_reference_images'] ?? null)
            ? $settings['main_character_reference_images']
            : [];

        foreach ($refs as $ref) {
            if (!is_array($ref)) {
                continue;
            }

            if (trim((string) ($ref['path'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }
}
