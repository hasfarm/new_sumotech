<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Delete stale zombie job #4737 (reserved 24+ hours ago, clearly dead)
$deleted = DB::table('jobs')->where('id', 4737)->delete();
echo "Deleted stale job #4737: " . ($deleted ? 'YES' : 'NO') . "\n";

// Show remaining jobs
$jobs = DB::table('jobs')->get();
echo "\nRemaining jobs: " . $jobs->count() . "\n";
foreach ($jobs as $j) {
    $p = json_decode($j->payload, true);
    echo "  Job #{$j->id}: " . ($p['displayName'] ?? '?') . "\n";
}
