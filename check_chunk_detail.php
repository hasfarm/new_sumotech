<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\AudioBookChapterChunk;
use App\Models\AudioBookChapter;

$chunk = AudioBookChapterChunk::find(21760);
$chapter = $chunk->chapter;
$totalChunks = $chapter->chunks()->count();
$audioBook = $chapter->audioBook;

echo "Chapter: #{$chapter->chapter_number} - {$chapter->title}\n";
echo "Book: #{$audioBook->id} - {$audioBook->title}\n";
echo "Chunk: #{$chunk->chunk_number} of {$totalChunks}\n";
echo "Raw text length: " . mb_strlen($chunk->text_content, 'UTF-8') . " chars\n";
echo "Is first chunk: " . ($chunk->chunk_number === 1 ? 'YES' : 'NO') . "\n";
echo "Is last chunk: " . ($chunk->chunk_number === $totalChunks ? 'YES' : 'NO') . "\n";

// Simulate intro/outro addition
$text = $chunk->text_content;

if ($chunk->chunk_number === 1) {
    $chapterTitle = $chapter->title;
    $hasChapterPrefix = preg_match('/^(Chương|Chapter|Phần)\s*\d+/iu', $chapterTitle);
    if ($hasChapterPrefix) {
        $intro = "{$chapterTitle}.\n\n";
    } else {
        $intro = "Chương {$chapter->chapter_number}: {$chapterTitle}.\n\n";
    }
    $text = $intro . $text;
    echo "With intro: " . mb_strlen($text, 'UTF-8') . " chars\n";
}

if ($chunk->chunk_number === $totalChunks) {
    $bookType = $audioBook->book_type ?? 'sách';
    $bookTypeLabel = match ($bookType) {
        'truyen' => 'truyện',
        'tieu_thuyet' => 'tiểu thuyết',
        'truyen_ngan' => 'truyện ngắn',
        'sach' => 'sách',
        default => $bookType ?: 'sách',
    };
    $bookCategory = $audioBook->category ?? '';
    $author = $audioBook->author ?? '';
    $channelName = $audioBook->youtubeChannel->title ?? '';
    $bookTitle = $audioBook->title;

    $bookDesc = "bộ {$bookTypeLabel}";
    if ($bookCategory) $bookDesc .= " {$bookCategory}";
    $bookDesc .= " mang tên {$bookTitle}";
    if ($author) $bookDesc .= " của nhà văn {$author}";

    $totalBookChapters = $audioBook->chapters()->count();
    $isLastChapter = ($chapter->chapter_number >= $totalBookChapters);
    $currentChapterLabel = trim((string) $chapter->title) !== ''
        ? trim((string) $chapter->title)
        : "Chương {$chapter->chapter_number}";

    if ($isLastChapter) {
        $outro = "\n\nBạn vừa nghe xong {$currentChapterLabel}, chương cuối cùng của {$bookDesc}.";
        $outro .= " Cảm ơn bạn đã đồng hành cùng chúng tôi trong suốt tác phẩm này.";
        if ($channelName) {
            $outro .= " Nếu bạn thích nội dung này, hãy nhấn like, subscribe và bật chuông thông báo để không bỏ lỡ những tác phẩm hay tiếp theo từ kênh {$channelName}.";
            $outro .= " Sự ủng hộ của bạn là động lực lớn để chúng tôi tiếp tục sáng tạo. Hẹn gặp lại bạn!";
        }
    } else {
        $nextChapterModel = $audioBook->chapters()
            ->where('chapter_number', '>', $chapter->chapter_number)
            ->orderBy('chapter_number')
            ->first(['chapter_number', 'title']);
        $nextChapterLabel = $nextChapterModel && trim((string) ($nextChapterModel->title ?? '')) !== ''
            ? trim((string) $nextChapterModel->title)
            : ($nextChapterModel ? "Chương {$nextChapterModel->chapter_number}" : 'chương tiếp theo');
        
        $outro = "\n\nBạn vừa nghe xong {$currentChapterLabel} của {$bookDesc}.";
        $outro .= " Mời bạn tiếp tục nghe {$nextChapterLabel}.";
        if ($channelName) {
            $outro .= " Đừng quên like, subscribe và bật chuông để ủng hộ kênh {$channelName} nhé!";
        }
    }
    $text = $text . $outro;
    echo "Outro length: " . mb_strlen($outro, 'UTF-8') . " chars\n";
    echo "With outro: " . mb_strlen($text, 'UTF-8') . " chars\n";
}

echo "\nFinal text length: " . mb_strlen($text, 'UTF-8') . " chars\n";
echo "VBEE_RUNTIME_TEXT_LIMIT (1900): " . (mb_strlen($text, 'UTF-8') > 1900 ? 'EXCEEDED -> fallback to raw' : 'OK') . "\n";
echo "VBEE_SAFE_TEXT_LIMIT (1700): " . (mb_strlen($chunk->text_content, 'UTF-8') > 1700 ? 'RAW TEXT EXCEEDED!' : 'Raw text OK') . "\n";

// Check all error chunks
echo "\n=== All error chunks in this chapter ===\n";
$errorChunks = $chapter->chunks()->where('status', 'error')->get();
foreach ($errorChunks as $ec) {
    echo "Chunk #{$ec->chunk_number}: " . mb_strlen($ec->text_content, 'UTF-8') . " chars\n";
}
