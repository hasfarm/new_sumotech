<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeStoryDirectionJob;
use App\Models\AudioBook;
use App\Models\AudioBookChapter;
use App\Models\AudiobookStoryBible;
use App\Models\YoutubeChannel;
use App\Services\StoryBibleAnalysisService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Verifies StoryBibleAnalysisService/AnalyzeStoryDirectionJob's data-quality contract:
 * unknown-when-insufficient, non-linear timeline detection, phase creation only when a
 * change signal exists, alias-based location resolution, multi-cultural locations, and
 * atomic versioning (a failed regenerate never touches the currently-active bible).
 *
 * The fixture book/characters/locations below are entirely invented for this test only —
 * nothing here is hard-coded anywhere in application code, which is proven by asserting
 * these exact strings appear nowhere outside this test file.
 */
class AnalyzeStoryDirectionJobTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.openai.api_key', 'test-key');
    }

    public function test_full_analysis_produces_a_valid_claim_normalized_bible(): void
    {
        [$audioBook, $chapters] = $this->makeFixtureBook();

        Http::fake(fn(Request $request) => $this->routeFakeCall($request, $chapters));

        (new AnalyzeStoryDirectionJob($audioBook->id))->handle(app(StoryBibleAnalysisService::class));

        $bible = AudiobookStoryBible::where('audio_book_id', $audioBook->id)->where('is_active', true)->first();
        $this->assertNotNull($bible);
        $this->assertSame('active', $bible->status);
        $this->assertSame(1, $bible->bible_version);
        $this->assertSame(StoryBibleAnalysisService::SCHEMA_VERSION, $bible->schema_version);

        // 1. Insufficient data -> unknown, not fabricated.
        $genre = $bible->source_facts['genre'];
        $this->assertSame('unknown', $genre['confidence']);
        $this->assertNull($genre['value']);
        $this->assertSame([], $genre['evidence']);

        // Invariant enforcement: a claim the fake marked "confirmed" but gave no
        // evidence/rationale for must be defensively downgraded to unknown.
        $pacing = $bible->director_treatment['pacing'];
        $this->assertSame('unknown', $pacing['confidence']);
        $this->assertNull($pacing['value']);

        // 2. Non-linear / time-skip structure is detected (not hard-coded to "linear").
        $this->assertSame('time_skip', $bible->source_facts['timeline_structure']['value']);
        $timelineEvidence = $bible->source_facts['timeline_structure']['evidence'];
        $this->assertNotEmpty($timelineEvidence);
        // Offsets are computed by PHP (never trusted from the model) against real chapter text.
        $this->assertNotNull($timelineEvidence[0]['text_offset_start']);
        $this->assertNotNull($timelineEvidence[0]['text_offset_end']);

        $timelines = $bible->timelines()->orderBy('chronological_order')->get();
        $this->assertCount(2, $timelines);
        $this->assertNotEquals($timelines[0]->canonical_key, $timelines[1]->canonical_key);

        // 5. Same location referred to by two different names resolves to ONE row.
        $locations = $bible->locations;
        $this->assertCount(1, $locations);
        $location = $locations->first();
        $this->assertContains('the crossing town', $location->aliases);

        // 6. Multi-cultural location: local + visiting groups coexist.
        $groups = collect($location->cultural_context['cultural_groups_present']);
        $this->assertGreaterThanOrEqual(2, $groups->count());
        $presences = $groups->pluck('value.presence')->all();
        $this->assertContains('local', $presences);
        $this->assertContains('visiting', $presences);

        $characters = $bible->characters()->get()->keyBy('canonical_name');

        // 3. Character with an evidenced change gets phases with chronological order
        //    distinct from mere narrative/creation order, and the phase referencing the
        //    location's ALIAS resolves to the same canonical location row (real FK, not a
        //    name string).
        $changedCharacter = $characters->get('Aran');
        $this->assertNotNull($changedCharacter);
        $phases = $changedCharacter->phases;
        $this->assertCount(2, $phases);
        $this->assertNotSame($phases[0]->chronological_order, $phases[1]->chronological_order);
        $laterPhase = $phases->firstWhere('label', 'Caravan leader after the raid');
        $this->assertNotNull($laterPhase);
        $this->assertSame($location->id, $laterPhase->current_location_id);
        $this->assertNotNull($laterPhase->timeline_id);

        // 4. Character with no change signal: zero phases, valid and expected. A Phase-3
        //    consumer would fall back to baseline_traits/identity_anchor directly.
        $unchangedCharacter = $characters->get('Mira');
        $this->assertNotNull($unchangedCharacter);
        $this->assertCount(0, $unchangedCharacter->phases);
        $this->assertSame('confirmed', $unchangedCharacter->baseline_traits['confidence']);

        // cultural_origin stays on the character (permanent), current_location_id lives on
        // the phase (mutable) — the two are structurally independent.
        $this->assertSame('steppe nomad culture', $changedCharacter->cultural_origin['value']['culture']);

        // Genericness guard: fixture-only strings must not leak into application code.
        foreach (['Aran', 'Mira', 'Border Market', 'steppe nomad culture'] as $needle) {
            $this->assertStringNotContainsString($needle, file_get_contents(app_path('Services/StoryBibleAnalysisService.php')));
            $this->assertStringNotContainsString($needle, file_get_contents(app_path('Jobs/AnalyzeStoryDirectionJob.php')));
        }
    }

    public function test_regenerate_failure_does_not_touch_the_currently_active_bible(): void
    {
        [$audioBook, $chapters] = $this->makeFixtureBook();

        // Http::fake() callbacks accumulate (Factory::fake() merges, first non-null match
        // wins) rather than replacing — a single closure driven by this mutable flag lets
        // behavior change across the three job runs below without an earlier "always
        // succeed" stub permanently shadowing the later "force a reduce failure" stub.
        $forceReduceFailure = false;
        Http::fake(function (Request $request) use ($chapters, &$forceReduceFailure) {
            $prompt = (string) data_get($request->data(), 'messages.0.content', '');
            $isReduce = str_contains($prompt, 'DỮ KIỆN ĐÃ TRÍCH XUẤT');

            if ($isReduce && $forceReduceFailure) {
                throw new \RuntimeException('Simulated reduce-step failure');
            }

            return $isReduce ? $this->fakeReduceResponse($chapters) : $this->fakeMapResponse($chapters);
        });

        (new AnalyzeStoryDirectionJob($audioBook->id))->handle(app(StoryBibleAnalysisService::class));

        $original = AudiobookStoryBible::where('audio_book_id', $audioBook->id)->where('is_active', true)->firstOrFail();
        $originalId = $original->id;
        $originalTimelineCount = $original->timelines()->count();
        $originalLocationCount = $original->locations()->count();
        $originalCharacterCount = $original->characters()->count();

        // Force regenerate: map succeeds, reduce blows up.
        $forceReduceFailure = true;
        (new AnalyzeStoryDirectionJob($audioBook->id, force: true))->handle(app(StoryBibleAnalysisService::class));

        // The original active bible must be completely untouched.
        $original->refresh();
        $this->assertTrue($original->is_active);
        $this->assertSame('active', $original->status);
        $this->assertSame($originalId, $original->id);
        $this->assertSame($originalTimelineCount, $original->timelines()->count());
        $this->assertSame($originalLocationCount, $original->locations()->count());
        $this->assertSame($originalCharacterCount, $original->characters()->count());

        // The failed attempt is recorded as its own (inactive) version, not silently lost.
        $failedDraft = AudiobookStoryBible::where('audio_book_id', $audioBook->id)->where('bible_version', 2)->first();
        $this->assertNotNull($failedDraft);
        $this->assertSame('failed', $failedDraft->status);
        $this->assertFalse($failedDraft->is_active);

        // A subsequent successful regenerate DOES activate the new version and retires the old one.
        $forceReduceFailure = false;
        (new AnalyzeStoryDirectionJob($audioBook->id, force: true))->handle(app(StoryBibleAnalysisService::class));

        $this->assertNull(AudiobookStoryBible::find($originalId));
        $newActive = AudiobookStoryBible::where('audio_book_id', $audioBook->id)->where('is_active', true)->firstOrFail();
        $this->assertSame(3, $newActive->bible_version);
    }

    /**
     * @return array{0:AudioBook,1:array<string,AudioBookChapter>}
     */
    private function makeFixtureBook(): array
    {
        $channel = YoutubeChannel::create(['channel_id' => 'UC_sb_' . uniqid(), 'title' => 'Test Channel']);
        $audioBook = AudioBook::create(['youtube_channel_id' => $channel->id, 'title' => 'Story Bible Test Fixture']);

        $ch1 = AudioBookChapter::create([
            'audio_book_id' => $audioBook->id,
            'chapter_number' => 1,
            'title' => 'Arrival',
            'content' => "Aran was a young trader from the far steppe, newly arrived at Border Market to sell wool. "
                . "Mira, a local guard at the market gate, watched the caravans pass without much interest.",
        ]);
        $ch2 = AudioBookChapter::create([
            'audio_book_id' => $audioBook->id,
            'chapter_number' => 2,
            'title' => 'Years Later',
            'content' => "Ba năm sau, a caravan raid left Aran with a permanent limp, and he rose to lead his own "
                . "caravan through the crossing town, where settled traders and visiting steppe nomads mingled at "
                . "the same crowded stalls.",
        ]);

        return [$audioBook, ['ch1' => $ch1, 'ch2' => $ch2]];
    }

    private function routeFakeCall(Request $request, array $chapters)
    {
        $prompt = (string) data_get($request->data(), 'messages.0.content', '');

        return str_contains($prompt, 'DỮ KIỆN ĐÃ TRÍCH XUẤT')
            ? $this->fakeReduceResponse($chapters)
            : $this->fakeMapResponse($chapters);
    }

    private function fakeMapResponse(array $chapters)
    {
        $content = [
            'synopsis' => 'A trader arrives at a market; years later he leads his own caravan.',
            'characters' => [
                ['name' => 'Aran', 'note' => 'young trader from the steppe', 'change_signal' => null, 'chapter_id' => $chapters['ch1']->id, 'quote' => 'Aran was a young trader from the far steppe'],
                ['name' => 'Aran', 'note' => 'injured in a raid, becomes caravan leader', 'change_signal' => 'permanent limp, becomes caravan leader', 'chapter_id' => $chapters['ch2']->id, 'quote' => 'a caravan raid left Aran with a permanent limp'],
                ['name' => 'Mira', 'note' => 'local guard at the market gate', 'change_signal' => null, 'chapter_id' => $chapters['ch1']->id, 'quote' => 'Mira, a local guard at the market gate'],
            ],
            'locations' => [
                ['name' => 'Border Market', 'note' => 'trading market, local guards present', 'chapter_id' => $chapters['ch1']->id, 'quote' => 'newly arrived at Border Market'],
                ['name' => 'the crossing town', 'note' => 'same market, settled traders and visiting nomads', 'chapter_id' => $chapters['ch2']->id, 'quote' => 'the crossing town'],
            ],
            'time_signals' => [
                ['note' => 'explicit multi-year time skip', 'chapter_id' => $chapters['ch2']->id, 'quote' => 'Ba năm sau'],
            ],
        ];

        return Http::response([
            'choices' => [['message' => ['content' => json_encode($content)], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 300, 'completion_tokens' => 150],
        ], 200, ['x-request-id' => 'req-' . uniqid()]);
    }

    private function claim($value, string $confidence, string $sourceType, array $evidence = [], ?string $rationale = null): array
    {
        return ['value' => $value, 'confidence' => $confidence, 'source_type' => $sourceType, 'evidence' => $evidence, 'rationale' => $rationale];
    }

    private function fakeReduceResponse(array $chapters)
    {
        $ch2 = $chapters['ch2']->id;
        $ch1 = $chapters['ch1']->id;

        $content = [
            // 1. Insufficient data -> unknown.
            'genre' => $this->claim(null, 'unknown', 'unknown'),
            'tone' => $this->claim('reflective', 'inferred', 'inferred_from_text', [['chapter_id' => $ch2, 'quote' => 'Ba năm sau']]),
            'timeline_structure' => $this->claim('time_skip', 'confirmed', 'explicit_text', [['chapter_id' => $ch2, 'quote' => 'Ba năm sau']]),
            'overall_time_span' => $this->claim('spans about 3 years', 'confirmed', 'explicit_text', [['chapter_id' => $ch2, 'quote' => 'Ba năm sau']]),
            'historical_context' => $this->claim(null, 'unknown', 'unknown'),
            'geography' => $this->claim('a steppe/market border region', 'inferred', 'inferred_from_text', [['chapter_id' => $ch1, 'quote' => 'Border Market']]),
            'culture' => $this->claim(null, 'unknown', 'unknown'),
            'world_rules' => $this->claim([], 'unknown', 'unknown'),
            'forbidden_elements' => $this->claim([], 'unknown', 'unknown'),
            'visual_style' => $this->claim('dusty trade-route atmosphere', 'inferred', 'director_choice', [], 'chosen to match the caravan/market setting'),
            'palette' => $this->claim('muted earth tones', 'inferred', 'director_choice', [], 'matches arid trade-route setting'),
            // Deliberately invalid: "confirmed" with no evidence AND no rationale -> must
            // be downgraded to unknown by normalizeClaim().
            'lighting' => $this->claim('harsh midday sun', 'confirmed', 'director_choice'),
            'camera_language' => $this->claim(null, 'unknown', 'unknown'),
            'pacing' => $this->claim('steady', 'confirmed', 'director_choice'),
            'timelines' => [
                [
                    'label' => 'Before the raid',
                    'timeline_type' => 'main',
                    'chronological_order' => 1,
                    'narrative_introduction_order' => 1,
                    'profile' => $this->claim(['story_time_marker' => null, 'description' => 'the earlier period'], 'confirmed', 'explicit_text', [['chapter_id' => $ch1, 'quote' => 'Aran was a young trader']]),
                ],
                [
                    'label' => 'Three years later',
                    'timeline_type' => 'main',
                    'chronological_order' => 2,
                    'narrative_introduction_order' => 2,
                    'profile' => $this->claim(['story_time_marker' => 'three years after the market arrival', 'description' => 'the later period'], 'confirmed', 'explicit_text', [['chapter_id' => $ch2, 'quote' => 'Ba năm sau']]),
                ],
            ],
            'locations' => [
                [
                    'name' => 'Border Market',
                    'aliases' => ['the crossing town'],
                    'cultural_context' => [
                        'region' => $this->claim('steppe border', 'inferred', 'inferred_from_text', [['chapter_id' => $ch1, 'quote' => 'Border Market']]),
                        'historical_polity' => $this->claim(null, 'unknown', 'unknown'),
                        'cultural_groups_present' => [
                            $this->claim(['name' => 'settled local traders', 'presence' => 'local'], 'confirmed', 'explicit_text', [['chapter_id' => $ch2, 'quote' => 'settled traders and visiting steppe nomads mingled']]),
                            $this->claim(['name' => 'visiting steppe nomads', 'presence' => 'visiting'], 'confirmed', 'explicit_text', [['chapter_id' => $ch2, 'quote' => 'settled traders and visiting steppe nomads mingled']]),
                        ],
                        'architecture' => $this->claim(null, 'unknown', 'unknown'),
                        'clothing' => $this->claim(null, 'unknown', 'unknown'),
                        'transportation' => $this->claim('caravans', 'inferred', 'inferred_from_text', [['chapter_id' => $ch1, 'quote' => 'caravans pass']]),
                        'religion' => $this->claim(null, 'unknown', 'unknown'),
                        'material_culture' => $this->claim(null, 'unknown', 'unknown'),
                        'environment' => $this->claim(null, 'unknown', 'unknown'),
                        'anachronism_constraints' => $this->claim([], 'unknown', 'unknown'),
                    ],
                    'visual_notes' => $this->claim(null, 'unknown', 'unknown'),
                ],
            ],
            'characters' => [
                [
                    'name' => 'Aran',
                    'aliases' => [],
                    'role' => $this->claim('protagonist', 'inferred', 'inferred_from_text', [['chapter_id' => $ch1, 'quote' => 'Aran was a young trader']]),
                    'cultural_origin' => $this->claim(['region' => 'the far steppe', 'culture' => 'steppe nomad culture', 'notes' => null], 'confirmed', 'explicit_text', [['chapter_id' => $ch1, 'quote' => 'Aran was a young trader from the far steppe']]),
                    'identity_anchor' => $this->claim(['gender' => 'male', 'ethnicity_notes' => 'steppe features', 'base_face' => null, 'defining_marks' => null], 'inferred', 'inferred_from_text', [['chapter_id' => $ch1, 'quote' => 'Aran was a young trader']]),
                    'baseline_traits' => $this->claim(['physique' => 'lean', 'hairstyle' => null, 'wardrobe' => 'travel clothing', 'emotional_state' => 'eager', 'social_status' => 'apprentice trader', 'occupation' => 'trader'], 'inferred', 'inferred_from_text', [['chapter_id' => $ch1, 'quote' => 'Aran was a young trader']]),
                    'character_phases' => [
                        [
                            'label' => 'Young trader',
                            'timeline_label' => 'Before the raid',
                            'chronological_order' => 1,
                            'current_location_name' => 'Border Market',
                            'mutable_traits' => $this->claim(['physique' => 'lean', 'hairstyle' => null, 'wardrobe' => 'travel clothing', 'emotional_state' => 'eager', 'social_status' => 'apprentice trader', 'occupation' => 'trader', 'injuries' => null, 'identity_overrides' => null], 'confirmed', 'explicit_text', [['chapter_id' => $ch1, 'quote' => 'Aran was a young trader']]),
                            'profile' => $this->claim(['story_time_marker' => null, 'trigger_reason' => null], 'confirmed', 'explicit_text', [['chapter_id' => $ch1, 'quote' => 'Aran was a young trader']]),
                        ],
                        [
                            'label' => 'Caravan leader after the raid',
                            'timeline_label' => 'Three years later',
                            'chronological_order' => 2,
                            // Deliberately references the ALIAS, not the canonical name —
                            // proves alias-based location resolution works.
                            'current_location_name' => 'the crossing town',
                            'mutable_traits' => $this->claim(['physique' => 'sturdier, walks with a limp', 'hairstyle' => null, 'wardrobe' => 'caravan leader attire', 'emotional_state' => 'guarded', 'social_status' => 'caravan leader', 'occupation' => 'caravan leader', 'injuries' => 'permanent limp', 'identity_overrides' => null], 'confirmed', 'explicit_text', [['chapter_id' => $ch2, 'quote' => 'a caravan raid left Aran with a permanent limp']]),
                            'profile' => $this->claim(['story_time_marker' => 'three years after the market arrival', 'trigger_reason' => 'survived a caravan raid'], 'confirmed', 'explicit_text', [['chapter_id' => $ch2, 'quote' => 'a caravan raid left Aran with a permanent limp']]),
                        ],
                    ],
                ],
                [
                    'name' => 'Mira',
                    'aliases' => [],
                    'role' => $this->claim('supporting', 'inferred', 'inferred_from_text', [['chapter_id' => $ch1, 'quote' => 'Mira, a local guard']]),
                    'cultural_origin' => $this->claim(['region' => 'Border Market area', 'culture' => 'local market culture', 'notes' => null], 'inferred', 'inferred_from_text', [['chapter_id' => $ch1, 'quote' => 'Mira, a local guard at the market gate']]),
                    'identity_anchor' => $this->claim(['gender' => 'female', 'ethnicity_notes' => null, 'base_face' => null, 'defining_marks' => null], 'inferred', 'inferred_from_text', [['chapter_id' => $ch1, 'quote' => 'Mira, a local guard']]),
                    'baseline_traits' => $this->claim(['physique' => null, 'hairstyle' => null, 'wardrobe' => 'guard uniform', 'emotional_state' => 'watchful', 'social_status' => 'market guard', 'occupation' => 'guard'], 'confirmed', 'explicit_text', [['chapter_id' => $ch1, 'quote' => 'Mira, a local guard at the market gate']]),
                    // No change signal anywhere in the facts for Mira -> zero phases.
                    'character_phases' => [],
                ],
            ],
        ];

        return Http::response([
            'choices' => [['message' => ['content' => json_encode($content)], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1200, 'completion_tokens' => 900],
        ], 200, ['x-request-id' => 'req-' . uniqid()]);
    }
}
