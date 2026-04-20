<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\AudioBookChapter;

// Check chapters 34, 40, 47, 60, 87, 96, 100, 105 from book 49
$chapterNums = [34, 40, 47, 60, 87, 96, 100, 105];
foreach ($chapterNums as $num) {
    $ch = AudioBookChapter::where('audio_book_id', 49)->where('chapter_number', $num)->first();
    if (!$ch) continue;
    
    $chunks = $ch->chunks()->orderBy('chunk_number')->get();
    $existingText = $chunks->pluck('text_content')->implode("\n\n");
    $currentContent = trim($ch->content);
    $existingTextNormalized = trim($existingText);
    
    $match = $currentContent === $existingTextNormalized;
    echo "Ch#{$num}: chunks=" . $chunks->count() 
        . " | content_len=" . mb_strlen($currentContent, 'UTF-8')
        . " | chunks_text_len=" . mb_strlen($existingTextNormalized, 'UTF-8')
        . " | match=" . ($match ? 'YES' : 'NO')
        . " | completed=" . $chunks->where('status', 'completed')->count()
        . " | pending=" . $chunks->where('status', 'pending')->count()
        . " | with_audio=" . $chunks->filter(fn($c) => $c->audio_file)->count()
        . "\n";
}
