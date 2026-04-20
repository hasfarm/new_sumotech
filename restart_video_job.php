<?php
require __DIR__ . '/vendor/autoload.php';
(require_once __DIR__ . '/bootstrap/app.php')->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// 1. Clear batch video lock
Cache::forget('batch_video_lock_49');
echo "Lock cleared" . PHP_EOL;

// 2. Reset error/processing segments to pending
$reset = DB::table('audiobook_video_segments')
    ->where('audio_book_id', 49)
    ->whereIn('status', ['error', 'processing'])
    ->update(['status' => 'pending', 'error_message' => null]);
echo "Reset {$reset} segments to pending" . PHP_EOL;

// 3. Check current segment status
$segments = DB::table('audiobook_video_segments')
    ->where('audio_book_id', 49)
    ->orderBy('sort_order')
    ->get(['id', 'sort_order', 'name', 'status']);
foreach ($segments as $s) {
    echo "  #{$s->id} {$s->name}: {$s->status}" . PHP_EOL;
}

// 4. Clear stale jobs for this book and re-queue
$deleted = DB::table('jobs')->where('payload', 'like', '%GenerateBatchVideoJob%')->delete();
echo PHP_EOL . "Deleted {$deleted} old video jobs" . PHP_EOL;

// 5. Dispatch new job for all pending segments
$pendingIds = DB::table('audiobook_video_segments')
    ->where('audio_book_id', 49)
    ->where('status', 'pending')
    ->pluck('id')
    ->toArray();

App\Jobs\GenerateBatchVideoJob::dispatch(49, $pendingIds);
echo "Dispatched new GenerateBatchVideoJob for " . count($pendingIds) . " segments" . PHP_EOL;
