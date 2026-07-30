<?php

namespace Tests\Feature;

use App\Jobs\EnrichVideoShotsJob;
use App\Models\AudioBook;
use App\Models\AudiobookCharacter;
use App\Models\AudiobookContinuityIssue;
use App\Models\AudiobookLocation;
use App\Models\AudiobookStoryBible;
use App\Models\AudiobookTimeline;
use App\Models\AudiobookVideoPipeline;
use App\Models\AudiobookVideoScene;
use App\Models\AudiobookVideoShot;
use App\Models\AudiobookVideoShotCharacter;
use App\Models\YoutubeChannel;
use App\Services\ContinuityValidationService;
use App\Services\VideoSceneAnalysisService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regression coverage for a real continuity bug found reviewing AudioBook #21 (real content,
 * not synthetic): a chunk boundary split ONE continuous event (a guide draws a dagger at
 * night in the desert -> Huyền Trang calmly chants, guide flees) across two independent
 * enrichment API calls with zero shared context. The second call had no visibility into what
 * the first call had already established, so it invented a completely different setting
 * ("frontier tower chamber") and a different role for the unnamed guide ("a soldier").
 *
 * Fixed via: (1) per-shot (chunk-scoped) location/timeline/character binding instead of one
 * binding per whole scene, (2) a carry-over summary from the previous chunk's last shot
 * threaded into the next chunk's prompt, (3) an explicit "do not invent a role/costume for an
 * unresolved character" instruction, (4) new continuity-validator issue types that compare a
 * shot against its ADJACENT sibling, not just against the scene-wide binding.
 */
class ChunkContinuityInheritanceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.openai.api_key', 'test-key');
    }

    public function test_continuous_event_split_across_chunk_boundary_does_not_silently_switch_location(): void
    {
        [$audioBook, , $scene, $desert, $guide] = $this->makeDesertAmbushFixture();

        // Shot #15 = last shot of chunk 0. Shot #16 = first shot of chunk 1 — a SEPARATE
        // enrichment API call with no text overlap with chunk 0. Neither shot's own sentence
        // names the guide or restates "desert" — exactly the real-world case.
        $shot15 = AudiobookVideoShot::create([
            'video_scene_id' => $scene->id, 'shot_index' => 15,
            'sentence_text' => 'Nào ngờ một đêm giữa hoang mạc, gã này lén rút dao định giết ngài.',
            'enrichment_status' => 'pending',
        ]);
        $shot16 = AudiobookVideoShot::create([
            'video_scene_id' => $scene->id, 'shot_index' => 16,
            'sentence_text' => 'Huyền Trang chỉ bình thản ngồi dậy niệm chú, khiến kẻ phản bội chùn tay rồi bỏ đi.',
            'enrichment_status' => 'pending',
        ]);

        AudiobookVideoPipeline::create([
            'audio_book_id' => $audioBook->id,
            'status' => 'analyzed',
            'shot_chunks' => [
                ['index' => 0, 'scene_id' => $scene->id, 'status' => 'pending', 'attempts' => 0, 'error' => null, 'shot_ids' => [$shot15->id]],
                ['index' => 1, 'scene_id' => $scene->id, 'status' => 'pending', 'attempts' => 0, 'error' => null, 'shot_ids' => [$shot16->id]],
            ],
        ]);

        $callCount = 0;
        $capturedChunk1Prompt = null;
        Http::fake(function (Request $request) use (&$callCount, &$capturedChunk1Prompt, $desert, $guide) {
            $callCount++;
            $prompt = (string) data_get($request->data(), 'messages.0.content', '');

            if ($callCount === 1) {
                // Chunk 0 (shot #15): resolves cleanly — guide named directly in the sentence.
                return $this->fakeChunkEnrichResponse([
                    'continues_previous' => false,
                    'timeline' => ['name' => 'Main pilgrimage timeline', 'confidence' => 'confirmed', 'source_type' => 'explicit_text', 'evidence' => [['quote' => 'x']]],
                    'location' => ['name' => $desert->canonical_name, 'confidence' => 'confirmed', 'source_type' => 'explicit_text', 'evidence' => [['quote' => 'x']], 'relevant_cultural_groups' => []],
                    'story_phase' => 'desert_ambush',
                    'characters_present' => [
                        ['name' => $guide->canonical_name, 'phase_label' => null, 'pronoun_only' => false, 'confidence' => 'confirmed', 'source_type' => 'explicit_text', 'evidence' => [['quote' => 'x']]],
                    ],
                ]);
            }

            // Chunk 1 (shot #16) — the model here only sees THIS shot's own sentence, plus
            // whatever carry-over context our code supplies. It correctly continues the
            // desert setting (simulating a model that obeys the carry-over instruction).
            $capturedChunk1Prompt = $prompt;
            return $this->fakeChunkEnrichResponse([
                'continues_previous' => true,
                'timeline' => ['name' => 'Main pilgrimage timeline', 'confidence' => 'confirmed', 'source_type' => 'explicit_text', 'evidence' => [['quote' => 'x']]],
                'location' => ['name' => $desert->canonical_name, 'confidence' => 'confirmed', 'source_type' => 'explicit_text', 'evidence' => [['quote' => 'x']], 'relevant_cultural_groups' => []],
                'story_phase' => 'desert_ambush',
                'characters_present' => [
                    ['name' => $guide->canonical_name, 'phase_label' => null, 'pronoun_only' => true, 'confidence' => 'inferred', 'source_type' => 'inferred_from_text', 'evidence' => [['quote' => 'kẻ phản bội']]],
                ],
            ]);
        });

        (new EnrichVideoShotsJob($audioBook->id, [0, 1]))->handle(app(VideoSceneAnalysisService::class));

        // The mechanism must actually OFFER the previous chunk's resolved context to the next
        // chunk's prompt — this is what's deterministically checkable regardless of what a
        // real model would decide to do with it (that judgment call is the live smoke test's
        // job, already verified separately against the real book).
        $this->assertNotNull($capturedChunk1Prompt);
        $this->assertStringContainsString('BỐI CẢNH NGAY TRƯỚC', $capturedChunk1Prompt);
        $this->assertStringContainsString($desert->canonical_name, $capturedChunk1Prompt);

        $shot15->refresh();
        $shot16->refresh();

        $this->assertNotNull($shot15->resolvedLocation());
        $this->assertSame($shot15->resolvedLocation()->id, $shot16->resolvedLocation()->id);

        // The guide is bound to BOTH shots — named directly in shot 15, pronoun-resolved in
        // shot 16 — never left as an unidentified figure for enrichment to invent a role for.
        $shot15Character = AudiobookVideoShotCharacter::where('video_shot_id', $shot15->id)->first();
        $shot16Character = AudiobookVideoShotCharacter::where('video_shot_id', $shot16->id)->first();
        $this->assertNotNull($shot15Character);
        $this->assertNotNull($shot16Character);
        $this->assertSame($guide->id, $shot15Character->character_id);
        $this->assertSame($guide->id, $shot16Character->character_id);
        $this->assertSame('pronoun_inferred', $shot16Character->resolution_status);
    }

    public function test_secondary_character_without_wardrobe_evidence_is_not_assigned_a_costume(): void
    {
        [$audioBook, , $scene, , $guide] = $this->makeDesertAmbushFixture();

        $shot = AudiobookVideoShot::create([
            'video_scene_id' => $scene->id, 'shot_index' => 1,
            'sentence_text' => 'Kẻ phản bội đứng lặng lẽ trong bóng tối.',
            'enrichment_status' => 'pending',
        ]);

        AudiobookVideoPipeline::create([
            'audio_book_id' => $audioBook->id,
            'status' => 'analyzed',
            'shot_chunks' => [
                ['index' => 0, 'scene_id' => $scene->id, 'status' => 'pending', 'attempts' => 0, 'error' => null, 'shot_ids' => [$shot->id]],
            ],
        ]);

        $capturedPrompt = null;
        Http::fake(function (Request $request) use (&$capturedPrompt) {
            $capturedPrompt = (string) data_get($request->data(), 'messages.0.content', '');
            // The character can't be resolved from this shot's own text alone (no name, no
            // carry-over available — this is the FIRST chunk) — the model correctly leaves
            // characters_present empty rather than guessing.
            return $this->fakeChunkEnrichResponse([
                'continues_previous' => false,
                'timeline' => ['name' => null, 'confidence' => 'unknown', 'source_type' => 'unknown', 'evidence' => []],
                'location' => ['name' => null, 'confidence' => 'unknown', 'source_type' => 'unknown', 'evidence' => [], 'relevant_cultural_groups' => []],
                'story_phase' => null,
                'characters_present' => [],
            ]);
        });

        (new EnrichVideoShotsJob($audioBook->id, [0]))->handle(app(VideoSceneAnalysisService::class));

        // The prompt must explicitly discourage inventing a specific role/costume for a
        // person who can't be resolved to a bible character — this is the instruction that
        // stops "kẻ phản bội" from being enriched into "a soldier" out of thin air.
        $this->assertNotNull($capturedPrompt);
        $this->assertStringContainsString('KHÔNG tự bịa vai trò/trang phục cụ thể', $capturedPrompt);

        // Nothing gets invented/bound for the unresolved guide — the character has zero
        // wardrobe evidence in the Story Bible, and this shot's own text names no one.
        $this->assertSame(0, AudiobookVideoShotCharacter::where('video_shot_id', $shot->id)->count());
        $this->assertNull(data_get($guide->fresh()->baseline_traits, 'value.wardrobe'));
    }

    public function test_validator_flags_unexpected_location_change_instead_of_marking_both_shots_valid(): void
    {
        [$audioBook, $bible, $scene, $desert, $guide] = $this->makeDesertAmbushFixture();
        $interior = AudiobookLocation::create([
            'story_bible_id' => $bible->id, 'canonical_name' => 'Tháp canh biên giới', 'aliases' => [],
            'cultural_context' => [], 'visual_notes' => null,
        ]);

        // Same event, same scene — shot 1 correctly bound to the desert; shot 2 (no
        // transition wording in its narration) incorrectly bound to an unrelated interior.
        // This mirrors persisted state AFTER a real (pre-fix) enrichment run — the point of
        // this test is the VALIDATOR, not the enrichment step.
        $shot1 = AudiobookVideoShot::create([
            'video_scene_id' => $scene->id, 'shot_index' => 1,
            'sentence_text' => 'Nào ngờ một đêm giữa hoang mạc, gã này lén rút dao định giết ngài.',
            'keywords' => ['desert night attack'], 'image_request' => 'A moonlit desert camp with a guide drawing a dagger.',
            'enrichment_status' => 'enriched', 'prompt_version' => VideoSceneAnalysisService::PROMPT_VERSION,
            'validation_status' => 'unvalidated', 'narrative_mode' => 'story',
            'location_binding' => ['location_id' => $desert->id, 'confidence' => 'confirmed', 'source_type' => 'explicit_text', 'evidence' => [], 'status' => 'resolved'],
        ]);
        $shot2 = AudiobookVideoShot::create([
            'video_scene_id' => $scene->id, 'shot_index' => 2,
            'sentence_text' => 'Huyền Trang chỉ bình thản ngồi dậy niệm chú, khiến kẻ phản bội chùn tay rồi bỏ đi.',
            'keywords' => ['border watchtower night'], 'image_request' => 'A monk seated inside a dim frontier tower chamber as a soldier retreats.',
            'enrichment_status' => 'enriched', 'prompt_version' => VideoSceneAnalysisService::PROMPT_VERSION,
            'validation_status' => 'unvalidated', 'narrative_mode' => 'story',
            'location_binding' => ['location_id' => $interior->id, 'confidence' => 'confirmed', 'source_type' => 'explicit_text', 'evidence' => [], 'status' => 'resolved'],
        ]);

        Http::fake(fn (Request $r) => $this->routeContinuityFake($r, [
            [
                'scope' => 'shot', 'shot_index' => 2,
                'issue_type' => AudiobookContinuityIssue::TYPE_UNEXPECTED_LOCATION_CHANGE,
                'message' => 'Location jumps from desert to an interior tower chamber with no transition in the narration.',
                'expected_value' => $desert->canonical_name, 'actual_value' => $interior->canonical_name,
                'confidence' => 'confirmed', 'source_type' => 'explicit_text',
                'evidence' => [['quote' => 'frontier tower chamber']], 'rationale' => null,
            ],
        ]));

        $service = app(ContinuityValidationService::class);
        $run = \App\Models\AudiobookContinuityValidationRun::create([
            'audio_book_id' => $audioBook->id, 'status' => 'running', 'scope' => 'full',
            'continuity_validator_version' => ContinuityValidationService::VALIDATOR_VERSION, 'started_at' => now(),
        ]);
        $service->validateScene($scene->fresh(), $bible, $run);

        $shot1->refresh();
        $shot2->refresh();

        // The validator must NOT silently pass both shots as valid — the whole point of this
        // regression is that a naive whole-scene-only comparison had nothing to catch this.
        $this->assertSame('valid', $shot1->validation_status);
        $this->assertSame('invalid', $shot2->validation_status);

        $issue = AudiobookContinuityIssue::where('video_shot_id', $shot2->id)->first();
        $this->assertNotNull($issue);
        $this->assertSame(AudiobookContinuityIssue::TYPE_UNEXPECTED_LOCATION_CHANGE, $issue->issue_type);
        $this->assertSame(AudiobookContinuityIssue::SEVERITY_ERROR, $issue->severity);
        $this->assertSame(AudiobookContinuityIssue::ACTION_AUTO_REGENERATE, $issue->recommended_action);
    }

    /**
     * @return array{0:AudioBook,1:AudiobookStoryBible,2:AudiobookVideoScene,3:AudiobookLocation,4:AudiobookCharacter}
     */
    private function makeDesertAmbushFixture(): array
    {
        $channel = YoutubeChannel::create(['channel_id' => 'UC_cci_' . uniqid(), 'title' => 'Test Channel']);
        $audioBook = AudioBook::create(['youtube_channel_id' => $channel->id, 'title' => 'Chunk Continuity Test Fixture']);

        $bible = AudiobookStoryBible::create([
            'audio_book_id' => $audioBook->id, 'bible_version' => 1, 'schema_version' => 'story_bible_v1',
            'status' => 'active', 'is_active' => true,
        ]);
        AudiobookTimeline::create([
            'story_bible_id' => $bible->id, 'canonical_key' => 'main', 'label' => 'Main pilgrimage timeline',
            'timeline_type' => 'main', 'chronological_order' => 1,
        ]);
        $desert = AudiobookLocation::create([
            'story_bible_id' => $bible->id, 'canonical_name' => 'Sa mạc biên giới', 'aliases' => [],
            'cultural_context' => [], 'visual_notes' => null,
        ]);
        // No wardrobe/baseline_traits claim on purpose — the real gap this bug exposed.
        $guide = AudiobookCharacter::create([
            'story_bible_id' => $bible->id, 'canonical_name' => 'Thạch Bàn Đà', 'aliases' => [],
            'role' => ['value' => 'người dẫn đường được thuê', 'confidence' => 'confirmed', 'source_type' => 'explicit_text', 'evidence' => [['quote' => 'x']], 'rationale' => null],
        ]);

        $scene = AudiobookVideoScene::create([
            'audio_book_id' => $audioBook->id,
            'scene_index' => 1,
            'title' => 'Hành trình',
            'script_text' => 'Nào ngờ một đêm giữa hoang mạc, gã này lén rút dao định giết ngài. '
                . 'Huyền Trang chỉ bình thản ngồi dậy niệm chú, khiến kẻ phản bội chùn tay rồi bỏ đi.',
            'scene_type' => 'character',
            'story_bible_id' => $bible->id,
            'story_bible_version_used' => 1,
            'scene_direction_version' => VideoSceneAnalysisService::SCENE_DIRECTION_VERSION,
            // Deliberately no scene-wide binding — mirrors the real bug (a scene-wide binding
            // resolved to a DIFFERENT part of a long journey) and proves the fix relies on
            // shot-level bindings, not a coincidentally-correct scene-level fallback.
            'timeline_binding' => null,
            'location_binding' => null,
            'story_phase' => null,
        ]);

        return [$audioBook, $bible, $scene, $desert, $guide];
    }

    private function fakeChunkEnrichResponse(array $chunkContext)
    {
        // Every chunk in these tests contains exactly ONE shot, so its local (1-based, reset
        // per chunk) index is always 1 — see EnrichVideoShotsJob::reconstructChunkPlan().
        return Http::response([
            'choices' => [['message' => ['content' => json_encode([
                'shots' => [['index' => 1, 'is_real_world' => true, 'keywords' => ['test'], 'image_request' => 'A test image request.', 'is_host_narration' => false]],
                'chunk_context' => $chunkContext,
            ])], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 300, 'completion_tokens' => 150],
        ], 200, ['x-request-id' => 'req-' . uniqid()]);
    }

    private function routeContinuityFake(Request $request, array $issues)
    {
        $prompt = (string) data_get($request->data(), 'messages.0.content', '');
        if (str_contains($prompt, 'kiểm tra continuity')) {
            return Http::response([
                'choices' => [['message' => ['content' => json_encode(['issues' => $issues])], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 400, 'completion_tokens' => 200],
            ], 200, ['x-request-id' => 'req-' . uniqid()]);
        }

        return Http::response([
            'choices' => [['message' => ['content' => json_encode(['shots' => []])], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
        ], 200, ['x-request-id' => 'req-' . uniqid()]);
    }
}
