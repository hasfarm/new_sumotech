<?php
require __DIR__ . '/vendor/autoload.php';
(require_once __DIR__ . '/bootstrap/app.php')->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$jobs = DB::table('jobs')->get();
echo "Remaining jobs: " . $jobs->count() . PHP_EOL;
foreach ($jobs as $j) {
    $payload = json_decode($j->payload);
    $data = unserialize($payload->data->command);
    $bookId = $data->audioBookId ?? ($data->audiobook_id ?? 'unknown');
    echo "ID: {$j->id} | Attempts: {$j->attempts} | Class: {$payload->displayName} | Book: {$bookId}" . PHP_EOL;
}
