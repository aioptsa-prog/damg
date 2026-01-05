<?php
/**
 * Direct API Test - Simulating browser request
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/db.php';
$pdo = db();

echo "=== Direct API Test ===\n\n";

// Get user token
$user = $pdo->query("SELECT * FROM public_users LIMIT 1")->fetch();
echo "User: {$user['name']}\n";

// Create token
$token = bin2hex(random_bytes(32));
$token_hash = hash('sha256', $token);
$expires_at = date('Y-m-d H:i:s', time() + 86400);
$pdo->prepare("INSERT INTO public_sessions (user_id, token_hash, expires_at, device_info) VALUES (?, ?, ?, 'Test')")->execute([$user['id'], $token_hash, $expires_at]);
echo "Token: $token\n\n";

// Simulate the API request
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
$_SERVER['REQUEST_METHOD'] = 'GET';

echo "Simulating API request...\n";

// Include the API
ob_start();
try {
    // Set up headers (but capture them)
    require_once __DIR__ . '/lib/public_auth.php';

    $authUser = current_public_user();
    if ($authUser) {
        echo "✅ Auth successful! User ID: {$authUser['id']}\n";
    } else {
        echo "❌ Auth failed!\n";
    }

    // Now test direct query
    $templates = $pdo->query("SELECT * FROM whatsapp_templates")->fetchAll(PDO::FETCH_ASSOC);
    echo "\nTemplates in DB: " . count($templates) . "\n";
    foreach ($templates as $t) {
        echo "  - {$t['name']}\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
$output = ob_get_clean();
echo $output;

echo "\n=== Done ===\n";
