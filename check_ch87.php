<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$e = App\Models\AudioBookChapterChunk::where('audiobook_chapter_id', 2038)->where('status', 'error')->first();
echo "Chunk #{$e->chunk_number} | Length: " . mb_strlen($e->text_content, 'UTF-8') . " | Error: {$e->error_message}\n";
