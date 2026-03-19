<?php

/**
 * Test script for short video generation
 * Usage: php test_short_generation.php [audiobook_id] [count]
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\AudioBook;
use App\Services\TTSService;
use App\Services\GeminiImageService;
use App\Services\KlingAIService;
use App\Services\DIDLipsyncService;
use App\Services\LipsyncSegmentManager;
use App\Services\VideoCompositionService;
use App\Services\DescriptionVideoService;
use App\Http\Controllers\AudioBookController;
use Illuminate\Http\Request;

// Get parameters
$audioBookId = $argv[1] ?? 47;
$count = $argv[2] ?? 2;

echo "Testing short video generation...\n";
echo "AudioBook ID: $audioBookId\n";
echo "Count: $count\n";
echo str_repeat('-', 50) . "\n";

try {
    // Find audiobook
    $audioBook = AudioBook::findOrFail($audioBookId);
    echo "✅ Found audiobook: {$audioBook->title}\n";

    // Create controller instance
    $ttsService = app(TTSService::class);
    $imageService = app(GeminiImageService::class);
    $klingService = app(KlingAIService::class);
    $lipsyncService = app(DIDLipsyncService::class);
    $segmentManager = app(LipsyncSegmentManager::class);
    $compositionService = app(VideoCompositionService::class);
    $descVideoService = app(DescriptionVideoService::class);

    $controller = new AudioBookController(
        $ttsService,
        $imageService,
        $klingService,
        $lipsyncService,
        $segmentManager,
        $compositionService,
        $descVideoService
    );

    // Create request with proper JSON body
    $request = Request::create(
        '/audiobooks/' . $audioBookId . '/short-videos/generate-plans',
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        json_encode(['count' => (int)$count])
    );
    $request->headers->set('Content-Type', 'application/json');
    $request->headers->set('Accept', 'application/json');

    echo "✅ Controller instantiated\n";
    echo "Calling generateShortVideoPlans...\n";

    // Call the method
    $response = $controller->generateShortVideoPlans($request, $audioBook);

    $statusCode = $response->getStatusCode();
    $content = $response->getContent();

    echo "\n" . str_repeat('=', 50) . "\n";
    echo "HTTP Status: $statusCode\n";
    echo str_repeat('=', 50) . "\n";

    if ($statusCode === 200) {
        echo "✅ SUCCESS!\n";
        $data = json_decode($content, true);
        echo "Generated plans: " . ($data['count'] ?? 0) . "\n";
        echo "\nResponse:\n";
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "❌ ERROR!\n";
        echo "Response:\n";
        echo $content . "\n";
    }
} catch (\Throwable $e) {
    echo "\n❌ EXCEPTION CAUGHT:\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "\nStack trace:\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\nTest completed.\n";
