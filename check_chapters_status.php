<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\AudioBookChapter;

// Check chapter status for book 49
$chapters = AudioBookChapter::where('audio_book_id', 49)
    ->orderBy('chapter_number')
    ->get(['id', 'chapter_number', 'title', 'status', 'audio_file', 'total_chunks']);

$noAudio = 0;
foreach ($chapters as $ch) {
    $completed = $ch->chunks()->where('status', 'completed')->count();
    $pending = $ch->chunks()->where('status', 'pending')->count();
    $error = $ch->chunks()->where('status', 'error')->count();
    $total = $ch->chunks()->count();
    
    $hasAudio = $ch->audio_file ? 'YES' : 'NO';
    
    if ($completed < $total || !$ch->audio_file) {
        $noAudio++;
        echo "Ch#{$ch->chapter_number} (ID:{$ch->id}) [{$ch->status}]: {$completed}/{$total} completed, {$pending} pending, {$error} error | audio: {$hasAudio}\n";
    }
}
echo "\nTotal chapters missing audio: {$noAudio} / {$chapters->count()}\n";
