<?php

namespace App\Services;

use App\Models\MediaCenterProject;
use App\Models\MediaCenterSentence;
use Illuminate\Support\Facades\Http;

class MediaCenterAnalyzeService
{
    /**
     * @return array<string,mixed>
     */
    public function buildCharacterAndPromptPlan(MediaCenterProject $project): array
    {
        $world = $this->buildWorldProfile($project);
        $apiKey = (string) config('services.openai.api_key', '');

        if ($apiKey === '') {
            return $this->buildFallbackPlan($project);
        }

        $baseUrl = rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/');
        $model = (string) config('services.openai.chat_model', 'gpt-4o-mini');

        $sentenceLines = $project->sentences
            ->map(function (MediaCenterSentence $sentence) {
                return '[' . $sentence->sentence_index . '] ' . $sentence->sentence_text;
            })
            ->implode("\n");

        $sentenceTextByIndex = [];
        foreach ($project->sentences as $sentence) {
            $sentenceTextByIndex[(int) $sentence->sentence_index] = trim((string) $sentence->sentence_text);
        }

        $prompt = "Bạn là media planner cho pipeline TTS + Image + Video.\n"
            . "Từ văn bản dưới đây, hãy tạo JSON gồm:\n"
            . "1) main_character: name, gender, race, skin_tone, appearance, wardrobe, style_consistency_rules\n"
            . "2) characters: danh sách nhân vật phụ (mỗi nhân vật có name, gender, race, skin_tone, appearance, wardrobe)\n"
            . "3) sentences: map theo index câu, mỗi câu gồm tts_text, image_prompt, video_prompt, character_notes, involves_main_character, costume_override, scene_id\n"
            . "Yêu cầu cứng:\n"
            . "- Prompt ảnh/video phải giữ đồng nhất nhân vật chính xuyên suốt.\n"
            . "- Bắt buộc mô tả gender, race và skin_tone rõ ràng cho nhân vật chính và phụ.\n"
            . "- image_prompt cần cụ thể góc máy, ánh sáng, bối cảnh.\n"
            . "- video_prompt mô tả chuyển động camera và hành động nhân vật từ image_prompt.\n"
            . "- Mọi image_prompt phải tuân thủ tỉ lệ khung hình và style hình ảnh được chỉ định trong hồ sơ bối cảnh.\n"
            . "- image_prompt phải là tiếng Anh, độ dài 90-160 từ, KHÔNG chung chung; bắt buộc có: subject action, environment/props, camera framing, lighting/color mood, texture/material details, continuity constraints.\n"
            . "- video_prompt phải là tiếng Anh, mô tả motion rõ ràng theo shot, tránh jump-cut, giữ continuity nhân vật/phục trang.\n"
            . "- Tránh cụm mơ hồ kiểu 'a person in a room', 'beautiful scene', 'cinematic image' nếu không có chi tiết cụ thể đi kèm.\n"
            . "- TUYỆT ĐỐI không dùng bối cảnh/đồ vật hiện đại nếu không khớp thời đại câu chuyện.\n"
            . "- TUYỆT ĐỐI bám hồ sơ bối cảnh bên dưới.\n"
            . "- TUYỆT ĐỐI không đổi ethnicity/chủng tộc nhân vật theo wardrobe/mô tả trong câu; race/skin_tone phải nhất quán xuyên suốt.\n"
            . "- involves_main_character: true nếu câu mô tả hành động/hiện diện vật lý nhân vật chính; false nếu là cảnh vật/cảm xúc trừu tượng không hiển thị nhân vật.\n"
            . "- costume_override: nếu câu CHỈ RÕ nhân vật đang mặc trang phục KHÁC với wardrobe mặc định của nhân vật chính thì mô tả ngắn bằng tiếng Anh (vd: 'monk robe and straw hat'); để chuỗi rỗng nếu trang phục không thay đổi hoặc không nhắc đến.\n"
            . "- scene_id: số nguyên bắt đầu từ 1, tăng khi câu chuyển sang địa điểm/bối cảnh vật lý khác (cùng phòng/đường/rừng = cùng scene_id).\n"
            . "Trả STRICT JSON, không markdown.\n\n"
            . "Hồ sơ bối cảnh:\n"
            . "- Thời đại: {$world['story_era']}\n"
            . "- Thể loại: {$world['story_genre']}\n"
            . "- Bối cảnh thế giới: {$world['world_context']}\n"
            . "- Tỉ lệ khung ảnh: {$world['image_aspect_ratio']}\n"
            . "- Thể loại/phong cách ảnh: {$world['image_style']}\n"
            . "- Yếu tố cấm: {$world['forbidden_elements_text']}\n\n"
            . "Danh sách câu:\n"
            . $sentenceLines;

        $response = Http::acceptJson()
            ->withToken($apiKey)
            ->timeout(120)
            ->post($baseUrl . '/chat/completions', [
                'model' => $model,
                'temperature' => 0.4,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Trả JSON hợp lệ, không bịa dữ liệu không có trong văn bản.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
            ]);

        if (!$response->successful()) {
            return $this->buildFallbackPlan($project);
        }

        $content = trim((string) data_get($response->json(), 'choices.0.message.content', ''));
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return $this->buildFallbackPlan($project);
        }

