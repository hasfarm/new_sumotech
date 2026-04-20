<?php
require __DIR__ . '/vendor/autoload.php';
(require_once __DIR__ . '/bootstrap/app.php')->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$f = DB::table('failed_jobs')->where('uuid', 'c79639dc-37c6-4f8d-a876-96902a1166d6')->first();
if ($f) {
    echo "Failed at: {$f->failed_at}" . PHP_EOL;
    echo "Exception:" . PHP_EOL;
    echo substr($f->exception, 0, 3000) . PHP_EOL;
} else {
    echo "Job not found" . PHP_EOL;
}
