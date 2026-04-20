<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\AudioBookChapterChunk;

// Error chunk detail
$errors = AudioBookChapterChunk::where('status', 'error')
    ->whereHas('chapter', fn($q) => $q->where('audio_book_id', 49))
    ->with('chapter')
    ->get();

echo "=== ERROR CHUNKS (Book 49) ===\n";
foreach ($errors as $e) {
    echo "Ch#{$e->chapter->chapter_number} Chunk#{$e->chunk_number}: " 
        . mb_strlen($e->text_content, 'UTF-8') . " chars | Error: {$e->error_message}\n";
}

// Pending chunks detail
$pending = AudioBookChapterChunk::where('status', 'pending')
    ->whereNull('audio_file')
    ->whereHas('chapter', fn($q) => $q->where('audio_book_id', 49))
    ->with('chapter')
    ->orderBy('audiobook_chapter_id')
    ->orderBy('chunk_number')
    ->get();

echo "\n=== PENDING CHUNKS (Book 49) ===\n";
$byChapter = [];
foreach ($pending as $p) {
    $key = "Ch#{$p->chapter->chapter_number} ({$p->chapter->title})";
    $byChapter[$key][] = $p->chunk_number;
}
foreach ($byChapter as $ch => $chunks) {
    echo "{$ch}: chunks " . implode(', ', $chunks) . " (" . count($chunks) . " total)\n";
}