        $mainCharacter = is_array($decoded['main_character'] ?? null) ? $decoded['main_character'] : [];
        $mainCharacter = $this->normalizeCharacterIdentity($mainCharacter, true);

        $characters = [];
        $rawCharacters = is_array($decoded['characters'] ?? null) ? $decoded['characters'] : [];
        foreach ($rawCharacters as $character) {
            if (!is_array($character)) {
                continue;
            }
            $characters[] = $this->normalizeCharacterIdentity($character, false);
        }

        $sentences = [];
        $rows = is_array($decoded['sentences'] ?? null) ? $decoded['sentences'] : [];

        if (!empty($rows) && array_keys($rows) !== range(0, count($rows) - 1)) {
            $normalized = [];
            foreach ($rows as $key => $row) {
                if (!is_array($row)) {
                    continue;
                }
                if (!isset($row['index'])) {
                    $row['index'] = (int) $key;
                }
                $normalized[] = $row;
            }
            $rows = $normalized;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $index = (int) ($row['index'] ?? 0);
            if ($index <= 0) {
                continue;
            }

            $sentences[$index] = [
                'tts_text' => trim((string) ($row['tts_text'] ?? '')),
                'image_prompt' => trim((string) ($row['image_prompt'] ?? '')),
                'video_prompt' => trim((string) ($row['video_prompt'] ?? '')),
                'character_notes' => trim((string) ($row['character_notes'] ?? '')),
                'involves_main_character' => isset($row['involves_main_character']) ? (bool) $row['involves_main_character'] : true,
                'costume_override' => trim((string) ($row['costume_override'] ?? '')),
                'scene_id' => max(1, (int) ($row['scene_id'] ?? 1)),
            ];

            $sourceSentence = $sentenceTextByIndex[$index] ?? trim((string) ($row['sentence'] ?? ''));
            $sentences[$index] = $this->enforceSentencePlanQuality(
                $sentences[$index],
                $sourceSentence,
                $mainCharacter,
                $world,
                $index
            );
        }

        foreach ($project->sentences as $sentence) {
            if (!isset($sentences[$sentence->sentence_index])) {
                $baseText = trim((string) ($sentence->tts_text ?: $sentence->sentence_text));
                $sentences[$sentence->sentence_index] = $this->buildDefaultSentencePlan(
                    $baseText,
                    $mainCharacter
                );

                $sentences[$sentence->sentence_index] = $this->enforceSentencePlanQuality(
                    $sentences[$sentence->sentence_index],
                    $baseText,
                    $mainCharacter,
                    $world
                );
            }
        }

        $mainProfile = [];
        foreach (['name', 'gender', 'race', 'skin_tone', 'appearance', 'wardrobe', 'style_consistency_rules'] as $field) {
            $value = trim((string) ($mainCharacter[$field] ?? ''));
            if ($value !== '') {
                $mainProfile[] = ucfirst(str_replace('_', ' ', $field)) . ': ' . $value;
            }
        }

