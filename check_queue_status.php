<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$jobs = DB::table('jobs')->get();
echo "Pending jobs: " . $jobs->count() . "\n\n";
foreach ($jobs as $j) {
    $p = json_decode($j->payload, true);
    $class = $p['displayName'] ?? '?';
    echo "Job #{$j->id}: {$class}\n";
    echo "  queue: {$j->queue}, attempts: {$j->attempts}\n";
    echo "  reserved_at: " . ($j->reserved_at ? date('Y-m-d H:i:s', $j->reserved_at) : 'NULL') . "\n";
    echo "  created_at: " . date('Y-m-d H:i:s', $j->created_at) . "\n";
    
    // Check if it's the download job and extract project ID
    $cmd = $p['data']['command'] ?? '';
    if (strpos($class, 'Download') !== false) {
        $obj = unserialize($cmd);
        echo "  projectId: " . ($obj->projectId ?? '?') . "\n";
    }
    echo "\n";
}

// Check download progress cache for project 435
$progress = \Illuminate\Support\Facades\Cache::get('download_video_progress_435');
echo "Download progress cache for project 435: " . json_encode($progress, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
