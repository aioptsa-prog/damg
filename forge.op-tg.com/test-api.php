<?php
/**
 * API Test Script
 * Tests all v1 API endpoints locally
 */

$baseUrl = 'http://localhost:8000/v1/api';

echo "=================================\n";
echo "Testing API Endpoints\n";
echo "=================================\n\n";

// Test 1: Login
echo "1. Testing Login...\n";
$ch = curl_init("$baseUrl/auth/login");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'mobile' => '590000000',
    'password' => 'Forge@2025!',
    'remember' => false
]));
curl_setopt($ch, CURLOPT_HEADER, true);

$response = curl_exec($ch);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$header = substr($response, 0, $headerSize);
$body = substr($response, $headerSize);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "   HTTP Status: $httpCode\n";
$data = json_decode($body, true);
if ($data && $data['ok'] && isset($data['token'])) {
    $token = $data['token'];
    echo "   ✅ Login successful! User: {$data['user']['name']}\n";
    echo "   Session Token: $token\n\n";

    // Extract session cookie
    preg_match('/Set-Cookie: PHPSESSID=([^;]+)/', $header, $matches);
    $sessionCookie = $matches ? "PHPSESSID={$matches[1]}" : null;

} else {
    echo "   ❌ Login failed: " . ($data['error'] ?? 'Unknown error') . "\n\n";
    exit(1);
}

// Test 2: Get Current User
echo "2. Testing Get Current User...\n";
$ch = curl_init("$baseUrl/auth/me");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    "X-Session-ID: $token"
]);
if ($sessionCookie) {
    curl_setopt($ch, CURLOPT_COOKIE, $sessionCookie);
}

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "   HTTP Status: $httpCode\n";
$data = json_decode($response, true);
if ($data && $data['ok']) {
    echo "   ✅ User info retrieved: {$data['user']['name']} ({$data['user']['role']})\n\n";
} else {
    echo "   ❌ Failed: " . ($data['error'] ?? 'Unknown error') . "\n\n";
}

// Test 3: Get Categories
echo "3. Testing Get Categories...\n";
$ch = curl_init("$baseUrl/categories/index.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "   HTTP Status: $httpCode\n";
$data = json_decode($response, true);
if ($data && $data['ok']) {
    $total = $data['total'] ?? count($data['data']);
    echo "   ✅ Categories retrieved: $total categories\n";
    if (!empty($data['data'])) {
        echo "   Sample category: {$data['data'][0]['name']}\n";
    }
    echo "\n";
} else {
    echo "   ❌ Failed: " . ($data['error'] ?? 'Unknown error') . "\n\n";
}

// Test 4: Get Leads (with authentication)
echo "4. Testing Get Leads...\n";
$ch = curl_init("$baseUrl/leads/index.php?page=1&limit=5");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    "X-Session-ID: $token"
]);
if ($sessionCookie) {
    curl_setopt($ch, CURLOPT_COOKIE, $sessionCookie);
}

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "   HTTP Status: $httpCode\n";
$data = json_decode($response, true);
if ($data && $data['ok']) {
    $pagination = $data['pagination'];
    echo "   ✅ Leads retrieved:\n";
    echo "      - Total: {$pagination['total']} leads\n";
    echo "      - Pages: {$pagination['pages']}\n";
    echo "      - Results: " . count($data['data']) . " leads\n";
    if (!empty($data['data'])) {
        $lead = $data['data'][0];
        echo "      - Sample lead: {$lead['name']} ({$lead['phone']})\n";
    }
    echo "\n";
} else {
    echo "   ❌ Failed: " . ($data['error'] ?? 'Unknown error') . "\n\n";
}

// Test 5: Get Leads with filter
echo "5. Testing Get Leads with Category Filter...\n";
$ch = curl_init("$baseUrl/leads/index.php?page=1&limit=3&category_id=1");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    "X-Session-ID: $token"
]);
if ($sessionCookie) {
    curl_setopt($ch, CURLOPT_COOKIE, $sessionCookie);
}

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "   HTTP Status: $httpCode\n";
$data = json_decode($response, true);
if ($data && $data['ok']) {
    $pagination = $data['pagination'];
    echo "   ✅ Filtered leads retrieved: {$pagination['total']} matching leads\n\n";
} else {
    echo "   ❌ Failed: " . ($data['error'] ?? 'Unknown error') . "\n\n";
}

// Test 6: Logout
echo "6. Testing Logout...\n";
$ch = curl_init("$baseUrl/auth/logout");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    "X-Session-ID: $token"
]);
if ($sessionCookie) {
    curl_setopt($ch, CURLOPT_COOKIE, $sessionCookie);
}

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "   HTTP Status: $httpCode\n";
$data = json_decode($response, true);
if ($data && $data['ok']) {
    echo "   ✅ Logout successful\n\n";
} else {
    echo "   ❌ Failed: " . ($data['error'] ?? 'Unknown error') . "\n\n";
}

echo "=================================\n";
echo "✅ All API tests completed!\n";
echo "=================================\n";
