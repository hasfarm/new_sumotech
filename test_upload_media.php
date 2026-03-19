<?php

/**
 * Test upload media functionality
 * Usage: php test_upload_media.php [audiobook_id] [image_path]
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\AudioBook;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use App\Http\Controllers\AudioBookController;
use Illuminate\Support\Facades\Storage;

// Get parameters
$audioBookId = $argv[1] ?? 47;
$imagePath = $argv[2] ?? null;

echo "Testing media upload...\n";
echo "AudioBook ID: $audioBookId\n";
echo str_repeat('-', 50) . "\n";

try {
    // Find audiobook
    $audioBook = AudioBook::findOrFail($audioBookId);
    echo "✅ Found audiobook: {$audioBook->title}\n";

    // Check if we have an actual image to test
    if ($imagePath && file_exists($imagePath)) {
        echo "Using test image: $imagePath\n";
        $testFile = new UploadedFile(
            $imagePath,
            basename($imagePath),
            mime_content_type($imagePath),
            null,
            true
        );
    } else {
        // Create a test image
        echo "Creating test image...\n";
        $tempPath = sys_get_temp_dir() . '/test_upload_' . time() . '.png';
        $image = imagecreatetruecolor(1280, 720);
        $bgColor = imagecolorallocate($image, 100, 100, 200);
        imagefill($image, 0, 0, $bgColor);
        $textColor = imagecolorallocate($image, 255, 255, 255);
        imagestring($image, 5, 10, 10, 'Test Upload Image', $textColor);
        imagepng($image, $tempPath);
        imagedestroy($image);

        $testFile = new UploadedFile(
            $tempPath,
            'test_image.png',
            'image/png',
            null,
            true
        );
    }

    // Create request
    $request = new Request();
    $request->merge(['type' => 'thumbnails']);
    $request->files->set('images', [$testFile]);

    echo "Test file created: " . $testFile->getClientOriginalName() . "\n";
    echo "File size: " . round($testFile->getSize() / 1024, 2) . " KB\n";
    echo "MIME type: " . $testFile->getMimeType() . "\n";

    // Create controller instance
    $controller = app(AudioBookController::class);

    echo "\nCalling uploadMedia method...\n";

    // Call the method
    $response = $controller->uploadMedia($request, $audioBook);

    $statusCode = $response->getStatusCode();
    $content = $response->getContent();

    echo "\n" . str_repeat('=', 50) . "\n";
    echo "HTTP Status: $statusCode\n";
    echo str_repeat('=', 50) . "\n";

    if ($statusCode === 200) {
        echo "✅ SUCCESS!\n";
        $data = json_decode($content, true);
        echo "Uploaded files: " . ($data['uploaded'] ?? 0) . "\n";
        echo "\nResponse:\n";
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "❌ ERROR!\n";
        echo "Response:\n";
        echo $content . "\n";
    }

    // Cleanup
    if (isset($tempPath) && file_exists($tempPath)) {
        unlink($tempPath);
        echo "\nTest file cleaned up.\n";
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
