<?php

namespace Tests\Feature;

use App\Jobs\EnrichVideoShotsJob;
use App\Models\AudioBook;
use App\Models\AudiobookCharacter;
use App\Models\AudiobookCharacterPhase;
use App\Models\AudiobookLocation;
use App\Models\AudiobookStoryBible;
use App\Models\AudiobookTimeline;
use App\Models\AudiobookVideoPipeline;
use App\Models\AudiobookVideoScene;
use App\Models\AudiobookVideoSceneCharacter;
use App\Services\VideoSceneAnalysisService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 3: proves enrichShotsChunk()'s FINAL prompt actually contains resolved Story
 * Bible / Character Bible data (identity anchor, current phase, location cultural context,
 * timeline/story phase, director treatment) — not just that the wiring compiles — plus the
 * baseline fallback when no phase applies, and that a Story Bible version bump triggers a
 * TARGETED re-run (only the stale scene/chunk), not a blanket restart.
 */
class StoryDirectionPromptTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.openai.api_key', 'test-key');
    }

    public function test_final_shot_prompt_contains_identity_phase_location_culture_and_timeline(): void
    {
        [$audioBook, $scene] = $this->makeBoundScene(withPhase: true);

        $capturedPrompt = null;
        Http::fake(function (Request $request) use (&$capturedPrompt) {
            $capturedPrompt = (string) data_get($request->data(), 'messages.0.content', '');
            return $this->fakeShotEnrichResponse($request);
        });

        (new EnrichVideoShotsJob($audioBook->id))->handle(app(VideoSceneAnalysisService::class));

        $this->assertNotNull($capturedPrompt);
        $this->assertStringContainsString('faded anchor tattoo', $capturedPrompt); // identity_anchor
        $this->assertStringContainsString('navy captain', $capturedPrompt); // PHASE trait, not baseline
        $this->assertStringContainsString('scar across the left eyebrow', $capturedPrompt); // phase injuries
        $this->assertStringContainsString('timber quay warehouses', $capturedPrompt); // location cultural_context
        $this->assertStringContainsString('northern trade coast', $capturedPrompt); // location region
        $this->assertStringContainsString('After the storm', $capturedPrompt); // story_phase
        $this->assertStringContainsString('Main Voyage', $capturedPrompt); // timeline label
        $this->assertStringContainsString('muted watercolor', $capturedPrompt); // director_treatment.visual_style
        $this->assertStringNotContainsString('plain deckhand overalls', $capturedPrompt); // baseline, should NOT appear when a phase applies
    }

    public function test_character_with_no_resolved_phase_falls_back_to_baseline_traits(): void
    {
        [$audioBook, $scene] = $this->makeBoundScene(withPhase: false);

        $capturedPrompt = null;
        Http::fake(function (Request $request) use (&$capturedPrompt) {
            $capturedPrompt = (string) data_get($request->data(), 'messages.0.content', '');
            return $this->fakeShotEnrichResponse($request);
        });

        (new EnrichVideoShotsJob($audioBook->id))->handle(app(VideoSceneAnalysisService::class));

        $this->assertNotNull($capturedPrompt);
        $this->assertStringContainsString('faded anchor tattoo', $capturedPrompt); // identity_anchor still applies
        $this->assertStringContainsString('plain deckhand overalls', $capturedPrompt); // BASELINE, since no phase resolved
        $this->assertStringNotContainsString('navy captain', $capturedPrompt); // no phase-specific text at all
        $this->assertStringNotContainsString('scar across the left eyebrow', $capturedPrompt);
    }

    public function test_story_bible_version_bump_only_regenerates_the_stale_scene_and_chunk(): void
    {
        [$audioBook, $staleScene, $currentScene, $newBible] = $this->makeTwoSceneStalenessFixture();

        $sceneAssignCalls = 0;
        $shotEnrichCalls = [];
        Http::fake(function (Request $request) use (&$sceneAssignCalls, &$shotEnrichCalls) {
            $prompt = (string) data_get($request->data(), 'messages.0.content', '');
            if (str_contains($prompt, 'BỐI CẢNH của MỘT CẢNH')) {
                $sceneAssignCalls++;
                return $this->fakeSceneAssignResponse();
            }
            $shotEnrichCalls[] = $prompt;
            return $this->fakeShotEnrichResponse($request);
        });

        $this->artisan('story-direction:regenerate-stale', ['audioBookId' => $audioBook->id])->assertSuccessful();

        // Only the genuinely-stale scene got re-assigned (one assignSceneContext HTTP call).
        $this->assertSame(1, $sceneAssignCalls);

        $staleScene->refresh();
        $currentScene->refresh();
        $this->assertSame($newBible->bible_version, $staleScene->story_bible_version_used);
        // The pre-stamped scene was left completely untouched by the reassignment.
        $this->assertSame($newBible->bible_version, $currentScene->story_bible_version_used);

        // Only the stale scene's chunk was regenerated (one shot_enrich HTTP call for it),
        // not a blanket re-run of every chunk in the book.
        $this->assertCount(1, $shotEnrichCalls);
    }

    /**
     * @return array{0:AudioBook,1:AudiobookVideoScene}
     */
    private function makeBoundScene(bool $withPhase): array
    {
        $audioBook = $this->makeAudioBook();

        $bible = AudiobookStoryBible::create([
            'audio_book_id' => $audioBook->id,
            'bible_version' => 1,
            'schema_version' => 'story_bible_v1',
            'status' => 'active',
            'is_active' => true,
            'director_treatment' => [
                'visual_style' => ['value' => 'muted watercolor tones with soft dawn lighting', 'confidence' => 'inferred', 'source_type' => 'director_choice', 'evidence' => [], 'rationale' => 'matches the maritime setting'],
            ],
        ]);

        $timeline = AudiobookTimeline::create([
            'story_bible_id' => $bible->id,
            'canonical_key' => 'main-voyage',
            'label' => 'Main Voyage',
            'timeline_type' => 'main',
            'chronological_order' => 1,
            'profile' => ['value' => ['story_time_marker' => 'the first crossing', 'description' => "the ship's maiden journey"], 'confidence' => 'confirmed', 'source_type' => 'explicit_text', 'evidence' => [['quote' => 'x']], 'rationale' => null],
        ]);

        $location = AudiobookLocation::create([
            'story_bible_id' => $bible->id,
            'canonical_name' => 'Skyport Docks',
            'aliases' => [],
            'cultural_context' => [
                'region' => ['value' => 'northern trade coast', 'confidence' => 'inferred', 'source_type' => 'inferred_from_text', 'evidence' => [['quote' => 'x']], 'rationale' => null],
                'architecture' => ['value' => 'timber quay warehouses', 'confidence' => 'inferred', 'source_type' => 'inferred_from_text', 'evidence' => [['quote' => 'x']], 'rationale' => null],
                'cultural_groups_present' => [],
            ],
        ]);

        $character = AudiobookCharacter::create([
            'story_bible_id' => $bible->id,
            'canonical_name' => 'Rin',
            'aliases' => [],
            'identity_anchor' => ['value' => ['defining_marks' => 'a faded anchor tattoo on the left forearm'], 'confidence' => 'confirmed', 'source_type' => 'explicit_text', 'evidence' => [['quote' => 'x']], 'rationale' => null],
            'baseline_traits' => ['value' => ['wardrobe' => 'plain deckhand overalls'], 'confidence' => 'confirmed', 'source_type' => 'explicit_text', 'evidence' => [['quote' => 'x']], 'rationale' => null],
        ]);

        $phase = AudiobookCharacterPhase::create([
            'character_id' => $character->id,
            'timeline_id' => $timeline->id,
            'current_location_id' => $location->id,
            'label' => 'Captain after the storm',
            'chronological_order' => 2,
            'mutable_traits' => ['value' => ['wardrobe' => "navy captain's coat with brass buttons", 'injuries' => 'a scar across the left eyebrow'], 'confidence' => 'confirmed', 'source_type' => 'explicit_text', 'evidence' => [['quote' => 'x']], 'rationale' => null],
        ]);

        $scene = AudiobookVideoScene::create([
            'audio_book_id' => $audioBook->id,
            'scene_index' => 1,
            'title' => 'At the docks',
            'script_text' => 'Rin đứng trên bến tàu, nhìn ra khơi xa, nhớ lại hành trình đầu tiên của mình.',
            'scene_type' => 'character',
            'story_bible_id' => $bible->id,
            'story_bible_version_used' => 1,
            'scene_direction_version' => VideoSceneAnalysisService::SCENE_DIRECTION_VERSION,
            'timeline_binding' => ['timeline_id' => $timeline->id, 'confidence' => 'confirmed', 'source_type' => 'explicit_text', 'evidence' => [], 'status' => 'resolved'],
            'location_binding' => ['location_id' => $location->id, 'confidence' => 'confirmed', 'source_type' => 'explicit_text', 'evidence' => [], 'status' => 'resolved', 'relevant_cultural_groups' => []],
            'story_phase' => 'After the storm',
        ]);

        AudiobookVideoSceneCharacter::create([
            'video_scene_id' => $scene->id,
            'character_id' => $character->id,
            'character_phase_id' => $withPhase ? $phase->id : null,
            'confidence' => 'confirmed',
            'source_type' => 'explicit_text',
            'evidence' => [],
            'resolution_status' => $withPhase ? 'resolved' : 'baseline_fallback',
        ]);

        return [$audioBook, $scene];
    }

    /**
     * @return array{0:AudioBook,1:AudiobookVideoScene,2:AudiobookVideoScene,3:AudiobookStoryBible}
     */
    private function makeTwoSceneStalenessFixture(): array
    {
        $audioBook = $this->makeAudioBook();

        $oldBible = AudiobookStoryBible::create([
            'audio_book_id' => $audioBook->id,
            'bible_version' => 1,
            'schema_version' => 'story_bible_v1',
            'status' => 'superseded',
            'is_active' => false,
        ]);

        $newBible = AudiobookStoryBible::create([
            'audio_book_id' => $audioBook->id,
            'bible_version' => 2,
            'schema_version' => 'story_bible_v1',
            'status' => 'active',
            'is_active' => true,
        ]);

        // Genuinely stale: still stamped with the OLD version.
        $staleScene = AudiobookVideoScene::create([
            'audio_book_id' => $audioBook->id,
            'scene_index' => 1,
            'title' => 'Scene A',
            'script_text' => 'Nội dung cảnh A.',
            'scene_type' => 'city',
            'story_bible_id' => $oldBible->id,
            'story_bible_version_used' => 1,
            'scene_direction_version' => VideoSceneAnalysisService::SCENE_DIRECTION_VERSION,
        ]);

        // Already handled some other way — pre-stamped with the CURRENT version, must be
        // skipped by the targeted regenerate command.
        $currentScene = AudiobookVideoScene::create([
            'audio_book_id' => $audioBook->id,
            'scene_index' => 2,
            'title' => 'Scene B',
            'script_text' => 'Nội dung cảnh B.',
            'scene_type' => 'city',
            'story_bible_id' => $newBible->id,
            'story_bible_version_used' => 2,
            'scene_direction_version' => VideoSceneAnalysisService::SCENE_DIRECTION_VERSION,
        ]);

        $staleShot = \App\Models\AudiobookVideoShot::create([
            'video_scene_id' => $staleScene->id,
            'shot_index' => 1,
            'sentence_text' => 'Nội dung cảnh A.',
            'enrichment_status' => 'enriched',
            'prompt_version' => VideoSceneAnalysisService::PROMPT_VERSION,
            'story_bible_version_used' => 1,
        ]);
        $currentShot = \App\Models\AudiobookVideoShot::create([
            'video_scene_id' => $currentScene->id,
            'shot_index' => 1,
            'sentence_text' => 'Nội dung cảnh B.',
            'enrichment_status' => 'enriched',
            'prompt_version' => VideoSceneAnalysisService::PROMPT_VERSION,
            'story_bible_version_used' => 2,
        ]);

        AudiobookVideoPipeline::create([
            'audio_book_id' => $audioBook->id,
            'status' => 'analyzed',
            'shot_chunks' => [
                ['index' => 0, 'scene_id' => $staleScene->id, 'status' => 'done', 'attempts' => 1, 'error' => null, 'shot_ids' => [$staleShot->id]],
                ['index' => 1, 'scene_id' => $currentScene->id, 'status' => 'done', 'attempts' => 1, 'error' => null, 'shot_ids' => [$currentShot->id]],
            ],
        ]);

        return [$audioBook, $staleScene, $currentScene, $newBible];
    }

    private function makeAudioBook(): AudioBook
    {
        $channel = \App\Models\YoutubeChannel::create(['channel_id' => 'UC_sd_' . uniqid(), 'title' => 'Test Channel']);
        return AudioBook::create(['youtube_channel_id' => $channel->id, 'title' => 'Story Direction Test Fixture']);
    }

    private function fakeSceneAssignResponse()
    {
        $content = [
            'timeline' => ['name' => 'Main Voyage', 'confidence' => 'confirmed', 'source_type' => 'explicit_text', 'evidence' => [['quote' => 'x']]],
            'location' => ['name' => 'Skyport Docks', 'confidence' => 'confirmed', 'source_type' => 'explicit_text', 'evidence' => [['quote' => 'x']], 'relevant_cultural_groups' => []],
            'story_phase' => 'After the storm',
            'characters_present' => [],
        ];

        return Http::response([
            'choices' => [['message' => ['content' => json_encode($content)], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 200, 'completion_tokens' => 100],
        ], 200, ['x-request-id' => 'req-' . uniqid()]);
    }

    private function fakeShotEnrichResponse(Request $request)
    {
        $prompt = (string) data_get($request->data(), 'messages.0.content', '');
        preg_match_all('/\[(\d+)\]/', $prompt, $matches);
        $indices = array_map('intval', $matches[1]) ?: [1];

        $shots = array_map(fn($idx) => [
            'index' => $idx,
            'is_real_world' => true,
            'keywords' => ['test'],
            'image_request' => 'A test image request.',
        ], $indices);

        return Http::response([
            'choices' => [['message' => ['content' => json_encode(['shots' => $shots])], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 300, 'completion_tokens' => 150],
        ], 200, ['x-request-id' => 'req-' . uniqid()]);
    }
}
