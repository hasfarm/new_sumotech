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
 * Regression test for the exact fixture used by `php artisan story-bible:smoke-test`
 * (the live-OpenAI smoke test — see that command for the real, non-faked equivalent).
 * A real gpt-5-mini run against this fixture initially produced bogus `characters` rows
 * for generic plural cultural-group mentions ("thương nhân miền núi", "cư dân ven sông")
 * that duplicated what was already correctly captured under a location's
 * `cultural_groups_present` — fixed by both tightening the map/reduce prompts AND adding a
 * defensive name-cross-check in `normalizeReducedBible()` (StoryBibleAnalysisService.php),
 * since prompt wording alone isn't reliably obeyed by the model on every run.
 *
 * This test is deterministic (Http::fake, no real API call) and asserts the DEFENSIVE CODE
 * PATH specifically: even when a reduce response still contains a duplicate-of-a-cultural-
 * group "character" (as actually observed live), it must not be persisted as a character.
 */
class StoryBibleSmokeTestFixtureRegressionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.openai.api_key', 'test-key');
    }

    public function test_plural_cultural_group_mentions_are_not_persisted_as_characters(): void
    {
        [$audioBook, $chapters] = $this->makeFixtureBook();

        Http::fake(function (Request $request) use ($chapters) {
            $prompt = (string) data_get($request->data(), 'messages.0.content', '');
            return str_contains($prompt, 'DỮ KIỆN ĐÃ TRÍCH XUẤT')
                ? $this->fakeReduceResponseWithBuggyGroupCharacters($chapters)
                : $this->fakeMapResponse($chapters);
        });

        (new AnalyzeStoryDirectionJob($audioBook->id))->handle(app(StoryBibleAnalysisService::class));

        $bible = AudiobookStoryBible::where('audio_book_id', $audioBook->id)->where('is_active', true)->firstOrFail();

        // The two generic-group "characters" the fake deliberately includes (mirroring the
        // real bug) must NOT have been persisted.
        $names = $bible->characters()->pluck('canonical_name')->all();
        $this->assertNotContains('thương nhân miền núi', $names);
        $this->assertNotContains('cư dân ven sông', $names);

        // Genuine individual characters are kept.
        $this->assertContains('Sela', $names);
        $this->assertContains('Doran', $names);
        $this->assertCount(2, $names);

        // The group data itself is still correctly present on the location, local + visiting.
        $location = $bible->locations()->where('canonical_name', 'Rivergate')->firstOrFail();
        $presences = collect($location->cultural_context['cultural_groups_present'])->pluck('value.presence')->all();
        $this->assertContains('local', $presences);
        $this->assertContains('visiting', $presences);
    }

    /**
     * @return array{0:AudioBook,1:array<string,AudioBookChapter>}
     */
    private function makeFixtureBook(): array
    {
        $channel = YoutubeChannel::create(['channel_id' => 'UC_sbfixture_' . uniqid(), 'title' => 'Smoke Test Channel']);
        $audioBook = AudioBook::create(['youtube_channel_id' => $channel->id, 'title' => 'Story Bible Smoke Test Fixture']);

        $ch1 = AudioBookChapter::create([
            'audio_book_id' => $audioBook->id,
            'chapter_number' => 1,
            'title' => 'Con Đường Thương Nhân',
            'content' => 'Sela là một nhà vẽ bản đồ trẻ tuổi, xuất thân từ Liên Minh Thương Mại Duyên Hải, lần đầu đặt '
                . 'chân đến Rivergate, một thị trấn có tường thành bên bờ sông. Tại cổng thành, Doran, một người lính '
                . 'gác điềm tĩnh của thị trấn, kiểm tra giấy thông hành của cô. Doran đã gác cổng này từ nhiều năm nay '
                . 'và ít khi rời khỏi vị trí của mình.',
        ]);
        $ch2 = AudioBookChapter::create([
            'audio_book_id' => $audioBook->id,
            'chapter_number' => 2,
            'title' => 'Hồi Ức: Bản Hợp Đồng Cũ',
            'content' => 'Nhiều năm trước khi đến Rivergate, Sela đã bí mật ký một bản hợp đồng với một thương nhân '
                . 'miền núi tại khu chợ đầu nguồn. Đó là quãng thời gian cô còn là một người học việc rụt rè, chưa '
                . 'từng nghĩ mình sẽ trở thành người đứng đầu một hội thương gia.',
        ]);
        $ch3 = AudioBookChapter::create([
            'audio_book_id' => $audioBook->id,
            'chapter_number' => 3,
            'title' => 'Mười Năm Sau',
            'content' => "Mười năm sau ngày đặt chân tới Rivergate, Sela giờ đây là thủ lĩnh của hội thương gia riêng, "
                . 'tay trái mang một vết sẹo dài do tai nạn trên sông, khoác áo choàng màu xanh lá của hội. Tại nơi mà '
                . "dân địa phương gọi là 'bến sông cũ' — chính là Rivergate năm xưa — thương nhân miền núi và cư dân "
                . 'ven sông vẫn cùng nhau buôn bán tại khu chợ.',
        ]);

        return [$audioBook, ['ch1' => $ch1, 'ch2' => $ch2, 'ch3' => $ch3]];
    }

    private function fakeMapResponse(array $chapters)
    {
        $content = [
            'synopsis' => 'A cartographer arrives at a border town; years later she leads her own trade guild.',
            'characters' => [
                ['name' => 'Sela', 'note' => 'young cartographer', 'change_signal' => null, 'chapter_id' => $chapters['ch1']->id, 'quote' => 'Sela là một nhà vẽ bản đồ trẻ tuổi'],
                ['name' => 'Sela', 'note' => 'now a guild leader with a scar', 'change_signal' => 'scar, guild leader now', 'chapter_id' => $chapters['ch3']->id, 'quote' => 'Mười năm sau ngày đặt chân tới Rivergate'],
                ['name' => 'Doran', 'note' => 'steady town guard', 'change_signal' => null, 'chapter_id' => $chapters['ch1']->id, 'quote' => 'Doran, một người lính gác điềm tĩnh'],
            ],
            'locations' => [
                ['name' => 'Rivergate', 'note' => 'walled river town, mountain traders and river residents present', 'chapter_id' => $chapters['ch1']->id, 'quote' => 'Rivergate, một thị trấn có tường thành bên bờ sông'],
                ['name' => 'bến sông cũ', 'note' => 'same town, alternate name', 'chapter_id' => $chapters['ch3']->id, 'quote' => "dân địa phương gọi là 'bến sông cũ'"],
            ],
            'time_signals' => [
                ['note' => 'flashback to years before', 'chapter_id' => $chapters['ch2']->id, 'quote' => 'Nhiều năm trước khi đến Rivergate'],
                ['note' => 'explicit ten-year skip', 'chapter_id' => $chapters['ch3']->id, 'quote' => 'Mười năm sau'],
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

    /**
     * Mirrors the ACTUAL first live-smoke-test response: a valid Rivergate location with
     * correct local+visiting cultural_groups_present, but the reduce step ALSO emits two
     * bogus "characters" — "thương nhân miền núi" and "cư dân ven sông" — that duplicate
     * those same group names. This is deliberately left in to prove the defensive
     * cross-check in normalizeReducedBible() catches it even though the prompt says not to.
     */
    private function fakeReduceResponseWithBuggyGroupCharacters(array $chapters)
    {
        $ch1 = $chapters['ch1']->id;
        $ch2 = $chapters['ch2']->id;
        $ch3 = $chapters['ch3']->id;

        $content = [
            'genre' => $this->claim(null, 'unknown', 'unknown'),
            'tone' => $this->claim(null, 'unknown', 'unknown'),
            'timeline_structure' => $this->claim('time_skip', 'confirmed', 'explicit_text', [['chapter_id' => $ch3, 'quote' => 'Mười năm sau']]),
            'overall_time_span' => $this->claim('about ten years', 'confirmed', 'explicit_text', [['chapter_id' => $ch3, 'quote' => 'Mười năm sau']]),
            'historical_context' => $this->claim(null, 'unknown', 'unknown'),
            'geography' => $this->claim('a river border town', 'inferred', 'inferred_from_text', [['chapter_id' => $ch1, 'quote' => 'bên bờ sông']]),
            'culture' => $this->claim(null, 'unknown', 'unknown'),
            'world_rules' => $this->claim([], 'unknown', 'unknown'),
            'forbidden_elements' => $this->claim([], 'unknown', 'unknown'),
            'visual_style' => $this->claim(null, 'unknown', 'unknown'),
            'palette' => $this->claim(null, 'unknown', 'unknown'),
            'lighting' => $this->claim(null, 'unknown', 'unknown'),
            'camera_language' => $this->claim(null, 'unknown', 'unknown'),
            'pacing' => $this->claim(null, 'unknown', 'unknown'),
            'timelines' => [
                ['label' => 'Before the contract', 'timeline_type' => 'flashback', 'chronological_order' => 1, 'narrative_introduction_order' => 2, 'profile' => $this->claim(['story_time_marker' => null, 'description' => null], 'confirmed', 'explicit_text', [['chapter_id' => $ch2, 'quote' => 'Nhiều năm trước khi đến Rivergate']])],
                ['label' => 'Ten years later', 'timeline_type' => 'main', 'chronological_order' => 2, 'narrative_introduction_order' => 3, 'profile' => $this->claim(['story_time_marker' => 'ten years after arrival', 'description' => null], 'confirmed', 'explicit_text', [['chapter_id' => $ch3, 'quote' => 'Mười năm sau']])],
            ],
            'locations' => [
                [
                    'name' => 'Rivergate',
                    'aliases' => ['bến sông cũ'],
                    'cultural_context' => [
                        'region' => $this->claim('river border', 'inferred', 'inferred_from_text', [['chapter_id' => $ch1, 'quote' => 'bên bờ sông']]),
                        'historical_polity' => $this->claim(null, 'unknown', 'unknown'),
                        'cultural_groups_present' => [
                            $this->claim(['name' => 'thương nhân miền núi', 'presence' => 'visiting'], 'confirmed', 'explicit_text', [['chapter_id' => $ch3, 'quote' => 'thương nhân miền núi và cư dân']]),
                            $this->claim(['name' => 'cư dân ven sông', 'presence' => 'local'], 'confirmed', 'explicit_text', [['chapter_id' => $ch3, 'quote' => 'cư dân ven sông vẫn cùng nhau buôn bán']]),
                        ],
                        'architecture' => $this->claim(null, 'unknown', 'unknown'),
                        'clothing' => $this->claim(null, 'unknown', 'unknown'),
                        'transportation' => $this->claim(null, 'unknown', 'unknown'),
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
                    'name' => 'Sela',
                    'aliases' => [],
                    'role' => $this->claim('protagonist', 'inferred', 'inferred_from_text', [['chapter_id' => $ch1, 'quote' => 'Sela là một nhà vẽ bản đồ']]),
                    'cultural_origin' => $this->claim(['region' => 'coastal trade league', 'culture' => null, 'notes' => null], 'inferred', 'inferred_from_text', [['chapter_id' => $ch1, 'quote' => 'Liên Minh Thương Mại Duyên Hải']]),
                    'identity_anchor' => $this->claim(['gender' => 'female', 'ethnicity_notes' => null, 'base_face' => null, 'defining_marks' => null], 'inferred', 'inferred_from_text', [['chapter_id' => $ch1, 'quote' => 'Sela là một nhà vẽ bản đồ']]),
                    'baseline_traits' => $this->claim(['physique' => null, 'hairstyle' => null, 'wardrobe' => null, 'emotional_state' => null, 'social_status' => 'apprentice cartographer', 'occupation' => 'cartographer'], 'inferred', 'inferred_from_text', [['chapter_id' => $ch1, 'quote' => 'Sela là một nhà vẽ bản đồ']]),
                    'character_phases' => [
                        [
                            'label' => 'Young cartographer',
                            'timeline_label' => 'Before the contract',
                            'chronological_order' => 1,
                            'current_location_name' => null,
                            'mutable_traits' => $this->claim(['physique' => null, 'hairstyle' => null, 'wardrobe' => null, 'emotional_state' => 'timid', 'social_status' => 'apprentice', 'occupation' => 'cartographer', 'injuries' => null, 'identity_overrides' => null], 'confirmed', 'explicit_text', [['chapter_id' => $ch2, 'quote' => 'người học việc rụt rè']]),
                            'profile' => $this->claim(['story_time_marker' => null, 'trigger_reason' => null], 'confirmed', 'explicit_text', [['chapter_id' => $ch2, 'quote' => 'người học việc rụt rè']]),
                        ],
                        [
                            'label' => 'Guild leader after the accident',
                            'timeline_label' => 'Ten years later',
                            'chronological_order' => 2,
                            'current_location_name' => 'bến sông cũ',
                            'mutable_traits' => $this->claim(['physique' => 'scarred left hand', 'hairstyle' => null, 'wardrobe' => 'guild green robes', 'emotional_state' => null, 'social_status' => 'guild leader', 'occupation' => 'guild leader', 'injuries' => 'scar on left hand from a river accident', 'identity_overrides' => null], 'confirmed', 'explicit_text', [['chapter_id' => $ch3, 'quote' => 'vết sẹo dài do tai nạn trên sông']]),
                            'profile' => $this->claim(['story_time_marker' => 'ten years after arrival', 'trigger_reason' => 'river accident'], 'confirmed', 'explicit_text', [['chapter_id' => $ch3, 'quote' => 'vết sẹo dài do tai nạn trên sông']]),
                        ],
                    ],
                ],
                [
                    'name' => 'Doran',
                    'aliases' => [],
                    'role' => $this->claim('supporting', 'inferred', 'inferred_from_text', [['chapter_id' => $ch1, 'quote' => 'Doran, một người lính gác']]),
                    'cultural_origin' => $this->claim(null, 'unknown', 'unknown'),
                    'identity_anchor' => $this->claim(['gender' => 'male', 'ethnicity_notes' => null, 'base_face' => null, 'defining_marks' => null], 'inferred', 'inferred_from_text', [['chapter_id' => $ch1, 'quote' => 'Doran, một người lính gác']]),
                    'baseline_traits' => $this->claim(['physique' => null, 'hairstyle' => null, 'wardrobe' => 'guard uniform', 'emotional_state' => 'calm', 'social_status' => 'town guard', 'occupation' => 'guard'], 'confirmed', 'explicit_text', [['chapter_id' => $ch1, 'quote' => 'Doran, một người lính gác điềm tĩnh']]),
                    'character_phases' => [],
                ],
                // Bug being regression-tested: these two duplicate the cultural_groups_present
                // entries above and must be dropped by normalizeReducedBible(), not persisted.
                [
                    'name' => 'thương nhân miền núi',
                    'aliases' => [],
                    'role' => $this->claim(null, 'unknown', 'unknown'),
                    'cultural_origin' => $this->claim(null, 'unknown', 'unknown'),
                    'identity_anchor' => $this->claim(null, 'unknown', 'unknown'),
                    'baseline_traits' => $this->claim(null, 'unknown', 'unknown'),
                    'character_phases' => [],
                ],
                [
                    'name' => 'cư dân ven sông',
                    'aliases' => [],
                    'role' => $this->claim(null, 'unknown', 'unknown'),
                    'cultural_origin' => $this->claim(null, 'unknown', 'unknown'),
                    'identity_anchor' => $this->claim(null, 'unknown', 'unknown'),
                    'baseline_traits' => $this->claim(null, 'unknown', 'unknown'),
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
