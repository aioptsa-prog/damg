<?php
/**
 * Final Debug - Test API flow
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "=== Final API Debug ===\n\n";

// Load config
require_once __DIR__ . '/config/db.php';
$pdo = db();

// Create a fresh token
$user_id = 1;
$token = bin2hex(random_bytes(32));
$token_hash = hash('sha256', $token);
$expires = date('Y-m-d H:i:s', time() + 86400);

$stmt = $pdo->prepare("INSERT INTO public_sessions (user_id, token_hash, expires_at, device_info) VALUES (?, ?, ?, 'Debug')");
$stmt->execute([$user_id, $token_hash, $expires]);
echo "Created token for user $user_id\n";
echo "Token: $token\n\n";

// Now test API by simulating the request
echo "=== Testing templates.php directly ===\n";

// Set up environment as if browser called it
$_SERVER['HTTP_AUTHORIZATION'] = "Bearer $token";
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_ORIGIN'] = 'http://localhost:5173';

// Capture output
ob_start();
try {
    include __DIR__ . '/v1/api/whatsapp/templates.php';
} catch (Exception $e) {
    echo "Exception: " . $e->getMessage() . "\n";
}
$output = ob_get_clean();

echo "Output: $output\n";

// Now check if it was valid JSON
$json = json_decode($output, true);
if ($json) {
    echo "\n✅ Valid JSON response!\n";
    echo "ok: " . ($json['ok'] ? 'true' : 'false') . "\n";
    if (isset($json['templates'])) {
        echo "Templates count: " . count($json['templates']) . "\n";
        foreach ($json['templates'] as $t) {
            echo "  - {$t['name']}\n";
        }
    }
    if (isset($json['error'])) {
        echo "Error: {$json['error']}\n";
    }
} else {
    echo "\n❌ Invalid JSON response\n";
    echo "Raw output: $output\n";
}

echo "\n=== Done ===\n";
