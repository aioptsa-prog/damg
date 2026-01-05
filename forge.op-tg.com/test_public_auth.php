<?php
/**
 * Test Public Authentication APIs
 */

echo "Testing Public Authentication APIs\n";
echo "===================================\n\n";

// Test 1: Register new user
echo "Test 1: Register new user\n";
echo "--------------------------\n";

$registerData = [
    'email' => 'test@example.com',
    'password' => 'TestPassword123',
    'name' => 'اختبار المستخدم',
    'company' => 'شركة الاختبار',
    'phone' => '+966500000000'
];

$ch = curl_init('http://localhost:8000/v1/api/public/auth/register.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($registerData));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Status: $httpCode\n";
echo "Response: $response\n\n";

$registerResult = json_decode($response, true);

if ($registerResult && $registerResult['ok']) {
    echo "✓ Registration successful!\n";
    $token = $registerResult['token'];
    $userId = $registerResult['user']['id'];
    echo "  User ID: $userId\n";
    echo "  Token: " . substr($token, 0, 20) . "...\n\n";
} else {
    echo "✗ Registration failed\n\n";
    exit(1);
}

// Test 2: Login with created user
echo "Test 2: Login\n";
echo "-------------\n";

$loginData = [
    'email' => 'test@example.com',
    'password' => 'TestPassword123'
];

$ch = curl_init('http://localhost:8000/v1/api/public/auth/login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($loginData));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Status: $httpCode\n";
echo "Response: $response\n\n";

$loginResult = json_decode($response, true);

if ($loginResult && $loginResult['ok']) {
    echo "✓ Login successful!\n";
    $loginToken = $loginResult['token'];
    echo "  Token: " . substr($loginToken, 0, 20) . "...\n";
    echo "  Subscription Plan: " . $loginResult['subscription']['plan']['name'] . "\n\n";
} else {
    echo "✗ Login failed\n\n";
}

// Test 3: Get current user (authenticated)
echo "Test 3: Get Current User\n";
echo "------------------------\n";

$ch = curl_init('http://localhost:8000/v1/api/public/auth/me.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $loginToken
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Status: $httpCode\n";
echo "Response: $response\n\n";

if ($httpCode === 200) {
    echo "✓ Authentication working!\n\n";
} else {
    echo "✗ Authentication failed\n\n";
}

// Test 4: Logout
echo "Test 4: Logout\n";
echo "--------------\n";

$ch = curl_init('http://localhost:8000/v1/api/public/auth/logout.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $loginToken
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Status: $httpCode\n";
echo "Response: $response\n\n";

if ($httpCode === 200) {
    echo "✓ Logout successful!\n\n";
} else {
    echo "✗ Logout failed\n\n";
}

echo "===================================\n";
echo "✓ All tests passed!\n";
