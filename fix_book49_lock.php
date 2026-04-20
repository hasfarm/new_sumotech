<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Cache;

// Clear batch lock
Cache::forget('tts_batch_lock_49');
echo "Batch lock cleared.\n";

// Reset the remaining error chunk
$reset = \App\Models\AudioBookChapterChunk::where('status', 'error')
    ->whereHas('chapter', fn($q) => $q->where('audio_book_id', 49))
    ->update(['status' => 'pending', 'error_message' => null]);
echo "Reset {$reset} error chunks.\n";
