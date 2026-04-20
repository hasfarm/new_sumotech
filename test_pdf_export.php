<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Find an audiobook with chapters
$book = App\Models\AudioBook::has('chapters')->first();
if (!$book) {
    echo "No audiobook with chapters found\n";
    exit(1);
}

echo "Testing export for book #{$book->id}: {$book->title}\n";
echo "Chapters: " . $book->chapters()->count() . "\n";

try {
    // Simulate what the export method does
    $book->load(['chapters' => fn($q) => $q->orderBy('chapter_number')]);
    
    $title = e($book->title ?: 'Audiobook');
    $author = $book->author ? e($book->author) : '';

    $html = '<style>'
        . 'body{font-family:DejaVu Sans,sans-serif;font-size:12pt;line-height:1.6;color:#222}'
        . 'h1{text-align:center;font-size:22pt;margin-bottom:6px}'
        . '</style>';
    $html .= "<h1>{$title}</h1>";
    
    // Just test with first chapter
    $chapter = $book->chapters->first();
    if ($chapter) {
        $chapterTitle = e($chapter->title ?: ('Chương ' . $chapter->chapter_number));
        $html .= "<h2>{$chapterTitle}</h2>";
        $content = trim((string) $chapter->content);
        $html .= '<p>' . e(mb_substr($content, 0, 200)) . '...</p>';
    }

    echo "HTML length: " . strlen($html) . " bytes\n";
    
    $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->setPaper('a4', 'portrait');
    
    $tempFile = storage_path('app/temp/test_export.pdf');
    if (!is_dir(dirname($tempFile))) {
        mkdir(dirname($tempFile), 0755, true);
    }
    $pdf->save($tempFile);
    
    echo "PDF saved: " . $tempFile . "\n";
    echo "File size: " . filesize($tempFile) . " bytes\n";
    echo "SUCCESS!\n";
    
    @unlink($tempFile);
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}
