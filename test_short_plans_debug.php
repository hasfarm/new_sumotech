<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

// Create a test request
$request = Illuminate\Http\Request::create(
    '/audiobooks/1/short-videos/generate-plans',
    'POST',
    ['count' => 3],
    [],
    [],
    ['CONTENT_TYPE' => 'application/json']
);

// Add CSRF token (bypass for testing)
$request->headers->set('X-CSRF-TOKEN', 'test-token');

try {
    $response = $kernel->handle($request);
    
    echo "Response Status: " . $response->getStatusCode() . "\n";
    echo "Response Headers:\n";
    foreach ($response->headers->all() as $key => $values) {
        echo "  $key: " . implode(', ', $values) . "\n";
    }
    echo "\nResponse Body:\n";
    echo $response->getContent() . "\n";
    
} catch (Throwable $e) {
    echo "EXCEPTION:\n";
    echo "Type: " . get_class($e) . "\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "\nStack trace:\n";
    echo $e->getTraceAsString() . "\n";
}

$kernel->terminate($request, $response ?? null);
