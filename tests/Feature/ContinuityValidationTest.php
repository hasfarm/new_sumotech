<?php

namespace Tests\Feature;

use App\Jobs\ValidateStoryContinuityJob;
use App\Models\AudioBook;
use App\Models\AudiobookCharacter;
use App\Models\AudiobookCharacterPhase;
use App\Models\AudiobookContinuityIssue;
use App\Models\AudiobookLocation;
use App\Models\AudiobookStoryBible;
use App\Models\AudiobookTimeline;
use App\Models\AudiobookVideoPipeline;
use App\Models\AudiobookVideoScene;
use App\Models\AudiobookVideoSceneCharacter;
use App\Models\AudiobookVideoShot;
use App\Models\YoutubeChannel;
use App\Services\ContinuityValidationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 4: ValidateStoryContinuityJob / ContinuityValidationService. All AI calls are
 * Http::fake'd (deterministic) — these tests validate OUR pipeline mechanics (severity/
 * action derivation, fingerprint upsert, selective regeneration, resolve-only-after-confirm),
 * not a real model's judgment.
 */
class ContinuityValidationTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.openai.api_key', 'test-key');
    }

    public function test_baseline_character_without_phase_is_valid(): void
    {
        [$audioBook, $bible, $scene] = $this->makeFixture();
        $shot = $this->makeShot($scene, 1, 'Rin đứng trên bến tàu.');
        $character = $this->makeCharacter($bible, 'Rin', ['gender' => 'female'], ['wardrobe' => 'plain overalls']);
        $this->bindCharacter($scene, $character, null, 'baseline_fallback');

        Http::fake(fn(Request $r) => $this->routeFake($r, []));

        $service = app(ContinuityValidationService::class);
        $run = $this->makeRun($audioBook);
        $service->validateScene($scene->fresh(), $bible, $run);

        $shot->refresh();
        $this->assertSame('valid', $shot->validation_status);
        $this->assertCount(0, AudiobookContinuityIssue::where('video_shot_id', $shot->id)->get());
    }

    public function test_wrong_phase_assigned_produces_invalid_shot(): void
    {
        [$audioBook, $bible, $scene, $timeline] = $this->makeFixture();
        $shot = $this->makeShot($scene, 1, 'Rin giờ đây là thủ lĩnh với vết sẹo trên tay.');
        $character = $this->makeCharacter($bible, 'Rin', ['gender' => 'female'], ['wardrobe' => 'plain overalls']);
        $wrongPhase = $this->makePhase($character, $timeline, 'Young apprentice', 1, ['wardrobe' => 'plain overalls', 'injuries' => null]);
        $this->bindCharacter($scene, $character, $wrongPhase, 'resolved');

        Http::fake(fn(Request $r) => $this->routeFake($r, [
            $this->semanticIssue('shot', 1, AudiobookContinuityIssue::TYPE_PHASE_MISMATCH, 'confirmed', 'scarred captain', 'young apprentice'),
        ]));

        $service = app(ContinuityValidationService::class);
        $run = $this->makeRun($audioBook);
        $service->validateScene($scene->fresh(), $bible, $run);

        $shot->refresh();
        $this->assertSame('invalid', $shot->validation_status);
        $issue = AudiobookContinuityIssue::where('video_shot_id', $shot->id)->first();
        $this->assertSame(AudiobookContinuityIssue::TYPE_PHASE_MISMATCH, $issue->issue_type);
        $this->assertSame(AudiobookContinuityIssue::SEVERITY_ERROR, $issue->severity);
        $this->assertSame(AudiobookContinuityIssue::ACTION_AUTO_REGENERATE, $issue->recommended_action);
    }

    public function test_flashback_scene_with_wrong_age_state_is_invalid(): void
    {
        [$audioBook, $bible, , $mainTimeline] = $this->makeFixture(withScene: false);
        $flashback = AudiobookTimeline::create([
            'story_bible_id' => $bible->id, 'canonical_key' => 'flashback-1', 'label' => 'Years before',
            'timeline_type' => 'flashback', 'chronological_order' => 0,
        ]);
        $scene = $this->makeScene($audioBook, $bible, ['timeline_id' => $flashback->id, 'status' => 'resolved'], null, 'Years before');
        $shot = $this->makeShot($scene, 1, 'Rin trẻ tuổi, chưa từng bị thương.');
        $character = $this->makeCharacter($bible, 'Rin', ['gender' => 'female'], []);
        $laterPhase = $this->makePhase($character, $mainTimeline, 'Captain after the storm', 2, ['injuries' => 'scarred hand']);
        // Bound (incorrectly) to the LATER phase even though this scene is the flashback.
        $this->bindCharacter($scene, $character, $laterPhase, 'resolved');

        Http::fake(fn(Request $r) => $this->routeFake($r, [
            $this->semanticIssue('shot', 1, AudiobookContinuityIssue::TYPE_PHASE_MISMATCH, 'confirmed', 'young, unscarred', 'scarred captain'),
        ]));

        $service = app(ContinuityValidationService::class);
        $run = $this->makeRun($audioBook);
        $service->validateScene($scene->fresh(), $bible, $run);

        $shot->refresh();
        $this->assertSame('invalid', $shot->validation_status);
    }

    public function test_location_alias_resolved_correctly_produces_no_issue(): void
    {
        [$audioBook, $bible, , , $location] = $this->makeFixtureWithLocation(['bến sông cũ']);
        $scene = $this->makeScene($audioBook, $bible, null, ['location_id' => $location->id, 'status' => 'resolved', 'unresolved_reason' => null], null);
        $this->makeShot($scene, 1, 'Nội dung an toàn.');

        Http::fake(fn(Request $r) => $this->routeFake($r, []));

        $service = app(ContinuityValidationService::class);
        $run = $this->makeRun($audioBook);
        $service->validateScene($scene->fresh(), $bible, $run);

        $this->assertCount(0, AudiobookContinuityIssue::where('video_scene_id', $scene->id)->where('issue_type', AudiobookContinuityIssue::TYPE_UNRESOLVED_BINDING)->get());
    }

    public function test_unresolved_binding_is_flagged_with_reason(): void
    {
        [$audioBook, $bible] = $this->makeFixture(withScene: false);
        $scene = $this->makeScene($audioBook, $bible, null, [
            'location_id' => null, 'status' => 'unresolved', 'unresolved_reason' => AudiobookContinuityIssue::REASON_ENTITY_MISSING, 'raw_name' => 'Nonexistent Place',
        ], null);
        $this->makeShot($scene, 1, 'Nội dung.');

        Http::fake(fn(Request $r) => $this->routeFake($r, []));

        $service = app(ContinuityValidationService::class);
        $run = $this->makeRun($audioBook);
        $service->validateScene($scene->fresh(), $bible, $run);

        $issue = AudiobookContinuityIssue::where('video_scene_id', $scene->id)->where('issue_type', AudiobookContinuityIssue::TYPE_UNRESOLVED_BINDING)->first();
        $this->assertNotNull($issue);
        $this->assertSame('location', $issue->binding_key);
        $this->assertSame(AudiobookContinuityIssue::REASON_ENTITY_MISSING, $issue->resolution_reason);
        $this->assertSame(AudiobookContinuityIssue::SEVERITY_ERROR, $issue->severity);
        $this->assertSame(AudiobookContinuityIssue::ACTION_MANUAL_REVIEW, $issue->recommended_action); // not shot-regenerable
        // No entity was invented — location_id stays null.
        $this->assertNull(data_get($scene->fresh()->location_binding, 'location_id'));
    }

    public function test_cultural_mismatch_produces_error(): void
    {
        [$audioBook, $bible, $scene] = $this->makeFixture();
        $shot = $this->makeShot($scene, 1, 'Chợ biên giới đông đúc.');

        Http::fake(fn(Request $r) => $this->routeFake($r, [
            $this->semanticIssue('shot', 1, AudiobookContinuityIssue::TYPE_CULTURAL_GROUP_MISMATCH, 'confirmed', 'local + visiting traders', 'unrelated foreign army'),
        ]));

        $service = app(ContinuityValidationService::class);
        $run = $this->makeRun($audioBook);
        $service->validateScene($scene->fresh(), $bible, $run);

        $shot->refresh();
        $issue = AudiobookContinuityIssue::where('video_shot_id', $shot->id)->first();
        $this->assertSame(AudiobookContinuityIssue::SEVERITY_ERROR, $issue->severity);
        $this->assertSame('invalid', $shot->validation_status);
    }

    public function test_anachronism_produces_error(): void
    {
        [$audioBook, $bible, $scene] = $this->makeFixture();
        $shot = $this->makeShot($scene, 1, 'Người lính cầm điện thoại di động.');

        Http::fake(fn(Request $r) => $this->routeFake($r, [
            $this->semanticIssue('shot', 1, AudiobookContinuityIssue::TYPE_ANACHRONISM, 'confirmed', 'pre-industrial setting', 'modern smartphone'),
        ]));

        $service = app(ContinuityValidationService::class);
        $run = $this->makeRun($audioBook);
        $service->validateScene($scene->fresh(), $bible, $run);

        $shot->refresh();
        $issue = AudiobookContinuityIssue::where('video_shot_id', $shot->id)->first();
        $this->assertSame(AudiobookContinuityIssue::SEVERITY_ERROR, $issue->severity);
        $this->assertSame(AudiobookContinuityIssue::ACTION_AUTO_REGENERATE, $issue->recommended_action);
    }

    public function test_valid_shot_triggers_zero_regenerate_calls(): void
    {
        [$audioBook, $bible, $scene] = $this->makeFixture();
        $shot = $this->makeShot($scene, 1, 'Nội dung bình thường.');
        $this->makePipelineWithChunk($audioBook, [$shot]);

        // A single Http::fake() registration for the whole test — Http::fake() callbacks
        // accumulate rather than replace (see Phase 1/2/3 tests), so a second registration's
        // counter would silently never run, shadowed by this one.
        $totalCalls = 0;
        Http::fake(function (Request $r) use (&$totalCalls) {
            $totalCalls++;
            return $this->routeFake($r, []);
        });

        $service = app(ContinuityValidationService::class);
        $service->validateScene($scene->fresh(), $bible, $this->makeRun($audioBook));
        $callsAfterValidate = $totalCalls;

        // No issue ids given -> nothing eligible for auto-regenerate -> zero further calls.
        $result = $service->regenerateAndRevalidate($audioBook->fresh(), []);

        $this->assertSame($callsAfterValidate, $totalCalls);
        $this->assertSame([], $result['chunk_indices']);
    }

    public function test_only_invalid_shot_chunk_gets_regenerated(): void
    {
        [$audioBook, $bible, $scene] = $this->makeFixture();
        $validShot = $this->makeShot($scene, 1, 'Nội dung ổn.');
        $invalidShot = $this->makeShot($scene, 2, 'Nội dung có vết sẹo sai lệch.');

        // Two SEPARATE chunks so we can prove only one gets a regenerate HTTP call.
        AudiobookVideoPipeline::create([
            'audio_book_id' => $audioBook->id,
            'status' => 'analyzed',
            'shot_chunks' => [
                ['index' => 0, 'scene_id' => $scene->id, 'status' => 'done', 'attempts' => 1, 'error' => null, 'shot_ids' => [$validShot->id]],
                ['index' => 1, 'scene_id' => $scene->id, 'status' => 'done', 'attempts' => 1, 'error' => null, 'shot_ids' => [$invalidShot->id]],
            ],
        ]);

        $issue = AudiobookContinuityIssue::create($this->issueRow($audioBook, $scene, $invalidShot, AudiobookContinuityIssue::TYPE_ANACHRONISM, 'error', 'auto_regenerate', 'open'));

        // Only track ENRICHMENT calls specifically (not the follow-up continuity-validate
        // call, which legitimately lists neighbor shots as context). Match by each shot's
        // distinct narration text rather than its bracketed index — a chunk's prompt uses
        // the LOCAL index within that chunk (which resets to 1 for any single-shot chunk),
        // not the shot's global shot_index, so index alone can't tell the two chunks apart.
        $enrichedTexts = [];
        Http::fake(function (Request $r) use (&$enrichedTexts) {
            $prompt = (string) data_get($r->data(), 'messages.0.content', '');
            if (!str_contains($prompt, 'kiểm tra continuity')) {
                $enrichedTexts[] = $prompt;
            }
            return $this->routeFake($r, []);
        });

        app(ContinuityValidationService::class)->regenerateAndRevalidate($audioBook->fresh(), [$issue->id]);

        $this->assertTrue(collect($enrichedTexts)->contains(fn($p) => str_contains($p, 'Nội dung có vết sẹo sai lệch'))); // invalid shot's chunk WAS regenerated
        $this->assertFalse(collect($enrichedTexts)->contains(fn($p) => str_contains($p, 'Nội dung ổn'))); // valid shot's chunk was NOT re-enriched
    }

    public function test_regenerate_and_revalidate_confirms_invalid_becomes_valid(): void
    {
        [$audioBook, $bible, $scene] = $this->makeFixture();
        $shot = $this->makeShot($scene, 1, 'Nội dung cần sửa.', 'invalid');
        $this->makePipelineWithChunk($audioBook, [$shot]);
        $issue = AudiobookContinuityIssue::create($this->issueRow($audioBook, $scene, $shot, AudiobookContinuityIssue::TYPE_ANACHRONISM, 'error', 'auto_regenerate', 'open'));

        Http::fake(fn(Request $r) => $this->routeFake($r, [])); // enrich succeeds, revalidate finds no issues

        app(ContinuityValidationService::class)->regenerateAndRevalidate($audioBook->fresh(), [$issue->id]);

        $shot->refresh();
        $issue->refresh();
        $this->assertSame('enriched', $shot->enrichment_status);
        $this->assertSame('valid', $shot->validation_status);
        $this->assertSame(AudiobookContinuityIssue::STATUS_RESOLVED, $issue->status);
        $this->assertNotNull($issue->resolved_at);
    }

    public function test_failed_revalidation_does_not_falsely_resolve_issue(): void
    {
        [$audioBook, $bible, $scene] = $this->makeFixture();
        $shot = $this->makeShot($scene, 1, 'Nội dung cần sửa.', 'invalid');
        $this->makePipelineWithChunk($audioBook, [$shot]);
        $issue = AudiobookContinuityIssue::create($this->issueRow($audioBook, $scene, $shot, AudiobookContinuityIssue::TYPE_ANACHRONISM, 'error', 'auto_regenerate', 'open'));

        Http::fake(function (Request $r) {
            $prompt = (string) data_get($r->data(), 'messages.0.content', '');
            if (str_contains($prompt, 'kiểm tra continuity')) {
                throw new \RuntimeException('Simulated revalidation failure');
            }
            return $this->fakeShotEnrichResponse($r);
        });

        app(ContinuityValidationService::class)->regenerateAndRevalidate($audioBook->fresh(), [$issue->id]);

        $shot->refresh();
        $issue->refresh();
        $this->assertSame('enriched', $shot->enrichment_status); // regeneration itself succeeded
        $this->assertSame('failed', $shot->validation_status); // but we COULDN'T confirm the fix
        $this->assertSame(AudiobookContinuityIssue::STATUS_REGENERATING, $issue->status); // must NOT be resolved
        $this->assertNull($issue->resolved_at);
    }

    public function test_revalidating_does_not_create_duplicate_issues(): void
    {
        [$audioBook, $bible, $scene] = $this->makeFixture();
        $shot = $this->makeShot($scene, 1, 'Nội dung có mâu thuẫn.');

        Http::fake(fn(Request $r) => $this->routeFake($r, [
            $this->semanticIssue('shot', 1, AudiobookContinuityIssue::TYPE_ANACHRONISM, 'confirmed', 'a', 'b'),
        ]));

        $service = app(ContinuityValidationService::class);
        $service->validateScene($scene->fresh(), $bible, $this->makeRun($audioBook));
        $service->validateScene($scene->fresh(), $bible, $this->makeRun($audioBook));

        $this->assertCount(1, AudiobookContinuityIssue::where('video_shot_id', $shot->id)->get());
    }

    public function test_only_shot_ids_persists_issues_for_target_shots_only(): void
    {
        [$audioBook, $bible, $scene] = $this->makeFixture();
        $shot1 = $this->makeShot($scene, 1, 'Shot 1.');
        $shot2 = $this->makeShot($scene, 2, 'Shot 2.');

        Http::fake(fn(Request $r) => $this->routeFake($r, [
            $this->semanticIssue('shot', 1, AudiobookContinuityIssue::TYPE_ANACHRONISM, 'confirmed', 'a', 'b'),
            $this->semanticIssue('shot', 2, AudiobookContinuityIssue::TYPE_ANACHRONISM, 'confirmed', 'c', 'd'),
        ]));

        $service = app(ContinuityValidationService::class);
        $service->validateScene($scene->fresh(), $bible, $this->makeRun($audioBook), onlyShotIndices: [1]);

        $shot1->refresh();
        $shot2->refresh();
        $this->assertSame('invalid', $shot1->validation_status);
        $this->assertCount(1, AudiobookContinuityIssue::where('video_shot_id', $shot1->id)->get());

        // Shot 2 must be completely untouched even though the AI mentioned it.
        $this->assertSame('unvalidated', $shot2->validation_status);
        $this->assertCount(0, AudiobookContinuityIssue::where('video_shot_id', $shot2->id)->get());
    }

    // ------------------------------------------------------------------
    // Fixtures / fakes
    // ------------------------------------------------------------------

    /**
     * @return array{0:AudioBook,1:AudiobookStoryBible,2:?AudiobookVideoScene,3:?AudiobookTimeline}
     */
    private function makeFixture(bool $withScene = true): array
    {
        $channel = YoutubeChannel::create(['channel_id' => 'UC_cv_' . uniqid(), 'title' => 'Test Channel']);
        $audioBook = AudioBook::create(['youtube_channel_id' => $channel->id, 'title' => 'Continuity Test Fixture']);
        $bible = AudiobookStoryBible::create([
            'audio_book_id' => $audioBook->id, 'bible_version' => 1, 'schema_version' => 'story_bible_v1',
            'status' => 'active', 'is_active' => true,
        ]);
        $timeline = AudiobookTimeline::create([
            'story_bible_id' => $bible->id, 'canonical_key' => 'main', 'label' => 'Main Voyage',
            'timeline_type' => 'main', 'chronological_order' => 1,
        ]);

        $scene = null;
        if ($withScene) {
            $scene = $this->makeScene($audioBook, $bible, ['timeline_id' => $timeline->id, 'status' => 'resolved'], null, 'Main');
        }

        return [$audioBook, $bible, $scene, $timeline];
    }

    private function makeFixtureWithLocation(array $aliases): array
    {
        [$audioBook, $bible] = $this->makeFixture(withScene: false);
        $location = AudiobookLocation::create([
            'story_bible_id' => $bible->id, 'canonical_name' => 'Rivergate', 'aliases' => $aliases,
            'cultural_context' => [], 'visual_notes' => null,
        ]);
        return [$audioBook, $bible, null, null, $location];
    }

    private function makeScene(AudioBook $audioBook, AudiobookStoryBible $bible, ?array $timelineBinding, ?array $locationBinding, ?string $storyPhase, int $sceneIndex = 1): AudiobookVideoScene
    {
        return AudiobookVideoScene::create([
            'audio_book_id' => $audioBook->id,
            'scene_index' => $sceneIndex,
            'title' => 'Scene ' . $sceneIndex,
            'script_text' => 'Nội dung cảnh.',
            'scene_type' => 'character',
            'story_bible_id' => $bible->id,
            'story_bible_version_used' => $bible->bible_version,
            'scene_direction_version' => \App\Services\VideoSceneAnalysisService::SCENE_DIRECTION_VERSION,
            'timeline_binding' => $timelineBinding,
            'location_binding' => $locationBinding,
            'story_phase' => $storyPhase,
        ]);
    }

    private function makeShot(AudiobookVideoScene $scene, int $index, string $text, string $initialStatus = 'unvalidated'): AudiobookVideoShot
    {
        return AudiobookVideoShot::create([
            'video_scene_id' => $scene->id,
            'shot_index' => $index,
            'sentence_text' => $text,
            'keywords' => ['test'],
            'image_request' => 'a test image',
            'enrichment_status' => 'enriched',
            'prompt_version' => \App\Services\VideoSceneAnalysisService::PROMPT_VERSION,
            'validation_status' => $initialStatus,
        ]);
    }

    private function makeCharacter(AudiobookStoryBible $bible, string $name, array $identityAnchor, array $baselineTraits): AudiobookCharacter
    {
        return AudiobookCharacter::create([
            'story_bible_id' => $bible->id,
            'canonical_name' => $name,
            'aliases' => [],
            'identity_anchor' => ['value' => $identityAnchor, 'confidence' => 'confirmed', 'source_type' => 'explicit_text', 'evidence' => [['quote' => 'x']], 'rationale' => null],
            'baseline_traits' => ['value' => $baselineTraits, 'confidence' => 'confirmed', 'source_type' => 'explicit_text', 'evidence' => [['quote' => 'x']], 'rationale' => null],
        ]);
    }

    private function makePhase(AudiobookCharacter $character, AudiobookTimeline $timeline, string $label, int $order, array $mutableTraits): AudiobookCharacterPhase
    {
        return AudiobookCharacterPhase::create([
            'character_id' => $character->id,
            'timeline_id' => $timeline->id,
            'label' => $label,
            'chronological_order' => $order,
            'mutable_traits' => ['value' => $mutableTraits, 'confidence' => 'confirmed', 'source_type' => 'explicit_text', 'evidence' => [['quote' => 'x']], 'rationale' => null],
        ]);
    }

    private function bindCharacter(AudiobookVideoScene $scene, AudiobookCharacter $character, ?AudiobookCharacterPhase $phase, string $resolutionStatus): AudiobookVideoSceneCharacter
    {
        return AudiobookVideoSceneCharacter::create([
            'video_scene_id' => $scene->id,
            'character_id' => $character->id,
            'character_phase_id' => $phase?->id,
            'confidence' => 'confirmed',
            'source_type' => 'explicit_text',
            'evidence' => [],
            'resolution_status' => $resolutionStatus,
        ]);
    }

    private function makePipelineWithChunk(AudioBook $audioBook, array $shots): AudiobookVideoPipeline
    {
        return AudiobookVideoPipeline::create([
            'audio_book_id' => $audioBook->id,
            'status' => 'analyzed',
            'shot_chunks' => [
                ['index' => 0, 'scene_id' => $shots[0]->video_scene_id, 'status' => 'done', 'attempts' => 1, 'error' => null, 'shot_ids' => collect($shots)->pluck('id')->all()],
            ],
        ]);
    }

    private function makeRun(AudioBook $audioBook): \App\Models\AudiobookContinuityValidationRun
    {
        return \App\Models\AudiobookContinuityValidationRun::create([
            'audio_book_id' => $audioBook->id,
            'status' => 'running',
            'scope' => 'full',
            'continuity_validator_version' => ContinuityValidationService::VALIDATOR_VERSION,
            'started_at' => now(),
        ]);
    }

    private function issueRow(AudioBook $audioBook, AudiobookVideoScene $scene, AudiobookVideoShot $shot, string $type, string $severity, string $action, string $status): array
    {
        return [
            'audio_book_id' => $audioBook->id,
            'video_scene_id' => $scene->id,
            'video_shot_id' => $shot->id,
            'issue_type' => $type,
            'severity' => $severity,
            'message' => 'test issue',
            'confidence' => 'confirmed',
            'source_type' => 'explicit_text',
            'evidence' => [['quote' => 'x']],
            'recommended_action' => $action,
            'status' => $status,
            'issue_fingerprint' => app(ContinuityValidationService::class)->fingerprint($scene->id, $shot->id, $type),
            'continuity_validator_version' => ContinuityValidationService::VALIDATOR_VERSION,
        ];
    }

    private function semanticIssue(string $scope, ?int $shotIndex, string $type, string $confidence, $expected, $actual): array
    {
        return [
            'scope' => $scope,
            'shot_index' => $shotIndex,
            'issue_type' => $type,
            'message' => "Mismatch: expected {$expected}, got {$actual}",
            'expected_value' => $expected,
            'actual_value' => $actual,
            'confidence' => $confidence,
            'source_type' => 'explicit_text',
            'evidence' => [['quote' => 'narration snippet']],
            'rationale' => null,
        ];
    }

    /**
     * Routes an OpenAI call to either the continuity-validate semantic response (given
     * issues) or a plain shot-enrich success response, based on prompt content.
     */
    private function routeFake(Request $request, array $issues)
    {
        $prompt = (string) data_get($request->data(), 'messages.0.content', '');
        if (str_contains($prompt, 'kiểm tra continuity')) {
            return Http::response([
                'choices' => [['message' => ['content' => json_encode(['issues' => $issues])], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 400, 'completion_tokens' => 200],
            ], 200, ['x-request-id' => 'req-' . uniqid()]);
        }

        return $this->fakeShotEnrichResponse($request);
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
