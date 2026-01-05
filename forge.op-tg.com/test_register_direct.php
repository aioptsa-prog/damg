<?php
// Test registration API directly with detailed error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = json_decode('{
    "email": "direct_test@example.com",
    "password": "TestPassword123",
    "name": "Direct Test User"
}', true);

// Simulate JSON input
$GLOBALS['HTTP_RAW_POST_DATA'] = json_encode($_POST);

// Include the registration file
try {
    ob_start();
    include 'v1/api/public/auth/register.php';
    $output = ob_get_clean();

    echo "=== OUTPUT ===\n";
    echo $output;
    echo "\n=== END OUTPUT ===\n";
} catch (Throwable $e) {
    echo "=== EXCEPTION ===\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}