        return [
            'main_character' => $mainCharacter,
            'main_character_profile_text' => implode("\n", $mainProfile),
            'characters' => $characters,
            'sentences' => $sentences,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function buildFallbackPlan(MediaCenterProject $project): array
    {
        $world = $this->buildWorldProfile($project);
        $sentences = [];
        $mainCharacter = $this->normalizeCharacterIdentity([], true);

        foreach ($project->sentences as $sentence) {
            $baseText = trim((string) ($sentence->tts_text ?: $sentence->sentence_text));
            $sentences[$sentence->sentence_index] = $this->buildDefaultSentencePlan(
                $baseText,
                $mainCharacter
            );

            $sentences[$sentence->sentence_index] = $this->enforceSentencePlanQuality(
                $sentences[$sentence->sentence_index],
                $baseText,
                $mainCharacter,
                $world
            );
        }

        return [
            'main_character' => $mainCharacter,
            'main_character_profile_text' => "Name: {$mainCharacter['name']}\nGender: {$mainCharacter['gender']}\nRace: {$mainCharacter['race']}\nSkin tone: {$mainCharacter['skin_tone']}\nAppearance: {$mainCharacter['appearance']}\nWardrobe: {$mainCharacter['wardrobe']}",
            'characters' => [],
            'sentences' => $sentences,
        ];
    }

    /**
     * @return array{story_era:string,story_genre:string,world_context:string,image_aspect_ratio:string,image_style:string,forbidden_elements:array<int,string>,forbidden_elements_text:string}
     */
    private function buildWorldProfile(MediaCenterProject $project): array
    {
        $settings = is_array($project->settings_json) ? $project->settings_json : [];

        $storyEra = trim((string) ($settings['story_era'] ?? '')) ?: 'Thời cổ đại';
        $storyGenre = trim((string) ($settings['story_genre'] ?? '')) ?: 'Kiếm hiệp / cổ trang';
        $worldContext = trim((string) ($settings['world_context'] ?? ''))
            ?: 'Bối cảnh cổ trang phương Đông, không có công nghệ hiện đại.';
        $imageAspectRatio = trim((string) ($settings['image_aspect_ratio'] ?? '')) ?: '16:9';
        if (!in_array($imageAspectRatio, ['16:9', '9:16', '1:1'], true)) {
            $imageAspectRatio = '16:9';
        }
        $imageStyle = trim((string) ($settings['image_style'] ?? '')) ?: 'Cinematic';

        $forbiddenRaw = trim((string) ($settings['forbidden_elements'] ?? ''));
        $defaultForbidden = [
            'văn phòng',
            'tòa nhà kính',
            'xe hơi',
            'điện thoại',
            'máy tính',
            'đèn neon',
            'quán cafe hiện đại',
        ];

        $custom = [];
        if ($forbiddenRaw !== '') {
            $parts = preg_split('/[,;\n]+/u', $forbiddenRaw) ?: [];
            foreach ($parts as $part) {
                $item = trim((string) $part);
                if ($item !== '') {
                    $custom[] = mb_strtolower($item);
                }
            }
        }

        $forbidden = array_values(array_unique(array_merge($defaultForbidden, $custom)));

        return [
            'story_era' => $storyEra,
            'story_genre' => $storyGenre,
            'world_context' => $worldContext,
            'image_aspect_ratio' => $imageAspectRatio,
            'image_style' => $imageStyle,
            'forbidden_elements' => $forbidden,
            'forbidden_elements_text' => implode(', ', $forbidden),
        ];
    }

    /**
     * @param array{tts_text:string,image_prompt:string,video_prompt:string,character_notes:string} $plan
     * @param array{story_era:string,story_genre:string,world_context:string,image_aspect_ratio:string,image_style:string,forbidden_elements:array<int,string>,forbidden_elements_text:string} $world
     * @return array{tts_text:string,image_prompt:string,video_prompt:string,character_notes:string}
     */
    private function sanitizeSentencePlanByWorld(array $plan, array $world): array
    {
        $imagePrompt = (string) ($plan['image_prompt'] ?? '');
        $videoPrompt = (string) ($plan['video_prompt'] ?? '');

        // Remove legacy verbose template fragments so cleanup can produce concise prompts.
        $legacyPatterns = [
            '/\bCinematic\s+keyframe\s+for\s+shot\s*\d+\.?/iu',
            '/\bCinematic\s+keyframe\s+for\s+this\s+shot\.?/iu',
            '/\bStory\s+action:\s*/iu',
            '/\bComposition:\s*[^.]*\.?/iu',
            '/\bCamera:\s*[^.]*\.?/iu',
            '/\bLighting:\s*[^.]*\.?/iu',
            '/\bContinuity:\s*[^.]*\.?/iu',
            '/\bNo\s+modern\s+props\.?/iu',
            '/\bNo\s+text\/logo\/watermark\.?/iu',
            '/\bShot\s+lock:\s*[^.]*\.?/iu',
            '/\bGlobal\s+lock:\s*[^.]*\.?/iu',
        ];

        $imagePrompt = preg_replace($legacyPatterns, ' ', $imagePrompt) ?: $imagePrompt;
        $imagePrompt = preg_replace('/\bScene\s+action:\s*/iu', '', $imagePrompt) ?: $imagePrompt;

        foreach ($world['forbidden_elements'] as $forbidden) {
            if ($forbidden === '') {
                continue;
            }

            $pattern = '/' . preg_quote($forbidden, '/') . '/iu';

            if (preg_match($pattern, $imagePrompt)) {
                $imagePrompt = preg_replace(
                    $pattern,
                    'không gian cổ trang đúng thời đại',
                    $imagePrompt
                ) ?: $imagePrompt;
            }

            if (preg_match($pattern, $videoPrompt)) {
                $videoPrompt = preg_replace(
                    $pattern,
                    'không gian cổ trang đúng thời đại',
                    $videoPrompt
                ) ?: $videoPrompt;
            }
        }

        $plan['image_prompt'] = trim((string) preg_replace('/\s+/u', ' ', $imagePrompt));
        $plan['video_prompt'] = trim((string) preg_replace('/\s+/u', ' ', $videoPrompt));
        $plan['character_notes'] = '';

        return $plan;
    }

    /**
     * @param array<string,mixed> $mainCharacter
     * @return array{tts_text:string,image_prompt:string,video_prompt:string,character_notes:string}
     */
    public function buildDefaultSentencePlan(string $text, array $mainCharacter): array
    {
        $cleanText = trim($text);
        $normalized = $this->normalizeCharacterIdentity($mainCharacter, true);
        $sceneAction = $this->rewriteNarrativeToVisualAction($cleanText, (string) ($normalized['name'] ?? ''));
        $imagePrompt = $sceneAction;

        $videoPrompt = "Animate this scene with subtle motion while preserving character identity from attached refs. "
            . "Camera motion: slow push-in with light parallax. "
            . "Character motion: natural micro expressions and body movement for action: {$sceneAction}.";

        $characterNotes = '';

        return [
            'tts_text' => $cleanText,
            'image_prompt' => $imagePrompt,
            'video_prompt' => $videoPrompt,
            'character_notes' => $characterNotes,
            'involves_main_character' => true,
            'costume_override' => '',
            'scene_id' => 1,
        ];
    }

    /**
     * @param array{tts_text:string,image_prompt:string,video_prompt:string,character_notes:string} $plan
     * @param array<string,string> $mainCharacter
     * @param array{story_era:string,story_genre:string,world_context:string,forbidden_elements:array<int,string>,forbidden_elements_text:string} $world
     * @return array{tts_text:string,image_prompt:string,video_prompt:string,character_notes:string}
     */
    private function enforceSentencePlanQuality(
        array $plan,
        string $sourceSentence,
        array $mainCharacter,
        array $world,
        int $shotIndex = 0
    ): array {
        $normalized = $this->normalizeCharacterIdentity($mainCharacter, true);

        $ttsText = trim((string) ($plan['tts_text'] ?? ''));
        if ($ttsText === '') {
            $ttsText = trim($sourceSentence);
        }
        $plan['tts_text'] = $ttsText;

        $imagePrompt = trim((string) ($plan['image_prompt'] ?? ''));
        if ($this->isNarrativeQuestionPrompt($imagePrompt)) {
            $imagePrompt = $this->rewriteNarrativeToVisualAction($imagePrompt, (string) ($normalized['name'] ?? ''));
            $plan['image_prompt'] = $imagePrompt;
        }

        if ($this->isWeakImagePrompt($imagePrompt)) {
            $plan['image_prompt'] = $this->rewriteNarrativeToVisualAction(
                trim($ttsText !== '' ? $ttsText : $sourceSentence),
                (string) ($normalized['name'] ?? '')
            );
        }

        $videoPrompt = trim((string) ($plan['video_prompt'] ?? ''));
        if ($videoPrompt === '' || mb_strlen($videoPrompt) < 45) {
            $plan['video_prompt'] = $this->buildDetailedVideoPrompt($ttsText !== '' ? $ttsText : $sourceSentence, $normalized, $world, $shotIndex);
        }

        $plan['character_notes'] = '';

        return $this->sanitizeSentencePlanByWorld($plan, $world);
    }

    /**
     * @param array<string,string> $mainCharacter
    * @param array{story_era:string,story_genre:string,world_context:string,image_aspect_ratio:string,image_style:string,forbidden_elements:array<int,string>,forbidden_elements_text:string} $world
     */
    private function buildDetailedImagePrompt(string $sentenceText, array $mainCharacter, array $world, int $shotIndex = 0): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $sentenceText));
    }

    /**
     * @param array<string,string> $mainCharacter
    * @param array{story_era:string,story_genre:string,world_context:string,image_aspect_ratio:string,image_style:string,forbidden_elements:array<int,string>,forbidden_elements_text:string} $world
     */
    private function buildDetailedVideoPrompt(string $sentenceText, array $mainCharacter, array $world, int $shotIndex = 0): string
    {
        $shotLabel = $shotIndex > 0 ? "shot {$shotIndex}" : 'current shot';

        return trim((string) preg_replace('/\s+/u', ' ',
            "Create image-to-video motion for {$shotLabel} while preserving shared identity of {$mainCharacter['name']} from project refs. "
            . "Action anchor: {$sentenceText}. "
            . "Camera motion: slow push-in, light parallax, stable shot. "
            . "Character motion: subtle micro movement aligned with action. "
            . "Continuity: keep identity/costume unchanged, no modern objects. "
            . "Visual lock: ratio {$world['image_aspect_ratio']}, style {$world['image_style']}."
        ));
    }

    /**
     * @param array<string,mixed> $mainCharacter
     * @return array{changed:bool,plan:array{tts_text:string,image_prompt:string,video_prompt:string,character_notes:string}}
     */
    public function regenerateWeakPromptForSentence(
        MediaCenterProject $project,
        MediaCenterSentence $sentence,
        array $mainCharacter = []
    ): array {
        $world = $this->buildWorldProfile($project);
        $normalizedMain = $this->normalizeCharacterIdentity($mainCharacter, true);

        $currentPlan = [
            'tts_text' => trim((string) ($sentence->tts_text ?: $sentence->sentence_text)),
            'image_prompt' => trim((string) ($sentence->image_prompt ?? '')),
            'video_prompt' => trim((string) ($sentence->video_prompt ?? '')),
            'character_notes' => trim((string) ($sentence->character_notes ?? '')),
        ];

        $needsRegenerate = $this->isWeakImagePrompt($currentPlan['image_prompt'])
            || $this->isWeakVideoPrompt($currentPlan['video_prompt']);

        if (!$needsRegenerate) {
            return [
                'changed' => false,
                'plan' => $currentPlan,
            ];
        }

        $regenerated = $this->enforceSentencePlanQuality(
            $currentPlan,
            (string) $sentence->sentence_text,
            $normalizedMain,
            $world,
            (int) $sentence->sentence_index
        );

        $changed = $regenerated['tts_text'] !== $currentPlan['tts_text']
            || $regenerated['image_prompt'] !== $currentPlan['image_prompt']
            || $regenerated['video_prompt'] !== $currentPlan['video_prompt'];

        return [
            'changed' => $changed,
            'plan' => $regenerated,
        ];
    }

    /**
     * @param array<string,mixed> $mainCharacter
     * @return array{changed:bool,plan:array{tts_text:string,image_prompt:string,video_prompt:string,character_notes:string}}
     */
    public function regeneratePromptForSentenceByStoryContext(
        MediaCenterProject $project,
        MediaCenterSentence $sentence,
        array $mainCharacter = []
    ): array {
        $world = $this->buildWorldProfile($project);
        $normalizedMain = $this->normalizeCharacterIdentity($mainCharacter, true);

        $baseText = trim((string) ($sentence->tts_text ?: $sentence->sentence_text));
        if ($baseText === '') {
            $baseText = trim((string) ($sentence->sentence_text ?? ''));
        }

        $fallback = $this->rebuildSentencePlanForMainCharacterProfile($project, $sentence, $normalizedMain);
        $fallbackPlan = [
            'tts_text' => (string) ($fallback['tts_text'] ?? $baseText),
            'image_prompt' => (string) ($fallback['image_prompt'] ?? ''),
            'video_prompt' => (string) ($fallback['video_prompt'] ?? ''),
            'character_notes' => '',
        ];

        $apiKey = trim((string) config('services.openai.api_key', ''));
        if ($apiKey === '') {
            return [
                'changed' => $this->isPlanChangedComparedToSentence($sentence, $fallbackPlan),
                'plan' => $fallbackPlan,
            ];
        }

        $baseUrl = rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/');
        $model = (string) config('services.openai.chat_model', 'gpt-4o-mini');
        $settings = is_array($project->settings_json ?? null) ? $project->settings_json : [];

        $storySummary = trim((string) ($project->source_text ?? ''));
        if ($storySummary !== '') {
            $storySummary = mb_substr($storySummary, 0, 1200);
        }

        $forbiddenText = trim((string) ($world['forbidden_elements_text'] ?? ''));
        $identityHint = trim((string) ($settings['main_character_identity_lock'] ?? ''));
        if ($identityHint === '') {
            $identityHint = trim((string) ($project->main_character_profile ?? ''));
        }

        $instruction = "Bạn là biên kịch storyboard cho ảnh minh họa từng câu.\n"
            . "Viết lại 1 câu thành prompt ảnh/video theo đúng ngữ cảnh câu chuyện.\n"
            . "Ràng buộc:\n"
            . "- image_prompt: tiếng Việt, 1 câu ngắn 12-40 từ, mô tả hành động nhìn thấy được.\n"
            . "- Không dùng cụm meta như cinematic keyframe, global lock, composition, camera, lighting.\n"
            . "- Nếu câu mang tính tu từ/trừu tượng thì chuyển thành hành động cụ thể trong bối cảnh truyện.\n"
            . "- Giữ đồng nhất nhân vật theo reference đã có, không mô tả trait dài dòng.\n"
            . "- TUYỆT ĐỐI không đổi ethnicity/nguồn gốc nhân vật. Nếu bối cảnh Trung Hoa cổ đại/Bắc Tống thì nhân vật phải là Đông Á (Trung Hoa), không được mô tả Caucasian/Western.\n"
            . "- Không tự thêm yếu tố fantasy lệch bối cảnh (phù thủy, pháp sư, đồ ma thuật) nếu câu gốc không có.\n"
            . "- Tránh yếu tố hiện đại: {$forbiddenText}.\n"
            . "- video_prompt ngắn gọn, bám action trong image_prompt.\n"
            . "Trả JSON với keys: tts_text, image_prompt, video_prompt, character_notes.";

        $context = "Thời đại: {$world['story_era']}\n"
            . "Thể loại: {$world['story_genre']}\n"
            . "Bối cảnh: {$world['world_context']}\n"
            . "Style: {$world['image_style']} | Ratio: {$world['image_aspect_ratio']}\n"
            . "Nhân vật chính: {$normalizedMain['name']}\n"
            . "Race: {$normalizedMain['race']} | Skin tone: {$normalizedMain['skin_tone']}\n"
            . "Identity hint: {$identityHint}\n"
            . "Tóm tắt truyện: {$storySummary}\n"
            . "Câu cần minh họa: {$baseText}";

        $response = Http::acceptJson()
            ->withToken($apiKey)
            ->timeout(60)
            ->post($baseUrl . '/chat/completions', [
                'model' => $model,
                'temperature' => 0.35,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => 'Trả JSON hợp lệ, không markdown.'],
                    ['role' => 'user', 'content' => $instruction . "\n\n" . $context],
                ],
            ]);

        if (!$response->successful()) {
            return [
                'changed' => $this->isPlanChangedComparedToSentence($sentence, $fallbackPlan),
                'plan' => $fallbackPlan,
            ];
        }

        $content = trim((string) data_get($response->json(), 'choices.0.message.content', ''));
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return [
                'changed' => $this->isPlanChangedComparedToSentence($sentence, $fallbackPlan),
                'plan' => $fallbackPlan,
            ];
        }

        $candidatePlan = [
            'tts_text' => trim((string) ($decoded['tts_text'] ?? $baseText)),
            'image_prompt' => trim((string) ($decoded['image_prompt'] ?? '')),
            'video_prompt' => trim((string) ($decoded['video_prompt'] ?? '')),
            'character_notes' => '',
        ];

        $candidatePlan = $this->enforceSentencePlanQuality(
            $candidatePlan,
            $baseText,
            $normalizedMain,
            $world,
            (int) $sentence->sentence_index
        );

        if (trim((string) ($candidatePlan['image_prompt'] ?? '')) === '') {
            $candidatePlan = $fallbackPlan;
        }

        return [
            'changed' => $this->isPlanChangedComparedToSentence($sentence, $candidatePlan),
            'plan' => $candidatePlan,
        ];
    }

    /**
     * @param array{tts_text:string,image_prompt:string,video_prompt:string,character_notes:string} $plan
     */
    private function isPlanChangedComparedToSentence(MediaCenterSentence $sentence, array $plan): bool
    {
        return trim((string) ($plan['tts_text'] ?? '')) !== trim((string) ($sentence->tts_text ?? $sentence->sentence_text ?? ''))
            || trim((string) ($plan['image_prompt'] ?? '')) !== trim((string) ($sentence->image_prompt ?? ''))
            || trim((string) ($plan['video_prompt'] ?? '')) !== trim((string) ($sentence->video_prompt ?? ''))
            || trim((string) ($plan['character_notes'] ?? '')) !== trim((string) ($sentence->character_notes ?? ''));
    }

    /**
     * Build a fresh sentence plan using the current main-character profile.
     *
     * @param array<string,mixed> $mainCharacter
     * @return array{tts_text:string,image_prompt:string,video_prompt:string,character_notes:string,main_character:array<string,string>}
     */
    public function rebuildSentencePlanForMainCharacterProfile(
        MediaCenterProject $project,
        MediaCenterSentence $sentence,
        array $mainCharacter = []
    ): array {
        $world = $this->buildWorldProfile($project);
        $normalizedMain = $this->normalizeCharacterIdentity($mainCharacter, true);
        $baseText = trim((string) ($sentence->tts_text ?: $sentence->sentence_text));

        $basePlan = $this->buildDefaultSentencePlan($baseText, $normalizedMain);
        $basePlan['tts_text'] = $baseText;

        $plan = $this->enforceSentencePlanQuality(
            $basePlan,
            $baseText,
            $normalizedMain,
            $world,
            (int) $sentence->sentence_index
        );

        return [
            'tts_text' => $plan['tts_text'],
            'image_prompt' => $plan['image_prompt'],
            'video_prompt' => $plan['video_prompt'],
            'character_notes' => $plan['character_notes'],
            'main_character' => $normalizedMain,
        ];
    }

    public function isWeakVideoPrompt(string $prompt): bool
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', $prompt));
        if ($clean === '') {
            return true;
        }

        $words = array_values(array_filter(preg_split('/\s+/u', $clean) ?: []));
        if (count($words) < 22) {
            return true;
        }

        $lower = mb_strtolower($clean);
        $signals = ['motion', 'camera', 'push-in', 'parallax', 'continuity', 'micro', 'movement'];
        $hits = 0;
        foreach ($signals as $signal) {
            if (mb_strpos($lower, $signal) !== false) {
                $hits++;
            }
        }

        return $hits < 2;
    }

    public function isWeakImagePrompt(string $prompt): bool
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', $prompt));
        if ($clean === '') {
            return true;
        }

        $words = array_values(array_filter(preg_split('/\s+/u', $clean) ?: []));
        $wordCount = count($words);
        if ($wordCount < 6) {
            return true;
        }

        return false;
    }

    private function isNarrativeQuestionPrompt(string $text): bool
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($clean === '') {
            return false;
        }

        $lower = mb_strtolower($clean);
        if (str_contains($clean, '?')) {
            return true;
        }

        return str_starts_with($lower, 'tại sao')
            || str_starts_with($lower, 'tai sao')
            || str_starts_with($lower, 'vì sao')
            || str_starts_with($lower, 'vi sao')
            || str_starts_with($lower, 'sao lại');
    }

    private function rewriteNarrativeToVisualAction(string $text, string $mainCharacterName = ''): string
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', $text));
        $clean = trim($clean, " \t\n\r\0\x0B\"'");
        if ($clean === '') {
            return $clean;
        }

        if (!$this->isNarrativeQuestionPrompt($clean) && !$this->isAbstractNarrativeSentence($clean)) {
            return $clean;
        }

        $subject = trim($mainCharacterName) !== '' ? trim($mainCharacterName) : 'Nhân vật chính';
        $lower = mb_strtolower($clean);

        $location = 'con đường rừng núi';
        if (str_contains($lower, 'quan lộ') || str_contains($lower, 'đường lớn')) {
            $location = 'con đường quan lộ';
        } elseif (str_contains($lower, 'ngõ') || str_contains($lower, 'hẻm')) {
            $location = 'con ngõ hẹp';
        }

        $chaser = str_contains($lower, 'quan quân') || str_contains($lower, 'pháp luật') || str_contains($lower, 'quan sai')
            ? 'quan quân nhà Tống'
            : 'những kẻ truy đuổi';

        $disguise = (str_contains($lower, 'áo tu hành') || str_contains($lower, 'tu hành') || str_contains($lower, 'nhà sư'))
            ? ' trong bộ áo tu hành'
            : '';

        return $subject . ' đang bước nhanh trên ' . $location . $disguise . ', phía sau là ' . $chaser . ' đang truy đuổi.';
    }

    private function isAbstractNarrativeSentence(string $text): bool
    {
        $lower = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
        if ($lower === '') {
            return false;
        }

        $abstractSignals = [
            'liem chinh', 'liêm chính', 'chính nghĩa', 'số phận', 'định mệnh',
            'nghĩa khí', 'uẩn khúc', 'bi kịch', 'trốn chạy pháp luật', 'đau đớn',
            'nội tâm', 'day dứt', 'oan khuất', 'quan trường', 'giang hồ',
        ];

        foreach ($abstractSignals as $signal) {
            if (str_contains($lower, $signal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $character
     * @return array<string,string>
     */
    public function normalizeCharacterIdentity(array $character, bool $isMainCharacter): array
    {
        $nameDefault = $isMainCharacter ? 'Nhân vật chính' : 'Nhân vật phụ';

        return [
            'name' => trim((string) ($character['name'] ?? '')) ?: $nameDefault,
            'gender' => trim((string) ($character['gender'] ?? '')) ?: 'unspecified',
            'race' => trim((string) ($character['race'] ?? '')) ?: 'Asian',
            'skin_tone' => trim((string) ($character['skin_tone'] ?? '')) ?: 'light-medium',
            'appearance' => trim((string) ($character['appearance'] ?? '')) ?: 'consistent facial features across scenes',
            'wardrobe' => trim((string) ($character['wardrobe'] ?? '')) ?: 'consistent wardrobe and style',
            'style_consistency_rules' => trim((string) ($character['style_consistency_rules'] ?? ''))
                ?: 'Do not change gender, race, skin tone, face shape, or core costume details.',
        ];
    }
}
