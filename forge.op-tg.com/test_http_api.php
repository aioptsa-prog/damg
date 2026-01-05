<?php
/**
 * HTTP API Test - Test WhatsApp templates API
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/db.php';

echo "=== HTTP API Test ===\n\n";

$pdo = db();

// Get or create a valid admin session token
$user = $pdo->query("SELECT * FROM users WHERE active = 1 LIMIT 1")->fetch();
echo "Admin user: {$user['name']} (ID: {$user['id']})\n";

// Create fresh token
$token = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $token);
$expires = date('Y-m-d H:i:s', time() + 86400);

$pdo->prepare("INSERT INTO sessions (user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, datetime('now'))")
    ->execute([$user['id'], $tokenHash, $expires]);

echo "Created token: $token\n\n";

// Test API via HTTP
echo "Testing templates API via HTTP...\n";

$url = 'http://localhost:8080/v1/api/whatsapp/templates.php';
$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => [
            "Authorization: Bearer $token",
            "Content-Type: application/json"
        ],
        'ignore_errors' => true
    ]
]);

$response = file_get_contents($url, false, $context);

// Get HTTP response code
$http_code = 'Unknown';
if (isset($http_response_header)) {
    foreach ($http_response_header as $header) {
        if (preg_match('/^HTTP\/\d+\.\d+\s+(\d+)/', $header, $matches)) {
            $http_code = $matches[1];
        }
    }
}

echo "HTTP Status: $http_code\n";
echo "Response: $response\n\n";

// Parse JSON response
$data = json_decode($response, true);
if ($data) {
    echo "Parsed response:\n";
    echo "  ok: " . ($data['ok'] ? 'true' : 'false') . "\n";
    if (isset($data['templates'])) {
        echo "  templates count: " . count($data['templates']) . "\n";
        foreach ($data['templates'] as $t) {
            echo "    - {$t['name']}\n";
        }
    }
    if (isset($data['error'])) {
        echo "  error: {$data['error']}\n";
    }
} else {
    echo "Failed to parse JSON. Raw HTML?\n";
    echo substr($response, 0, 500) . "\n";
}

echo "\n=== Done ===\n";
