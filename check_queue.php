<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$jobs = DB::table('jobs')->get();
echo "Total jobs in queue: " . $jobs->count() . PHP_EOL;
foreach ($jobs as $j) {
    $payload = json_decode($j->payload);
    echo "ID: {$j->id} | Queue: {$j->queue} | Attempts: {$j->attempts} | Class: " . ($payload->displayName ?? 'unknown') . PHP_EOL;
}

$failed = DB::table('failed_jobs')->count();
echo PHP_EOL . "Failed jobs: {$failed}" . PHP_EOL;

// Check batch video lock
$lock = Cache::get('batch_video_lock_49');
echo "Batch video lock for book 49: " . ($lock ? $lock : 'NONE') . PHP_EOL;

// Check video segment status for book 49
$segments = DB::table('audiobook_video_segments')
    ->where('audio_book_id', 49)
    ->selectRaw("status, count(*) as cnt")
    ->groupBy('status')
    ->get();
echo PHP_EOL . "Video segments for book 49:" . PHP_EOL;
foreach ($segments as $s) {
    echo "  {$s->status}: {$s->cnt}" . PHP_EOL;
}
