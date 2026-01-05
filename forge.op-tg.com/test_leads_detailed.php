<?php
// Manual test with DETAILED error reporting
$token = '207482aa0379e9f162437db2de6b71363d5b1cf2ad1863e5cfac4d21256946c2';

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['page'] = 1;
$_GET['limit'] = 10;

echo "Testing Leads API\n";
echo "=================\n\n";

ini_set('display_errors', 1);
error_reporting(E_ALL);

try {
    require_once 'v1/api/bootstrap_api.php';
    require_once 'lib/auth.php';

    echo "1. Authentication check...\n";
    $user = current_user();
    if (!$user) {
        die("ERROR: Authentication failed\n");
    }
    echo "  ✓ User authenticated: {$user['name']} (ID: {$user['id']}, Role: {$user['role']})\n\n";

    echo "2. Database connection...\n";
    $pdo = db();
    echo "  ✓ Connected\n\n";

    echo "3. Count leads...\n";
    $stmt = $pdo->query("SELECT COUNT(*) FROM leads SELECT id, name FROM leads LIMIT 5");
    $count = $stmt->fetchColumn();
    echo "  ✓ Found $count leads\n\n";

    echo "4. Fetch sample...\n";
    $stmt2 = $pdo->query("SELECT id, name FROM leads LIMIT 5");
    while ($row = $stmt2->fetch()) {
        echo "    - Lead {$row['id']}: {$row['name']}\n";
    }

    echo "\n✓ All checks passed!\n";

} catch (Throwable $e) {
    echo "\n✗ ERROR: " . $e->getMessage() . "\n";
    echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "  Trace:\n" . $e->getTraceAsString() . "\n";
}
