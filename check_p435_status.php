<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$p = App\Models\DubSyncProject::find(435);
echo "Project 435:\n";
echo "  status: " . $p->status . "\n";
echo "  video_id: " . $p->video_id . "\n";
echo "  youtube_url: " . $p->youtube_url . "\n";
echo "  error_message: " . $p->error_message . "\n";
echo "  updated_at: " . $p->updated_at . "\n";

// Check all columns
foreach ($p->getAttributes() as $k => $v) {
    if (stripos($k, 'download') !== false || stripos($k, 'queue') !== false) {
        echo "  $k: $v\n";
    }
}

// Check pending jobs
$jobs = DB::table('jobs')->get();
echo "\nPending jobs in queue: " . $jobs->count() . "\n";
foreach ($jobs as $job) {
    $payload = json_decode($job->payload, true);
    $class = $payload['displayName'] ?? 'unknown';
    echo "  Job #{$job->id}: $class (queue: {$job->queue}, attempts: {$job->attempts})\n";
}

// Check failed jobs
$failed = DB::table('failed_jobs')->orderByDesc('id')->limit(5)->get();
echo "\nFailed jobs: " . $failed->count() . "\n";
foreach ($failed as $fj) {
    $payload = json_decode($fj->payload, true);
    $class = $payload['displayName'] ?? 'unknown';
    echo "  Failed #{$fj->id}: $class - " . substr($fj->exception, 0, 200) . "\n";
}
