<?php

namespace Tests\Feature;

use App\Jobs\EnrichVideoShotsJob;
use App\Models\ApiUsage;
use App\Models\AudioBook;
use App\Models\AudiobookVideoScene;
use App\Models\User;
use App\Models\YoutubeChannel;
use App\Services\VideoSceneAnalysisService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Verifies the retry/logging contract for Stage B of the video pipeline
 * (EnrichVideoShotsJob): one HTTP request per job attempt, one api_usages row per
 * attempt (success or failure), chunk-scoped retry isolation, and no data loss/duplication
 * across a fresh run + a "retry failed chunks only" run.
 *
 * Book layout mirrors a real fault-injection run: 3 scenes / 203 shots total (68/68/67),
 * chunked at 15 shots/chunk -> 15 global chunks (indices 0-14). Chunk index 5 is scene 2's
 * first chunk (scene 1 contributes chunks 0-4), identified in fakes via the "SC2-0001"
 * marker unique to that chunk's prompt.
 */
class EnrichVideoShotsJobFaultInjectionTest extends TestCase
{
    use DatabaseTransactions;

    private const SCENE_SHOT_COUNTS = [68, 68, 67];
    private const CHUNK5_MARKER = 'SC2-0001 ';

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.openai.api_key', 'test-key');
    }

    public function test_chunk_fails_once_then_succeeds_on_retry(): void
    {
        [$audioBook] = $this->makeAudioBookWithScenes();

        $chunk5Attempts = 0;
        Http::fake(function (Request $request) use (&$chunk5Attempts) {
            $isChunk5 = str_contains((string) $request->body(), self::CHUNK5_MARKER);

            if ($isChunk5) {
                $chunk5Attempts++;
                if ($chunk5Attempts === 1) {
                    throw new ConnectionException('Simulated network blip on chunk 5, attempt 1');
                }
            }

            return $this->fakeSuccessResponse($request);
        });

        (new EnrichVideoShotsJob($audioBook->id))->handle(app(VideoSceneAnalysisService::class));

        // Exactly 2 HTTP calls reached chunk 5's marker: one failed, one succeeded.
        $this->assertSame(2, $chunk5Attempts);

        $pipeline = $audioBook->videoPipeline()->first();
        $chunks = collect($pipeline->shot_chunks);

        $chunk5 = $chunks->firstWhere('index', 5);
        $this->assertSame('done', $chunk5['status']);
        $this->assertSame(2, $chunk5['attempts']);

        // Every other chunk succeeded on its first attempt only.
        $others = $chunks->reject(fn($c) => $c['index'] === 5);
        $this->assertCount(14, $others);
        $this->assertTrue($others->every(fn($c) => $c['status'] === 'done' && $c['attempts'] === 1));

        // api_usages: one failed + one success row for chunk 5, nothing else duplicated.
        $bookUsages = $this->apiUsagesForBook($audioBook);
        $chunk5Usages = $bookUsages
            ->filter(fn($u) => (int) data_get($u->request_data, 'chunk_index') === 5)
            ->values();

        $this->assertCount(2, $chunk5Usages);
        $this->assertSame(['failed', 'success'], $chunk5Usages->pluck('status')->all());

        $failedRow = $chunk5Usages->firstWhere('status', 'failed');
        $this->assertNull($failedRow->tokens_used);
        $this->assertNull($failedRow->estimated_cost);
        $this->assertSame(ConnectionException::class, data_get($failedRow->response_data, 'error_type'));

        $successRow = $chunk5Usages->firstWhere('status', 'success');
        $this->assertNotNull($successRow->tokens_used);
        $this->assertNotNull($successRow->estimated_cost);

        // Total api_usages rows for this run: 14 single-attempt chunks + 2 for chunk 5.
        $this->assertSame(16, $bookUsages->count());

        // Final data: still 3 scenes / 203 shots, no duplicates from the retried chunk.
        $this->assertSame(3, $audioBook->videoScenes()->count());
        $this->assertSame(203, $this->totalShotCount($audioBook));

        $pipeline->refresh();
        $this->assertSame('analyzed', $pipeline->status);

        // Explicit status columns, not inferred from keywords/image_request presence.
        $anyShot = $audioBook->videoScenes()->first()->shots()->first();
        $this->assertSame('enriched', $anyShot->enrichment_status);
        $this->assertSame(VideoSceneAnalysisService::PROMPT_VERSION, $anyShot->prompt_version);
        $this->assertSame('unvalidated', $anyShot->validation_status);
        $this->assertNotNull($anyShot->enriched_at);
    }

    public function test_stale_prompt_version_forces_reenrichment_of_only_that_chunk(): void
    {
        [$audioBook] = $this->makeAudioBookWithScenes();

        Http::fake(fn(Request $request) => $this->fakeSuccessResponse($request));

        (new EnrichVideoShotsJob($audioBook->id))->handle(app(VideoSceneAnalysisService::class));

        $shot = $audioBook->videoScenes()->first()->shots()->first();
        $this->assertSame('enriched', $shot->enrichment_status);
        $this->assertSame(VideoSceneAnalysisService::PROMPT_VERSION, $shot->prompt_version);

        // Simulate a prompt-version bump having happened after this shot was enriched.
        $shot->update(['prompt_version' => 'v0']);

        $chunk0Calls = 0;
        $otherCalls = 0;
        Http::fake(function (Request $request) use (&$chunk0Calls, &$otherCalls) {
            if (str_contains((string) $request->body(), 'SC1-0001 ')) {
                $chunk0Calls++;
            } else {
                $otherCalls++;
            }

            return $this->fakeSuccessResponse($request);
        });

        (new EnrichVideoShotsJob($audioBook->id))->handle(app(VideoSceneAnalysisService::class));

        // Only the chunk containing the stale shot was re-sent to OpenAI; every other
        // already-current chunk was left untouched (zero extra HTTP calls/cost).
        $this->assertSame(1, $chunk0Calls);
        $this->assertSame(0, $otherCalls);

        $shot->refresh();
        $this->assertSame('enriched', $shot->enrichment_status);
        $this->assertSame(VideoSceneAnalysisService::PROMPT_VERSION, $shot->prompt_version);
        $this->assertSame(203, $this->totalShotCount($audioBook));
    }

    public function test_chunk_exhausts_all_retries_and_is_marked_failed_without_affecting_other_chunks(): void
    {
        [$audioBook, $user] = $this->makeAudioBookWithScenes(withUser: true);

        // Http::fake() callbacks accumulate (Factory::fake() merges, first non-null match
        // wins) rather than replacing — a single closure driven by this mutable flag lets
        // the retry-phase behavior change without an old "always fail chunk 5" stub still
        // shadowing it.
        $chunk5ShouldSucceed = false;
        Http::fake(function (Request $request) use (&$chunk5ShouldSucceed) {
            if (str_contains((string) $request->body(), self::CHUNK5_MARKER)) {
                if (!$chunk5ShouldSucceed) {
                    throw new ConnectionException('Simulated persistent network failure on chunk 5');
                }

                return $this->fakeSuccessResponse($request);
            }

            if ($chunk5ShouldSucceed) {
                throw new \RuntimeException('Retry-failed-chunks must not touch non-failed chunks.');
            }

            return $this->fakeSuccessResponse($request);
        });

        (new EnrichVideoShotsJob($audioBook->id))->handle(app(VideoSceneAnalysisService::class));

        $pipeline = $audioBook->videoPipeline()->first();
        $chunks = collect($pipeline->shot_chunks);

        $chunk5 = $chunks->firstWhere('index', 5);
        $this->assertSame('failed', $chunk5['status']);
        $this->assertSame(4, $chunk5['attempts']); // 1 initial + 3 retries (5s/15s/45s)
        $this->assertNotNull($chunk5['error']);

        $others = $chunks->reject(fn($c) => $c['index'] === 5);
        $this->assertTrue($others->every(fn($c) => $c['status'] === 'done'));

        // Pipeline reports a partial-failure state, not a false "all done".
        $this->assertSame('analyzed_with_errors', $pipeline->status);

        // No data lost: still 3 scenes / 203 shots; the successfully-enriched chunks keep
        // their keywords/image_request (buildFreshChunkPlan never overwrites those columns).
        $this->assertSame(203, $this->totalShotCount($audioBook));
        $doneShot = $audioBook->videoScenes()->first()->shots()->first();
        $this->assertNotEmpty($doneShot->keywords);
        $this->assertNotEmpty($doneShot->image_request);

        // "Thử lại phần lỗi" must only touch chunk 5 — flipping this makes chunk 5 succeed
        // while any (wrongly) re-sent request for another chunk now throws instead.
        $chunk5ShouldSucceed = true;

        $response = $this->actingAs($user)
            ->postJson(route('audiobooks.video.pipeline.retry-failed-chunks', $audioBook));

        $response->assertOk()->assertJson(['success' => true, 'retrying_chunks' => 1]);

        $pipeline->refresh();
        $finalChunks = collect($pipeline->shot_chunks);
        $this->assertTrue($finalChunks->every(fn($c) => $c['status'] === 'done'));
        $this->assertSame('analyzed', $pipeline->status);
        $this->assertSame(203, $this->totalShotCount($audioBook));
    }

    /**
     * @return array{0:AudioBook,1:?User}
     */
    private function makeAudioBookWithScenes(bool $withUser = false): array
    {
        $channel = YoutubeChannel::create([
            'channel_id' => 'UC_test_' . uniqid(),
            'title' => 'Test Channel',
        ]);

        $audioBook = AudioBook::create([
            'youtube_channel_id' => $channel->id,
            'title' => 'Fault Injection Test Book',
        ]);

        foreach (self::SCENE_SHOT_COUNTS as $i => $count) {
            AudiobookVideoScene::create([
                'audio_book_id' => $audioBook->id,
                'scene_index' => $i + 1,
                'title' => 'Scene ' . ($i + 1),
                'script_text' => $this->buildSceneText($i + 1, $count),
                'scene_type' => 'city',
            ]);
        }

        $user = $withUser ? User::factory()->create() : null;

        return [$audioBook, $user];
    }

    private function buildSceneText(int $sceneNum, int $count): string
    {
        $sentences = [];
        for ($i = 1; $i <= $count; $i++) {
            $prefix = sprintf('SC%d-%04d ', $sceneNum, $i);
            $padLen = max(0, 99 - mb_strlen($prefix));
            $sentences[] = $prefix . str_repeat('a', $padLen) . '.';
        }

        return implode(' ', $sentences);
    }

    private function totalShotCount(AudioBook $audioBook): int
    {
        return $audioBook->videoScenes()->withCount('shots')->get()->sum('shots_count');
    }

    /**
     * The dev database this test suite runs against already has real historical api_usages
     * rows, so queries must scope to this book's id (stored in request_data since the table
     * has no book_id column) rather than just filtering by purpose.
     */
    private function apiUsagesForBook(AudioBook $audioBook)
    {
        return ApiUsage::where('purpose', 'video_shot_enrich')
            ->get()
            ->filter(fn($u) => (int) data_get($u->request_data, 'book_id') === $audioBook->id)
            ->values();
    }

    private function fakeSuccessResponse(Request $request)
    {
        $prompt = (string) data_get($request->data(), 'messages.0.content', '');
        preg_match_all('/\[(\d+)\]/', $prompt, $matches);
        $indices = array_map('intval', $matches[1]);

        $shots = array_map(fn($idx) => [
            'index' => $idx,
            'is_real_world' => true,
            'keywords' => ['test', 'keyword'],
            'image_request' => 'A test image request for shot ' . $idx . '.',
        ], $indices);

        return Http::response([
            'choices' => [[
                'message' => ['content' => json_encode(['shots' => $shots])],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 500, 'completion_tokens' => 200],
        ], 200, ['x-request-id' => 'req-' . uniqid()]);
    }
}
