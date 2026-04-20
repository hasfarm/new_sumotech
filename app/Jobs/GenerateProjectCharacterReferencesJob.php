<?php

namespace App\Jobs;

use App\Models\MediaCenterProject;
use App\Services\GeminiImageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class GenerateProjectCharacterReferencesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 2;

    public function __construct(public readonly int $projectId)
    {
        $this->onQueue('default');
    }

    public function handle(GeminiImageService $imageService): void
    {
        $project = MediaCenterProject::find($this->projectId);
        if (!$project) {
            return;
        }

        $settings = is_array($project->settings_json) ? $project->settings_json : [];

        // Skip main character if AI refs already exist
        if (!$this->hasAiGeneratedMainCharacterRefs($settings)) {
            $this->generateMainCharacterRefs($project, $imageService);
            $project->refresh();
            $settings = is_array($project->settings_json) ? $project->settings_json : [];
        }

        // Generate supporting character refs
        $this->generateSupportingCharacterRefs($project, $imageService);
    }

    private function generateMainCharacterRefs(MediaCenterProject $project, GeminiImageService $imageService): void
    {
        $name = trim((string) ($project->main_character_name ?? ''));
        $profile = trim((string) ($project->main_character_profile ?? ''));

        if ($name === '' && $profile === '') {
            return;
        }

        $settings = is_array($project->settings_json) ? $project->settings_json : [];
        $identityLock = $this->buildIdentityLock($project);
        $contextBlock = $this->buildContextBlock($settings);

        $existingRefs = is_array($settings['main_character_reference_images'] ?? null)
            ? $settings['main_character_reference_images']
            : [];
        $preservedRefs = [];
        $staleAiPaths = [];
        foreach ($existingRefs as $ref) {
            if (!is_array($ref)) {
                continue;
            }
            $source = trim((string) ($ref['source'] ?? ''));
            $type = trim((string) ($ref['type'] ?? ''));
            if ($source === 'manual_upload' || str_starts_with($type, 'upload')) {
                $preservedRefs[] = $ref;
            } elseif ($source === 'ai_generated') {
                $staleAiPaths[] = trim((string) ($ref['path'] ?? ''));
            } else {
                $preservedRefs[] = $ref;
            }
        }

        foreach ($staleAiPaths as $oldPath) {
            if ($oldPath !== '') {
                Storage::disk('public')->delete($oldPath);
            }
        }

        $referencePlan = [
            ['type' => 'face', 'ratio' => '1:1', 'shot' => 'close-up portrait of face and shoulders only, neutral background'],
            ['type' => 'half_body', 'ratio' => '9:16', 'shot' => 'medium shot from waist up, full costume visible'],
            ['type' => 'full_body', 'ratio' => '9:16', 'shot' => 'full body standing pose, head to toe, clear background'],
        ];

        $generatedRefs = [];
        foreach ($referencePlan as $item) {
            $type = (string) $item['type'];
            $ratio = (string) $item['ratio'];
            $shot = (string) $item['shot'];

            $prompt = trim("{$contextBlock}\n\n{$identityLock}\n\nReference shot: {$shot}. Render full wardrobe detail. Keep facial identity, hairstyle, age cues, skin tone, and costume fully consistent with identity lock.");

            $relativePath = $this->buildMainCharacterRefPath((int) $project->id, $type);
            $absolutePath = storage_path('app/public/' . $relativePath);

            $result = $imageService->generateImage($prompt, $absolutePath, $ratio, 'gemini');
            if (!(bool) ($result['success'] ?? false)) {
                continue;
            }

            $generatedRefs[] = [
                'type' => $type,
                'path' => $relativePath,
                'ratio' => $ratio,
                'prompt' => $prompt,
                'source' => 'ai_generated',
                'created_at' => now()->toIso8601String(),
            ];

            $this->addUsageCost($project, 'image', 0.02);
        }

        if (empty($generatedRefs)) {
            return;
        }

        $project->refresh();
        $settings = is_array($project->settings_json) ? $project->settings_json : [];
        $settings['main_character_reference_images'] = array_values(array_merge($preservedRefs, $generatedRefs));
        $settings['main_character_identity_lock'] = $identityLock;
        if (!array_key_exists('use_character_reference', $settings)) {
            $settings['use_character_reference'] = true;
        }

        $project->forceFill(['settings_json' => $settings])->save();
    }

    private function generateSupportingCharacterRefs(MediaCenterProject $project, GeminiImageService $imageService): void
    {
        $characters = is_array($project->characters_json) ? $project->characters_json : [];
        if (empty($characters)) {
            return;
        }

        $settings = is_array($project->settings_json) ? $project->settings_json : [];
        $contextBlock = $this->buildContextBlock($settings);
        $changed = false;

        foreach ($characters as $idx => $character) {
            if (!is_array($character)) {
                continue;
            }

            // Skip if this character already has AI-generated refs
            $existingRefs = is_array($character['reference_images'] ?? null) ? $character['reference_images'] : [];
            $hasRef = false;
            foreach ($existingRefs as $ref) {
                if (is_array($ref) && trim((string) ($ref['source'] ?? '')) === 'ai_generated') {
                    $hasRef = true;
                    break;
                }
            }
            if ($hasRef) {
                continue;
            }

            $name = trim((string) ($character['name'] ?? ''));
            $race = trim((string) ($character['race'] ?? 'Asian'));
            $skinTone = trim((string) ($character['skin_tone'] ?? 'medium'));
            $appearance = trim((string) ($character['appearance'] ?? ''));
            $wardrobe = trim((string) ($character['wardrobe'] ?? ''));

            if ($name === '') {
                continue;
            }

            $charIdentityLock = "Character: {$name}.";
            if ($race !== '') {
                $charIdentityLock .= " Race/ethnicity: {$race}. Keep ethnicity strictly consistent — never change facial structure or race.";
            }
            if ($skinTone !== '') {
                $charIdentityLock .= " Skin tone: {$skinTone}.";
            }
            if ($appearance !== '') {
                $charIdentityLock .= " Appearance: {$appearance}.";
            }
            if ($wardrobe !== '') {
                $charIdentityLock .= " Wardrobe: {$wardrobe}.";
            }

            $prompt = trim("{$contextBlock}\n\n{$charIdentityLock}\n\nReference shot: close-up portrait of face and shoulders, neutral background. Capture distinct facial features clearly.");

            $relativePath = $this->buildSupportingCharacterRefPath((int) $project->id, $idx, $name);
            $absolutePath = storage_path('app/public/' . $relativePath);

            $result = $imageService->generateImage($prompt, $absolutePath, '1:1', 'gemini');
            if (!(bool) ($result['success'] ?? false)) {
                continue;
            }

            $characters[$idx]['reference_images'] = [
                [
                    'type' => 'face',
                    'path' => $relativePath,
                    'ratio' => '1:1',
                    'source' => 'ai_generated',
                    'created_at' => now()->toIso8601String(),
                ],
            ];
            $changed = true;

            $this->addUsageCost($project, 'image', 0.02);
            $project->refresh();
        }

        if ($changed) {
            $project->forceFill(['characters_json' => $characters])->save();
        }
    }

    private function buildIdentityLock(MediaCenterProject $project): string
    {
        $settings = is_array($project->settings_json) ? $project->settings_json : [];
        $charData = is_array($settings['main_character_data'] ?? null) ? $settings['main_character_data'] : [];

        $name = trim((string) ($charData['name'] ?? $project->main_character_name ?? ''));
        $race = trim((string) ($charData['race'] ?? ''));
        $skinTone = trim((string) ($charData['skin_tone'] ?? ''));
        $appearance = trim((string) ($charData['appearance'] ?? ''));
        $wardrobe = trim((string) ($charData['wardrobe'] ?? ''));
        $styleRules = trim((string) ($charData['style_consistency_rules'] ?? ''));

        // Fallback: parse profile text if structured data is unavailable
        if ($race === '' && $wardrobe === '') {
            $profile = trim((string) ($project->main_character_profile ?? ''));
            $race = $this->parseProfileField($profile, 'Race') ?: '';
            $wardrobe = $this->parseProfileField($profile, 'Wardrobe') ?: '';
            $skinTone = $this->parseProfileField($profile, 'Skin tone') ?: $skinTone;
        }

        $lines = ['Main character identity lock (must be consistent in all scenes):'];

        if ($name !== '') {
            $lines[] = 'Name: ' . $name . '.';
        }
        if ($race !== '') {
            $lines[] = 'Race/ethnicity: ' . $race . '. Never change facial structure, skin tone, or ethnicity across scenes.';
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

        $lines[] = 'Do not change face structure, hairline, eye shape, skin tone, age impression, or core racial features between images.';

        return trim(implode("\n", $lines));
    }

    private function buildContextBlock(array $settings): string
    {
        $style = trim((string) ($settings['image_style'] ?? 'Cinematic')) ?: 'Cinematic';
        $era = trim((string) ($settings['story_era'] ?? ''));
        $genre = trim((string) ($settings['story_genre'] ?? ''));
        $worldContext = trim((string) ($settings['world_context'] ?? ''));
        $forbidden = trim((string) ($settings['forbidden_elements'] ?? ''));

        $chunks = ['Visual style: ' . $style . '.'];
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

    private function buildMainCharacterRefPath(int $projectId, string $type): string
    {
        $micro = str_replace('.', '', (string) microtime(true));
        $rand = bin2hex(random_bytes(4));
        $typeSlug = preg_replace('/[^a-z0-9\-]+/', '-', strtolower(trim($type))) ?: 'ref';

        return 'media_center/' . $projectId . '/references/main_' . $typeSlug . '_' . $micro . '_' . $rand . '.png';
    }

    private function buildSupportingCharacterRefPath(int $projectId, int $idx, string $name): string
    {
        $micro = str_replace('.', '', (string) microtime(true));
        $rand = bin2hex(random_bytes(4));
        $nameSlug = preg_replace('/[^a-z0-9\-]+/', '-', strtolower(trim($name))) ?: 'char';

        return 'media_center/' . $projectId . '/references/char_' . $idx . '_' . $nameSlug . '_' . $micro . '_' . $rand . '.png';
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

    private function addUsageCost(MediaCenterProject $project, string $bucket, float $amountUsd): void
    {
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

        $key = in_array($bucket, ['image', 'video', 'audio', 'ai_generation'], true) ? $bucket : 'image';
        $costs[$key] = round(($costs[$key] ?? 0) + $amount, 6);

        $settings['usage_costs'] = [
            'image' => round($costs['image'], 6),
            'video' => round($costs['video'], 6),
            'audio' => round($costs['audio'], 6),
            'ai_generation' => round($costs['ai_generation'], 6),
            'total' => round($costs['image'] + $costs['video'] + $costs['audio'] + $costs['ai_generation'], 6),
        ];

        $project->forceFill(['settings_json' => $settings])->save();
    }
}
