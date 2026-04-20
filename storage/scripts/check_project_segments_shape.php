<?php

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$projectId = isset($argv[1]) ? (int) $argv[1] : 237;
$project = App\Models\DubSyncProject::find($projectId);
if (!$project) {
    echo "PROJECT_NOT_FOUND\n";
    exit(1);
}

$sets = [
    'segments' => $project->segments,
    'translated_segments' => $project->translated_segments,
];

foreach ($sets as $name => $rows) {
    echo "== {$name} ==\n";
    if (!is_array($rows)) {
        echo "not_array\n";
        continue;
    }

    echo "count=" . count($rows) . "\n";
    foreach ($rows as $i => $row) {
        $type = gettype($row);
        if ($type !== 'array') {
            echo "idx {$i}: type={$type}\n";
            continue;
        }

        $textType = gettype($row['text'] ?? null);
        $startType = gettype($row['start_time'] ?? ($row['start'] ?? null));
        if ($textType !== 'string' && $textType !== 'NULL') {
            echo "idx {$i}: text_type={$textType}\n";
        }
        if (!in_array($startType, ['double', 'integer', 'NULL'], true)) {
            echo "idx {$i}: start_type={$startType}\n";
        }
    }
}
