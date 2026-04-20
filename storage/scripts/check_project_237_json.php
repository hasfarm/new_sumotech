<?php

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$project = App\Models\DubSyncProject::find(237);
if (!$project) {
    echo "PROJECT_NOT_FOUND\n";
    exit(1);
}

$segmentsJson = json_encode($project->segments);
$segmentsErr = json_last_error_msg();
$translatedJson = json_encode($project->translated_segments);
$translatedErr = json_last_error_msg();
$origJson = json_encode($project->original_transcript);
$origErr = json_last_error_msg();

echo "status=" . ($project->status ?? '') . PHP_EOL;
echo "segments_count=" . (is_array($project->segments) ? count($project->segments) : -1) . PHP_EOL;
echo "translated_count=" . (is_array($project->translated_segments) ? count($project->translated_segments) : -1) . PHP_EOL;
echo "orig_type=" . gettype($project->original_transcript) . PHP_EOL;
echo "seg_json_ok=" . ($segmentsJson !== false ? '1' : '0') . PHP_EOL;
echo "seg_json_err=" . $segmentsErr . PHP_EOL;
echo "tr_json_ok=" . ($translatedJson !== false ? '1' : '0') . PHP_EOL;
echo "tr_json_err=" . $translatedErr . PHP_EOL;
echo "orig_json_ok=" . ($origJson !== false ? '1' : '0') . PHP_EOL;
echo "orig_json_err=" . $origErr . PHP_EOL;
