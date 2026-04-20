<?php
require __DIR__ . '/vendor/autoload.php';
(require_once __DIR__ . '/bootstrap/app.php')->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$project = DB::table('dubsync_projects')->where('id', 435)->first(['id', 'video_id', 'youtube_url', 'youtube_title', 'status']);
if ($project) {
    echo "Project #435:" . PHP_EOL;
    echo "  video_id: {$project->video_id}" . PHP_EOL;
    echo "  youtube_url: {$project->youtube_url}" . PHP_EOL;
    echo "  youtube_title: {$project->youtube_title}" . PHP_EOL;
    echo "  status: {$project->status}" . PHP_EOL;
} else {
    echo "Project 435 not found" . PHP_EOL;
}
