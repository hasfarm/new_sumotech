<?php
require __DIR__ . '/vendor/autoload.php';
(require_once __DIR__ . '/bootstrap/app.php')->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Check progress
$progress = Cache::get('batch_video_progress_49');
echo "=== PROGRESS ===" . PHP_EOL;
if ($progress) {
    foreach ($progress as $k => $v) {
        echo "  {$k}: {$v}" . PHP_EOL;
    }
} else {
    echo "  No progress data" . PHP_EOL;
}

echo PHP_EOL . "=== LOGS (last 30) ===" . PHP_EOL;
$logs = Cache::get('batch_video_log_49', []);
$tail = array_slice($logs, -30);
foreach ($tail as $line) {
    echo $line . PHP_EOL;
}

echo PHP_EOL . "=== SEGMENTS ===" . PHP_EOL;
$segments = DB::table('audiobook_video_segments')
    ->where('audio_book_id', 49)
    ->orderBy('sort_order')
    ->get(['id', 'sort_order', 'status', 'video_duration', 'error_message']);
foreach ($segments as $s) {
    $status = str_pad($s->status, 12);
    $dur = $s->video_duration ? round($s->video_duration, 1) . 's' : '-';
    $err = $s->error_message ? ' ERR: ' . mb_substr($s->error_message, 0, 80) : '';
    echo "  #{$s->id} sort:{$s->sort_order} [{$status}] dur:{$dur}{$err}" . PHP_EOL;
}
