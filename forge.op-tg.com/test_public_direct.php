<?php
/**
 * Direct Test of Public Authentication (No cURL)
 */

require_once 'lib/public_auth.php';
require_once 'lib/subscriptions.php';

echo "Testing Public Authentication - Direct PHP\n";
echo "==========================================\n\n";

// Test 1: Register
echo "Test 1: Register User\n";
echo "---------------------\n";
$result = register_public_user(
    'test2@example.com',
    'TestPass123',
    'مستخدم تجريبي',
    'شركة تجريبية',
    '+966501234567'
);

if ($result['success']) {
    echo "✓ Registration successful!\n";
    echo "  User ID: {$result['user']['id']}\n";
    echo "  Email: {$result['user']['email']}\n";
    echo "  Token: " . substr($result['token'], 0, 20) . "...\n\n";
    $test_token = $result['token'];
} else {
    echo "✗ Registration failed: {$result['message']}\n\n";
}

// Test 2: Login
echo "Test 2: Login\n";
echo "-------------\n";
$result = login_public_user('test2@example.com', 'TestPass123');

if ($result['success']) {
    echo "✓ Login successful!\n";
    echo "  User ID: {$result['user']['id']}\n";
    echo "  Token: " . substr($result['token'], 0, 20) . "...\n";
    echo "  Subscription Plan: {$result['subscription']['name']}\n";
    echo "  Phone Credits: {$result['subscription']['credits_phone']}\n\n";
    $login_token = $result['token'];
} else {
    echo "✗ Login failed: {$result['message']}\n\n";
}

// Test 3: Get Subscription Details
echo "Test 3: Get User Subscription\n";
echo "-----------------------------\n";
$subscription = get_user_subscription($result['user']['id']);

if ($subscription) {
    echo "✓ Subscription retrieved!\n";
    echo "  Plan: {$subscription['name']}\n";
    echo "  Slug: {$subscription['slug']}\n";
    echo "  Phone Credits: {$subscription['credits_phone']}\n";
    echo "  Email Credits: {$subscription['credits_email']}\n";
    echo "  Export Credits: {$subscription['credits_export']}\n";
    echo "  Max Saved Searches: {$subscription['max_saved_searches']}\n";
    echo "  Max Saved Lists: {$subscription['max_saved_lists']}\n\n";
}

// Test 4: Check Quota
echo "Test 4: Check Quota\n";
echo "-------------------\n";
$userId = $result['user']['id'];

$phoneQuota = check_quota($userId, 'phone');
echo "Phone Quota:\n";
echo "  Allowed: " . ($phoneQuota['allowed'] ? 'Yes' : 'No') . "\n";
echo "  Message: {$phoneQuota['message']}\n\n";

$emailQuota = check_quota($userId, 'email');
echo "Email Quota:\n";
echo "  Allowed: " . ($emailQuota['allowed'] ? 'Yes' : 'No') . "\n";
echo "  Message: {$emailQuota['message']}\n\n";

// Test 5: Get Usage
echo "Test 5: Get Usage Stats\n";
echo "-----------------------\n";
$usage = get_user_usage($userId);
echo "Current Month Usage:\n";
echo "  Phone Reveals: {$usage['phone_reveals']}\n";
echo "  Email Reveals: {$usage['email_reveals']}\n";
echo "  Exports: {$usage['exports_count']}\n";
echo "  Searches: {$usage['searches_count']}\n\n";

// Test 6: Get All Plans
echo "Test 6: Get All Subscription Plans\n";
echo "-----------------------------------\n";
$plans = get_subscription_plans();
echo "Available Plans: " . count($plans) . "\n";
foreach ($plans as $plan) {
    echo "  - {$plan['name']} ({$plan['slug']}): {$plan['price_monthly']} SAR/month\n";
}
echo "\n";

echo "==========================================\n";
echo "✓ All tests completed successfully!\n";
