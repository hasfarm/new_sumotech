<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\AudioBookChapterChunk;
use App\Models\AudioBookChapter;

// Count error chunks grouped by book
$errorChunks = AudioBookChapterChunk::where('status', 'error')
    ->with('chapter.audioBook')
    ->get();

$byBook = [];
foreach ($errorChunks as $chunk) {
    $book = $chunk->chapter->audioBook;
    $bookKey = "Book #{$book->id}: {$book->title}";
    if (!isset($byBook[$bookKey])) {
        $byBook[$bookKey] = ['error' => 0, 'chapters' => []];
    }
    $byBook[$bookKey]['error']++;
    $chKey = "Ch#{$chunk->chapter->chapter_number}";
    $byBook[$bookKey]['chapters'][$chKey] = ($byBook[$bookKey]['chapters'][$chKey] ?? 0) + 1;
}

echo "=== ERROR CHUNKS ===\n";
$totalError = 0;
foreach ($byBook as $book => $info) {
    echo "{$book}: {$info['error']} error chunks\n";
    foreach ($info['chapters'] as $ch => $count) {
        echo "  {$ch}: {$count} chunks\n";
    }
    $totalError += $info['error'];
}
echo "Total error: {$totalError}\n\n";

// Count pending chunks (never processed)
$pendingChunks = AudioBookChapterChunk::where('status', 'pending')
    ->whereNull('audio_file')
    ->with('chapter.audioBook')
    ->get();

$byBookPending = [];
foreach ($pendingChunks as $chunk) {
    $book = $chunk->chapter->audioBook;
    $bookKey = "Book #{$book->id}: {$book->title}";
    if (!isset($byBookPending[$bookKey])) {
        $byBookPending[$bookKey] = ['pending' => 0, 'chapters' => []];
    }
    $byBookPending[$bookKey]['pending']++;
    $chKey = "Ch#{$chunk->chapter->chapter_number}";
    $byBookPending[$bookKey]['chapters'][$chKey] = ($byBookPending[$bookKey]['chapters'][$chKey] ?? 0) + 1;
}

echo "=== PENDING CHUNKS (no audio) ===\n";
$totalPending = 0;
foreach ($byBookPending as $book => $info) {
    echo "{$book}: {$info['pending']} pending chunks\n";
    $totalPending += $info['pending'];
}
echo "Total pending: {$totalPending}\n";
