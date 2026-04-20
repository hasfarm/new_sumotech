<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$chunk = App\Models\AudioBookChapterChunk::where('audiobook_chapter_id', 2038)
    ->where('chunk_number', 14)
    ->first();

echo "=== Chapter #87, Chunk #14 ===\n";
echo "Length: " . mb_strlen($chunk->text_content, 'UTF-8') . " chars\n";
echo "Status: {$chunk->status}\n";
echo "Error: {$chunk->error_message}\n";
echo "\n--- CONTENT ---\n";
echo $chunk->text_content;
echo "\n--- END ---\n";
