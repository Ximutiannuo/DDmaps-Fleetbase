<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
echo "Starting bootstrap...\n";
require __DIR__ . '/vendor/autoload.php';
echo "Autoload done.\n";
try {
    $app = require_once __DIR__ . '/bootstrap/app.php';
    echo "App created: " . get_class($app) . "\n";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    echo "Kernel bootstrapped.\n";
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}
