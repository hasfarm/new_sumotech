<?php
require __DIR__ . '/vendor/autoload.php';
(require_once __DIR__ . '/bootstrap/app.php')->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$seg = DB::table('audiobook_video_segments')->where('id', 78)->first();
echo "Segment #78 columns:" . PHP_EOL;
foreach ((array)$seg as $k => $v) {
    $val = is_string($v) ? mb_substr($v, 0, 300) : $v;
    echo "  {$k}: {$val}" . PHP_EOL;
}
