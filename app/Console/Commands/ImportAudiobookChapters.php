<?php

namespace App\Console\Commands;

use App\Models\AudioBook;
use App\Models\AudioBookChapter;
use App\Models\YoutubeChannel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Generic chapter import for real audiobook content — used to seed the dev DB for Phase
 * 2-4's production-acceptance runs. Deliberately contains no book-specific content or
 * assumptions of its own; every piece of text comes from the file(s) the caller points it
 * at, and the split pattern is configurable rather than tuned to any one book.
 *
 * Usage:
 *   # One file per chapter (recommended — most robust, no ambiguity):
 *   php artisan audiobook:import-chapters --title="Book Title" --author="Author" --dir=storage/app/import/my-book
 *
 *   # One big text file, auto-split on chapter headings:
 *   php artisan audiobook:import-chapters --title="Book Title" --file=storage/app/import/my-book.txt
 *
 *   # Preview without writing to DB:
 *   php artisan audiobook:import-chapters --title="Book Title" --dir=... --dry-run
 *
 *   # Add more chapters to an already-imported book:
 *   php artisan audiobook:import-chapters --audio-book-id=5 --dir=...
 */
class ImportAudiobookChapters extends Command
{
    protected $signature = 'audiobook:import-chapters
        {--audio-book-id= : Attach to an existing AudioBook instead of creating one}
        {--title= : Title for a new AudioBook (required if --audio-book-id is not given)}
        {--author=}
        {--category=}
        {--dir= : Directory of chapter files, one file per chapter, natural-sorted by filename}
        {--file= : Single text file to split into chapters}
        {--split-pattern= : PCRE multiline pattern marking the start of a new chapter when using --file (default matches "Chương N" / "Chapter N")}
        {--reset : Delete this AudioBook\'s existing chapters before importing}
        {--dry-run : Parse and report without writing anything to the database}';

    protected $description = 'Import a real audiobook\'s chapters (from a directory or a single text file) for pipeline testing/production-acceptance runs';

    // Must have exactly one capturing group around the WHOLE heading line — PREG_SPLIT_DELIM_CAPTURE
    // only captures matched text that's inside a capturing group, not a non-capturing (?:...) one.
    private const DEFAULT_SPLIT_PATTERN = '/^\s*((?:Chương|CHƯƠNG|Chapter|CHAPTER)\s+\d+[^\n]*)$/mu';

    public function handle(): int
    {
        $dir = $this->option('dir');
        $file = $this->option('file');

        if (!$dir && !$file) {
            $this->error('Cần chỉ định --dir hoặc --file.');
            return self::FAILURE;
        }
        if ($dir && $file) {
            $this->error('Chỉ dùng MỘT trong hai: --dir hoặc --file, không dùng cả hai.');
            return self::FAILURE;
        }

        $chapters = $dir ? $this->parseDirectory($dir) : $this->parseSingleFile($file);

        if (empty($chapters)) {
            $this->error('Không tìm thấy chương nào từ nguồn đã cho.');
            return self::FAILURE;
        }

        $totalChars = array_sum(array_map(fn($c) => mb_strlen($c['content']), $chapters));
        $this->info('Đã parse ' . count($chapters) . ' chương, tổng ' . number_format($totalChars) . ' ký tự.');
        foreach ($chapters as $i => $c) {
            $this->line(sprintf('  [%d] %s (%s ký tự) — %s...', $i + 1, $c['title'], number_format(mb_strlen($c['content'])), mb_substr(trim($c['content']), 0, 80)));
        }

        if ($this->option('dry-run')) {
            $this->info('(--dry-run: chưa ghi gì vào database)');
            return self::SUCCESS;
        }

        $audioBookIdOpt = $this->option('audio-book-id');
        if ($audioBookIdOpt) {
            $audioBook = AudioBook::find((int) $audioBookIdOpt);
            if (!$audioBook) {
                $this->error("AudioBook #{$audioBookIdOpt} không tồn tại.");
                return self::FAILURE;
            }
        } else {
            $title = $this->option('title');
            if (!$title) {
                $this->error('Cần --title khi tạo AudioBook mới (hoặc dùng --audio-book-id để import vào sách có sẵn).');
                return self::FAILURE;
            }
            $channel = YoutubeChannel::firstOrCreate(
                ['channel_id' => 'import_' . str()->slug($title)],
                ['title' => 'Imported: ' . $title]
            );
            $audioBook = AudioBook::create([
                'youtube_channel_id' => $channel->id,
                'title' => $title,
                'author' => $this->option('author'),
                'category' => $this->option('category'),
            ]);
            $this->info("Đã tạo AudioBook #{$audioBook->id}.");
        }

        if ($this->option('reset')) {
            $deleted = AudioBookChapter::where('audio_book_id', $audioBook->id)->delete();
            $this->info("Đã xoá {$deleted} chương cũ.");
        }

        $startNumber = (AudioBookChapter::where('audio_book_id', $audioBook->id)->max('chapter_number') ?? 0) + 1;
        foreach ($chapters as $i => $c) {
            AudioBookChapter::create([
                'audio_book_id' => $audioBook->id,
                'chapter_number' => $startNumber + $i,
                'title' => $c['title'],
                'content' => $c['content'],
            ]);
        }

        $audioBook->update(['total_chapters' => AudioBookChapter::where('audio_book_id', $audioBook->id)->count()]);

        $this->info("Đã import " . count($chapters) . " chương vào AudioBook #{$audioBook->id} ({$audioBook->title}).");
        $this->line("Chạy tiếp: php artisan story-bible:smoke-test --audio-book-id={$audioBook->id}");

        return self::SUCCESS;
    }

    /**
     * @return array<int,array{title:string,content:string}>
     */
    private function parseDirectory(string $dir): array
    {
        if (!File::isDirectory($dir)) {
            $this->error("Thư mục không tồn tại: {$dir}");
            return [];
        }

        $files = collect(File::files($dir))
            ->filter(fn($f) => in_array(strtolower($f->getExtension()), ['txt', 'md']))
            ->sortBy(fn($f) => $f->getFilename(), SORT_NATURAL)
            ->values();

        return $files->map(function ($f) {
            $content = trim(File::get($f->getPathname()));
            // First non-empty line is the chapter title if the file doesn't look like it
            // starts straight into narration; otherwise fall back to the filename. When used
            // as the title, strip it from content so it isn't duplicated in the chapter body.
            $firstLine = trim(strtok($content, "\n") ?: '');
            if ($firstLine !== '' && mb_strlen($firstLine) <= 120) {
                $title = $firstLine;
                $content = trim(mb_substr($content, mb_strlen($firstLine)));
            } else {
                $title = pathinfo($f->getFilename(), PATHINFO_FILENAME);
            }

            return ['title' => $title, 'content' => $content];
        })->all();
    }

    /**
     * @return array<int,array{title:string,content:string}>
     */
    private function parseSingleFile(string $file): array
    {
        if (!File::exists($file)) {
            $this->error("File không tồn tại: {$file}");
            return [];
        }

        $text = File::get($file);
        $pattern = $this->option('split-pattern') ?: self::DEFAULT_SPLIT_PATTERN;

        $parts = preg_split($pattern, $text, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false || count($parts) < 2) {
            $this->warn('Không tìm thấy ranh giới chương theo pattern — coi toàn bộ file là MỘT chương.');
            return [['title' => pathinfo($file, PATHINFO_FILENAME), 'content' => trim($text)]];
        }

        // With PREG_SPLIT_DELIM_CAPTURE, the array alternates [pre-match-text?, heading, body, heading, body, ...].
        // Leading text before the first real heading is real content (an introduction/
        // preface), not noise — keep it as its own chapter rather than silently discarding it.
        $chapters = [];
        if (!preg_match($pattern, $parts[0]) && trim($parts[0]) !== '') {
            $chapters[] = ['title' => 'Mở đầu', 'content' => trim(array_shift($parts))];
        } elseif (!preg_match($pattern, $parts[0])) {
            array_shift($parts);
        }

        for ($i = 0; $i < count($parts) - 1; $i += 2) {
            $heading = trim($parts[$i]);
            $body = trim($parts[$i + 1] ?? '');
            if ($body === '') {
                continue;
            }
            $chapters[] = ['title' => $heading, 'content' => $body];
        }

        return $chapters;
    }
}
