<?php
require __DIR__ . '/vendor/autoload.php';
(require_once __DIR__ . '/bootstrap/app.php')->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Symfony\Component\Process\Process;

$pythonCmd = env('PYTHON_PATH', 'python');
$script = storage_path('scripts/get_youtube_metadata.py');
$videoId = 'lUHcrGbKajc';

$process = new Process([$pythonCmd, $script, $videoId]);
$process->setTimeout(30);
$process->run();

echo "Exit code: " . $process->getExitCode() . PHP_EOL;
echo "isSuccessful: " . ($process->isSuccessful() ? 'true' : 'false') . PHP_EOL;
echo PHP_EOL . "=== STDOUT ===" . PHP_EOL;
echo $process->getOutput() . PHP_EOL;
echo PHP_EOL . "=== STDERR ===" . PHP_EOL;
echo $process->getErrorOutput() . PHP_EOL;

$trimmed = trim($process->getOutput());
$json = json_decode($trimmed, true);
echo PHP_EOL . "=== JSON VALID: " . ($json !== null ? 'YES' : 'NO') . " ===" . PHP_EOL;
