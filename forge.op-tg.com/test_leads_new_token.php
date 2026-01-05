<?php
// Test Leads API with the CORRECT new token
$token = '207482aa0379e9f162437db2de6b71363d5b1cf2ad1863e5cfac4d21256946c2';

// Simulate the API request
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['page'] = 1;
$_GET['limit'] = 10;

echo "Testing Leads API with NEW token\n";
echo "Token: $token\n";
echo "==========================================\n\n";

// Enable error display
ini_set('display_errors', 1);
error_reporting(E_ALL);

try {
    // Capture output
    ob_start();

    // Include the leads API endpoint
    require 'v1/api/leads/index.php';

    $output = ob_get_clean();

    echo "API Response:\n";
    echo $output . "\n";

} catch (Throwable $e) {
    ob_end_clean();
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
}
