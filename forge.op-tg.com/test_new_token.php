<?php
// Test the NEW token
require_once __DIR__ . '/v1/api/bootstrap_api.php';
require_once __DIR__ . '/lib/auth.php';

// Simulate authentication with the new token
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer 940a66c458a85239bbfc34584d88114e';

try {
    echo "Testing with NEW token: 940a66c458a85239bbfc34584d88114e\n\n";

    $user = current_user();
    if ($user) {
        echo "✓ Authentication successful!\n";
        echo "  User ID: {$user['id']}\n";
        echo "  Name: {$user['name']}\n";
        echo "  Role: {$user['role']}\n\n";

        // Now test the leads query
        echo "Testing leads query...\n";
        $pdo = db();
        $stmt = $pdo->query("SELECT COUNT(*) FROM leads");
        $count = $stmt->fetchColumn();
        echo "✓ Found $count leads in database\n";

    } else {
        echo "\✗ Authentication failed with new token\n";
    }

} catch (Throwable $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}
