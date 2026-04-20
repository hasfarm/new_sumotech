<?php
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$key = (string) config('services.gemini.api_key', '');
echo $key !== '' ? "GEMINI_KEY_SET\n" : "GEMINI_KEY_EMPTY\n";
