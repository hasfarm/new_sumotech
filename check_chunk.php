<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\AudioBookChapterChunk;

$chunks = AudioBookChapterChunk::whereHas('chapter', function($q) {
    $q->where('chapter_number', 96);
})->where('chunk_number', 24)->get();

if ($chunks->isEmpty()) {
    echo "No chunk found for chapter_number=96, chunk_number=24\n";
    // Try finding all error chunks recently
    echo "\nRecent error chunks:\n";
    $errors = AudioBookChapterChunk::where('status', 'error')
        ->orderByDesc('updated_at')
        ->take(10)
        ->get(['id', 'audiobook_chapter_id', 'chunk_number', 'status', 'updated_at']);
    foreach ($errors as $e) {
        $ch = $e->chapter;
        $len = mb_strlen($e->text_content ?? '', 'UTF-8');
        echo "Chunk ID: {$e->id} | Chapter: {$e->audiobook_chapter_id} (ch#{$ch->chapter_number}) | Chunk #{$e->chunk_number} | Length: {$len} | Status: {$e->status} | Updated: {$e->updated_at}\n";
    }
} else {
    foreach ($chunks as $c) {
        $ch = $c->chapter;
        $len = mb_strlen($c->text_content ?? '', 'UTF-8');
        echo "Chunk ID: {$c->id} | Chapter ID: {$c->audiobook_chapter_id} (ch#{$ch->chapter_number}, book#{$ch->audiobook_id}) | Length: {$len} chars | Status: {$c->status}\n";
    }
}
