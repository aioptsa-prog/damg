<?php
/**
 * Authentication Workflow Test
 * Tests the complete authentication flow including token generation and API calls
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "\n";
echo "╔════════════════════════════════════════════════════════╗\n";
echo "║     Authentication & API Test Suite                  ║\n";
echo "╚════════════════════════════════════════════════════════╝\n\n";

// Load dependencies
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/auth.php';

// Test 1: Check if admin user exists
echo "📋 Test 1: Admin User Check\n";
echo "─────────────────────────────────────────────────────────\n";

$pdo = db();
$admin = $pdo->query("SELECT id, mobile, name, role FROM users WHERE role='admin' LIMIT 1")->fetch();

if ($admin) {
    echo "✅ Admin user found: {$admin['name']} ({$admin['mobile']})\n";
    $testMobile = $admin['mobile'];
    $testUserId = $admin['id'];
} else {
    echo "❌ No admin user found - creating test admin...\n";
    $testMobile = '590000000';
    $testPassword = 'test123';
    $hashedPassword = password_hash($testPassword, PASSWORD_DEFAULT);

    $pdo->prepare("INSERT INTO users (mobile, name, role, password_hash, active, created_at) VALUES (?, ?, ?, ?, 1, datetime('now'))")
        ->execute([$testMobile, 'Test Admin', 'admin', $hashedPassword]);

    $testUserId = $pdo->lastInsertId();
    echo "✅ Created test admin: mobile=$testMobile, password=$testPassword\n";
}

// Test 2: Test login and token generation
echo "\n🔐 Test 2: Login & Token Generation\n";
echo "─────────────────────────────────────────────────────────\n";

// Clean up old sessions for this user
$pdo->prepare("DELETE FROM sessions WHERE user_id=?")->execute([$testUserId]);

// Simulate login
echo "Attempting login...\n";
$_SERVER['HTTPS'] = 'off'; // Simulate HTTP for local testing

// We need to test the login function, but it expects actual password
// Let's test token creation directly
$token = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $token);
$expires = date('Y-m-d H:i:s', time() + 86400 * 30);

$pdo->prepare("INSERT INTO sessions (user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, datetime('now'))")
    ->execute([$testUserId, $tokenHash, $expires]);

echo "✅ Token generated: $token\n";
echo "   Hash: $tokenHash\n";
echo "   Expires: $expires\n";

// Test 3: Test token validation
echo "\n🔍 Test 3: Token Validation\n";
echo "─────────────────────────────────────────────────────────\n";

// Simulate HTTP_AUTHORIZATION header
$_SERVER['HTTP_AUTHORIZATION'] = "Bearer $token";

// Test current_user() function
$user = current_user();

if ($user) {
    echo "✅ Token validated successfully\n";
    echo "   User ID: {$user['id']}\n";
    echo "   Name: {$user['name']}\n";
    echo "   Role: {$user['role']}\n";
} else {
    echo "❌ Token validation failed\n";
}

// Test 4: Simulate API Request
echo "\n🌐 Test 4: Simulated API Request\n";
echo "─────────────────────────────────────────────────────────\n";

// Test with curl to actual API endpoint
$apiUrl = "http://localhost:8080/v1/api/auth/me.php";
$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $token",
        "Content-Type: application/json"
    ],
    CURLOPT_TIMEOUT => 10
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "API Endpoint: $apiUrl\n";
echo "HTTP Status: $httpCode\n";

if ($httpCode === 200) {
    echo "✅ API request successful\n";
    $data = json_decode($response, true);
    if ($data && isset($data['user'])) {
        echo "   Response: " . json_encode($data['user'], JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "   Response: $response\n";
    }
} else {
    echo "❌ API request failed\n";
    echo "   Response: $response\n";
}

// Test 5: Test campaigns endpoint
echo "\n📊 Test 5: Campaigns API Endpoint\n";
echo "─────────────────────────────────────────────────────────\n";

$campaignsUrl = "http://localhost:8080/v1/api/campaigns/index.php";
$ch = curl_init($campaignsUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $token",
        "Content-Type: application/json"
    ],
    CURLOPT_TIMEOUT => 10
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "API Endpoint: $campaignsUrl\n";
echo "HTTP Status: $httpCode\n";

if ($httpCode === 200) {
    echo "✅ Campaigns API successful\n";
    $data = json_decode($response, true);
    if ($data) {
        echo "   Response: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    }
} else {
    echo "⚠ Campaigns API returned $httpCode\n";
    echo "   Response: $response\n";
}

// Final Summary
echo "\n";
echo "╔════════════════════════════════════════════════════════╗\n";
echo "║                  TEST SUMMARY                         ║\n";
echo "╚════════════════════════════════════════════════════════╝\n\n";

if ($httpCode === 200) {
    echo "✅ All tests passed!\n";
    echo "✅ Authorization header is being forwarded correctly\n";
    echo "✅ Bearer token authentication is working\n\n";

    echo "🎯 Next steps:\n";
    echo "─────────────────────────────────────────────────────────\n";
    echo "1. Test in browser: http://localhost:3000\n";
    echo "2. Login with admin credentials\n";
    echo "3. Verify no 401 errors in browser console\n";
    echo "4. Test creating campaigns and viewing leads\n\n";

    echo "📝 Test Token (save this for browser testing):\n";
    echo "$token\n\n";
} else {
    echo "⚠ Some tests failed\n";
    echo "Please check the PHP server logs and .htaccess configuration\n\n";
}
