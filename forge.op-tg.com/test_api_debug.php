<?php
/**
 * Debug WhatsApp API
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Get a valid token
require_once __DIR__ . '/config/db.php';
$pdo = db();

// Get a session token
$session = $pdo->query("SELECT * FROM public_sessions ORDER BY id DESC LIMIT 1")->fetch();
if (!$session) {
    echo "No sessions found!\n";
    exit;
}

// Get the original token from the hash - we need to use the stored token
// Actually, let's create a new token for testing
$user = $pdo->query("SELECT * FROM public_users WHERE id = {$session['user_id']}")->fetch();
if (!$user) {
    echo "User not found!\n";
    exit;
}

echo "User: {$user['name']}\n";

// Create a test token
$token = bin2hex(random_bytes(32));
$token_hash = hash('sha256', $token);
$expires_at = date('Y-m-d H:i:s', time() + 86400);

$stmt = $pdo->prepare("INSERT INTO public_sessions (user_id, token_hash, expires_at, device_info) VALUES (?, ?, ?, 'CLI Test')");
$stmt->execute([$user['id'], $token_hash, $expires_at]);

echo "Token created: $token\n\n";

// Now test the API with curl
echo "Testing templates API...\n";
$ch = curl_init('http://localhost:8080/v1/api/whatsapp/templates.php');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token
    ]
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $httpCode\n";
echo "Response: $response\n";
