<?php

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$projectId = isset($argv[1]) ? (int) $argv[1] : 237;
$project = App\Models\DubSyncProject::find($projectId);
if (!$project) {
    echo "PROJECT_NOT_FOUND\n";
    exit(1);
}

$fields = [
    'speakers_config' => $project->speakers_config,
    'style_instruction' => $project->style_instruction,
    'segments' => $project->segments,
    'translated_segments' => $project->translated_segments,
    'original_transcript' => $project->original_transcript,
];

echo "project_id={$projectId}\n";
foreach ($fields as $name => $value) {
    $encoded = json_encode($value);
    $ok = $encoded !== false ? 'ok' : 'fail';
    $err = json_last_error_msg();
    echo "{$name}: {$ok} ({$err})\n";
}
