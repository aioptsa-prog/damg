<?php
/**
 * Debug WhatsApp API - using file_get_contents
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/db.php';
$pdo = db();

// Get a user
$user = $pdo->query("SELECT * FROM public_users LIMIT 1")->fetch();
echo "User: {$user['name']} (ID: {$user['id']})\n\n";

// Create a test token
$token = bin2hex(random_bytes(32));
$token_hash = hash('sha256', $token);
$expires_at = date('Y-m-d H:i:s', time() + 86400);

$stmt = $pdo->prepare("INSERT INTO public_sessions (user_id, token_hash, expires_at, device_info) VALUES (?, ?, ?, 'CLI Test')");
$stmt->execute([$user['id'], $token_hash, $expires_at]);

echo "New Token created: $token\n\n";

// Test using file_get_contents
$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "Content-Type: application/json\r\nAuthorization: Bearer $token"
    ]
]);

echo "Testing templates API...\n";
$response = @file_get_contents('http://localhost:8080/v1/api/whatsapp/templates.php', false, $context);

if ($response === false) {
    $error = error_get_last();
    echo "Error: " . ($error['message'] ?? 'Unknown error') . "\n";

    // Check HTTP response headers
    if (isset($http_response_header)) {
        echo "HTTP Headers:\n";
        foreach ($http_response_header as $h) {
            echo "  $h\n";
        }
    }
} else {
    echo "Response: $response\n";
}
