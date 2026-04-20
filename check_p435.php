<?php
require __DIR__ . '/vendor/autoload.php';
(require_once __DIR__ . '/bootstrap/app.php')->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$p = App\Models\DubSyncProject::find(435);
if ($p) {
    echo "video_id: {$p->video_id}" . PHP_EOL;
    echo "url: {$p->youtube_url}" . PHP_EOL;
    echo "status: {$p->status}" . PHP_EOL;
} else {
    echo "Not found" . PHP_EOL;
}
