<?php

namespace App\Console\Commands;

use App\Jobs\AnalyzeStoryDirectionJob;
use App\Models\ApiUsage;
use App\Models\AudioBook;
use App\Models\AudioBookChapter;
use App\Models\AudiobookStoryBible;
use App\Models\YoutubeChannel;
use App\Services\StoryBibleAnalysisService;
use Illuminate\Console\Command;

/**
 * Live smoke test against the REAL OpenAI API (no Http::fake) for both the map
 * (story_bible_batch_extraction) and reduce (story_bible) strict json_schema calls — proves
 * the schemas are actually accepted by the API, not just internally self-consistent.
 *
 * Usage:
 *   php artisan story-bible:smoke-test              # synthetic fixture, auto-cleanup after
 *   php artisan story-bible:smoke-test --keep        # synthetic fixture, keep the rows
 *   php artisan story-bible:smoke-test --audio-book-id=73   # real content, never deleted
 */
class StoryBibleSmokeTest extends Command
{
    protected $signature = 'story-bible:smoke-test {--audio-book-id=} {--keep}';
    protected $description = 'Live OpenAI smoke test for the Story Bible map/reduce json_schema calls';

    public function handle(StoryBibleAnalysisService $service): int
    {
        $apiKey = trim((string) config('services.openai.api_key', ''));
        if ($apiKey === '') {
            $this->error('OPENAI_API_KEY chưa được cấu hình — không thể chạy live smoke test.');
            return self::FAILURE;
        }

        $audioBookIdOpt = $this->option('audio-book-id');
        $isFixture = $audioBookIdOpt === null;

        if ($isFixture) {
            $audioBook = $this->createFixtureBook();
            $this->info("Đã tạo fixture book #{$audioBook->id} với " . $audioBook->chapters()->count() . ' chương.');
        } else {
            $audioBook = AudioBook::with('chapters')->find((int) $audioBookIdOpt);
            if (!$audioBook || $audioBook->chapters->isEmpty()) {
                $this->error("Audiobook #{$audioBookIdOpt} không tồn tại hoặc chưa có chương nào.");
                return self::FAILURE;
            }
            $this->info("Dùng nội dung thật của book #{$audioBook->id} ({$audioBook->title}) — " . $audioBook->chapters->count() . ' chương.');
        }

        $before = ApiUsage::max('id') ?? 0;
        $start = microtime(true);

        (new AnalyzeStoryDirectionJob($audioBook->id))->handle($service);

        $wallSeconds = round(microtime(true) - $start, 2);

        $calls = ApiUsage::where('id', '>', $before)
            ->whereIn('purpose', ['story_bible_extract', 'story_bible_reduce'])
            ->orderBy('id')
            ->get();

        $this->printCallReport($calls, $wallSeconds);

        $bible = AudiobookStoryBible::where('audio_book_id', $audioBook->id)->where('is_active', true)->first();
        $lastDraft = AudiobookStoryBible::where('audio_book_id', $audioBook->id)->latest('bible_version')->first();

        if (!$bible) {
            $this->error('KHÔNG có bible active sau khi chạy — smoke test THẤT BẠI.');
            $this->line('Trạng thái draft cuối: ' . ($lastDraft->status ?? 'n/a'));
            $this->line('error_message: ' . ($lastDraft->error_message ?? '(none)'));
            $this->line('batches ledger: ' . json_encode($lastDraft->batches ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            if ($isFixture && !$this->option('keep')) {
                $audioBook->delete();
            }
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('=== KẾT QUẢ ===');
        $this->line("bible_version active: {$bible->bible_version}");
        $this->line('timelines: ' . $bible->timelines()->count());
        $this->line('locations: ' . $bible->locations()->count());
        $this->line('characters: ' . $bible->characters()->count());
        $this->line('character_phases: ' . \App\Models\AudiobookCharacterPhase::whereIn(
            'character_id',
            $bible->characters()->pluck('id')
        )->count());

        $this->newLine();
        $this->info('Genre claim: ' . json_encode($bible->source_facts['genre'] ?? null, JSON_UNESCAPED_UNICODE));
        $this->info('Timeline structure claim: ' . json_encode($bible->source_facts['timeline_structure'] ?? null, JSON_UNESCAPED_UNICODE));

        foreach ($bible->characters as $c) {
            $this->line("- {$c->canonical_name}: " . $c->phases()->count() . ' phase(s)');
        }
        foreach ($bible->locations as $l) {
            $groups = collect($l->cultural_context['cultural_groups_present'] ?? [])->pluck('value.presence')->implode(',');
            $this->line("- location {$l->canonical_name} (aliases: " . implode(',', $l->aliases ?? []) . ") groups=[{$groups}]");
        }

        if ($isFixture && !$this->option('keep')) {
            $audioBook->delete(); // cascades: story bibles -> children
            $this->line('(fixture book đã được xoá — dùng --keep để giữ lại)');
        }

        return self::SUCCESS;
    }

    private function printCallReport($calls, float $wallSeconds): void
    {
        $this->newLine();
        $this->info('=== API CALLS ===');
        $this->line("Tổng thời gian wall-clock: {$wallSeconds}s | Số API calls: " . $calls->count());

        $totalCost = 0;
        $totalTokens = 0;

        foreach ($calls as $u) {
            $model = (string) preg_replace('/^model=(\S+).*/', '$1', (string) $u->description);
            $httpStatus = data_get($u->response_data, 'http_status');
            $requestId = data_get($u->response_data, 'provider_request_id');
            $usage = data_get($u->response_data, 'usage', []);
            $reasoningTokens = data_get($usage, 'completion_tokens_details.reasoning_tokens');

            $this->line(sprintf(
                '[%s] purpose=%s status=%s http=%s duration=%ss tokens=%s (in=%s out=%s reasoning=%s) cost=$%s request_id=%s',
                $u->id,
                $u->purpose,
                $u->status,
                $httpStatus ?? 'n/a',
                $u->duration_seconds,
                $u->tokens_used ?? 'null',
                data_get($usage, 'prompt_tokens', 'n/a'),
                data_get($usage, 'completion_tokens', 'n/a'),
                $reasoningTokens ?? 'n/a',
                $u->estimated_cost ?? 'null',
                $requestId ?? 'n/a'
            ));

            if ($u->status === 'failed') {
                $this->warn('  error: ' . $u->error_message);
            }

            $totalCost += (float) ($u->estimated_cost ?? 0);
            $totalTokens += (int) ($u->tokens_used ?? 0);
        }

        $model = $calls->isNotEmpty() ? preg_replace('/^model=(\S+).*/', '$1', (string) $calls->first()->description) : 'n/a';
        $this->line("Model: {$model} | Tổng tokens: {$totalTokens} | Tổng chi phí ước tính: \${$totalCost}");
    }

    private function createFixtureBook(): AudioBook
    {
        $channel = YoutubeChannel::create(['channel_id' => 'UC_smoketest_' . uniqid(), 'title' => 'Smoke Test Channel']);
        $audioBook = AudioBook::create(['youtube_channel_id' => $channel->id, 'title' => 'Story Bible Smoke Test Fixture']);

        AudioBookChapter::create([
            'audio_book_id' => $audioBook->id,
            'chapter_number' => 1,
            'title' => 'Con Đường Thương Nhân',
            'content' => 'Sela là một nhà vẽ bản đồ trẻ tuổi, xuất thân từ Liên Minh Thương Mại Duyên Hải, lần đầu đặt '
                . 'chân đến Rivergate, một thị trấn có tường thành bên bờ sông. Tại cổng thành, Doran, một người lính '
                . 'gác điềm tĩnh của thị trấn, kiểm tra giấy thông hành của cô. Doran đã gác cổng này từ nhiều năm nay '
                . 'và ít khi rời khỏi vị trí của mình.',
        ]);

        AudioBookChapter::create([
            'audio_book_id' => $audioBook->id,
            'chapter_number' => 2,
            'title' => 'Hồi Ức: Bản Hợp Đồng Cũ',
            'content' => 'Nhiều năm trước khi đến Rivergate, Sela đã bí mật ký một bản hợp đồng với một thương nhân '
                . 'miền núi tại khu chợ đầu nguồn. Đó là quãng thời gian cô còn là một người học việc rụt rè, chưa '
                . 'từng nghĩ mình sẽ trở thành người đứng đầu một hội thương gia.',
        ]);

        AudioBookChapter::create([
            'audio_book_id' => $audioBook->id,
            'chapter_number' => 3,
            'title' => 'Mười Năm Sau',
            'content' => "Mười năm sau ngày đặt chân tới Rivergate, Sela giờ đây là thủ lĩnh của hội thương gia riêng, "
                . 'tay trái mang một vết sẹo dài do tai nạn trên sông, khoác áo choàng màu xanh lá của hội. Tại nơi mà '
                . "dân địa phương gọi là 'bến sông cũ' — chính là Rivergate năm xưa — thương nhân miền núi và cư dân "
                . 'ven sông vẫn cùng nhau buôn bán tại khu chợ.',
        ]);

        return $audioBook->fresh(['chapters']);
    }
}
