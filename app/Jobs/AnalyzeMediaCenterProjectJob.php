<?php

namespace App\Jobs;

use App\Models\MediaCenterProject;
use App\Models\MediaCenterSentence;
use App\Services\MediaCenterAnalyzeService;
use App\Jobs\GenerateProjectCharacterReferencesJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AnalyzeMediaCenterProjectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public function __construct(public readonly int $projectId)
    {
    }

    public function handle(MediaCenterAnalyzeService $analyzeService): void
    {
        $project = MediaCenterProject::with('sentences')->find($this->projectId);
        if (!$project) {
            return;
        }

        $total = max(1, $project->sentences->count());
        $this->updateProgress($project, 'running', 5, 'Bắt đầu phân tích nội dung...', 0, $total);

        try {
            $result = $analyzeService->buildCharacterAndPromptPlan($project);

            $this->addProjectUsageCost(
                $project,
                'ai_generation',
                $this->estimateAnalyzeCostUsd($project->sentences->count())
            );

            $project->forceFill([
                'main_character_name' => $result['main_character']['name'] ?? null,
                'main_character_profile' => $result['main_character_profile_text'] ?? null,
                'characters_json' => $result['characters'] ?? [],
                'status' => 'analyzing',
            ])->save();

            $sentencePlans = $result['sentences'] ?? [];
            $processed = 0;

            foreach ($project->sentences as $sentence) {
                $plan = $sentencePlans[$sentence->sentence_index] ?? null;
                if (!is_array($plan)) {
                    $plan = $analyzeService->buildDefaultSentencePlan(
                        $sentence->sentence_text,
                        is_array($result['main_character'] ?? null) ? $result['main_character'] : []
                    );
                }

                $imagePrompt = trim((string) ($plan['image_prompt'] ?? ''));
                $videoPrompt = trim((string) ($plan['video_prompt'] ?? ''));
                $characterNotes = trim((string) ($plan['character_notes'] ?? ''));

                if ($imagePrompt === '' || $videoPrompt === '' || $characterNotes === '') {
                    $defaultPlan = $analyzeService->buildDefaultSentencePlan(
                        (string) ($plan['tts_text'] ?? $sentence->sentence_text),
                        is_array($result['main_character'] ?? null) ? $result['main_character'] : []
                    );
                    if ($imagePrompt === '') {
                        $imagePrompt = $defaultPlan['image_prompt'];
                    }
                    if ($videoPrompt === '') {
                        $videoPrompt = $defaultPlan['video_prompt'];
                    }
                    if ($characterNotes === '') {
                        $characterNotes = $defaultPlan['character_notes'];
                    }
                }

                $involvesMain = isset($plan['involves_main_character']) ? (bool) $plan['involves_main_character'] : true;
                $costumeOverride = trim((string) ($plan['costume_override'] ?? ''));
                $sceneId = max(1, (int) ($plan['scene_id'] ?? 1));

                $sentence->forceFill([
                    'tts_text' => (string) ($plan['tts_text'] ?? $sentence->sentence_text),
                    'image_prompt' => $imagePrompt,
                    'video_prompt' => $videoPrompt,
                    'character_notes' => $characterNotes,
                    'metadata_json' => [
                        'main_character' => $result['main_character'] ?? null,
                        'involves_main_character' => $involvesMain,
                        'include_character_references' => $involvesMain,
                        'costume_override' => $costumeOverride,
                        'scene_id' => $sceneId,
                    ],
                ])->save();

                $processed++;
                $progress = 10 + (int) floor(($processed / $total) * 85);
                $this->updateProgress($project, 'running', min(95, $progress), "Đã xử lý {$processed}/{$total} câu...", $processed, $total);
            }

            // Store main character data for prompt-building jobs
            $this->storeMainCharacterData($project, $result['main_character'] ?? []);

            // Compute scene-based cross-sentence refs for visual continuity
            $project->refresh();
            $this->assignSceneCrossRefs($project);

            $project->forceFill(['status' => 'analyzed'])->save();
            $this->updateProgress($project, 'completed', 100, 'Phân tích hoàn tất.', $total, $total);

            // Auto-generate character reference images if not yet done (once per project)
            $settings = is_array($project->settings_json) ? $project->settings_json : [];
            if (!$this->hasAiGeneratedMainCharacterRefs($settings)
                && trim((string) ($project->main_character_name ?? '')) !== '') {
                GenerateProjectCharacterReferencesJob::dispatch((int) $project->id);
            }
        } catch (\Throwable $e) {
            $project->forceFill(['status' => 'analyze_failed'])->save();
            $this->updateProgress($project, 'failed', 100, 'Lỗi analyze: ' . $e->getMessage(), 0, $total, $e->getMessage());
            throw $e;
        }
    }

    private function storeMainCharacterData(MediaCenterProject $project, array $mainCharacter): void
    {
        if (empty($mainCharacter)) {
            return;
        }

        $settings = is_array($project->settings_json) ? $project->settings_json : [];
        $settings['main_character_data'] = [
            'name' => trim((string) ($mainCharacter['name'] ?? '')),
            'gender' => trim((string) ($mainCharacter['gender'] ?? '')),
            'race' => trim((string) ($mainCharacter['race'] ?? '')),
            'skin_tone' => trim((string) ($mainCharacter['skin_tone'] ?? '')),
            'appearance' => trim((string) ($mainCharacter['appearance'] ?? '')),
            'wardrobe' => trim((string) ($mainCharacter['wardrobe'] ?? '')),
            'style_consistency_rules' => trim((string) ($mainCharacter['style_consistency_rules'] ?? '')),
        ];

        $project->forceFill(['settings_json' => $settings])->save();
    }

    private function assignSceneCrossRefs(MediaCenterProject $project): void
    {
        $sentences = MediaCenterSentence::query()
            ->where('media_center_project_id', $project->id)
            ->orderBy('sentence_index')
            ->get(['id', 'sentence_index', 'metadata_json']);

        // Group sentences by scene_id
        $sceneGroups = [];
        foreach ($sentences as $sentence) {
            $meta = is_array($sentence->metadata_json) ? $sentence->metadata_json : [];
            $sceneId = max(1, (int) ($meta['scene_id'] ?? 1));
            $sceneGroups[$sceneId][] = $sentence;
        }

        // For each scene, sentences after the first get a ref to the immediately preceding sentence in the same scene
        foreach ($sceneGroups as $group) {
            for ($i = 1; $i < count($group); $i++) {
                /** @var MediaCenterSentence $cur */
                $cur = $group[$i];
                /** @var MediaCenterSentence $prev */
                $prev = $group[$i - 1];

                $meta = is_array($cur->metadata_json) ? $cur->metadata_json : [];
                // Only set if not already manually configured
                if (!isset($meta['image_reference_sentence_ids']) || empty($meta['image_reference_sentence_ids'])) {
                    $meta['image_reference_sentence_ids'] = [(int) $prev->id];
                    $cur->forceFill(['metadata_json' => $meta])->save();
                }
            }
        }
    }

    /**
     * @param array<string,mixed> $settings
     */
    private function hasAiGeneratedMainCharacterRefs(array $settings): bool
    {
        $refs = is_array($settings['main_character_reference_images'] ?? null)
            ? $settings['main_character_reference_images']
            : [];

        foreach ($refs as $ref) {
            if (is_array($ref) && trim((string) ($ref['source'] ?? '')) === 'ai_generated') {
                return true;
            }
        }

        return false;
    }

    private function updateProgress(
        MediaCenterProject $project,
        string $status,
        int $progress,
        string $message,
        int $processed,
        int $total,
        ?string $error = null
    ): void {
        $settings = is_array($project->settings_json) ? $project->settings_json : [];

        $settings['analyze'] = [
            'status' => $status,
            'progress' => max(0, min(100, $progress)),
            'message' => $message,
            'processed_sentences' => $processed,
            'total_sentences' => $total,
            'error' => $error,
            'updated_at' => now()->toIso8601String(),
            'finished_at' => in_array($status, ['completed', 'failed'], true) ? now()->toIso8601String() : null,
        ];

        $project->forceFill(['settings_json' => $settings])->save();
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

        $project->forceFill(['settings_json' => $settings])->save();
    }

    private function estimateAnalyzeCostUsd(int $sentenceCount): float
    {
        $count = max(1, $sentenceCount);
        return round(0.01 + ($count * 0.0015), 6);
    }
}
