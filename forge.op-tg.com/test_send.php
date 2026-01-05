<?php
/**
 * Test WhatsApp Send API
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/db.php';

echo "=== WhatsApp Send Test ===\n\n";

$pdo = db();

// Get admin user
$user = $pdo->query("SELECT * FROM users WHERE active = 1 LIMIT 1")->fetch();
echo "User: {$user['name']} (ID: {$user['id']})\n";

// Check WhatsApp settings
$stmt = $pdo->prepare("SELECT * FROM whatsapp_settings WHERE user_id = ?");
$stmt->execute([$user['id']]);
$settings = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$settings) {
    echo "\n❌ No WhatsApp settings found for this user!\n";
    echo "   User needs to configure WhatsApp settings first.\n";

    // Check for any settings
    $allSettings = $pdo->query("SELECT * FROM whatsapp_settings")->fetchAll(PDO::FETCH_ASSOC);
    echo "\n   All settings in DB: " . count($allSettings) . "\n";
    foreach ($allSettings as $s) {
        echo "   - User ID: {$s['user_id']}, Active: {$s['is_active']}\n";
    }
} else {
    echo "\n✅ WhatsApp settings found:\n";
    echo "   - API URL: {$settings['api_url']}\n";
    echo "   - Sender Number: {$settings['sender_number']}\n";
    echo "   - Auth Token: " . (empty($settings['auth_token']) ? '❌ EMPTY' : '✅ Set (' . strlen($settings['auth_token']) . ' chars)') . "\n";
    echo "   - Is Active: " . ($settings['is_active'] ? '✅ Yes' : '❌ No') . "\n";
}

// Check if curl is available
echo "\n\n=== cURL Check ===\n";
if (function_exists('curl_init')) {
    echo "✅ cURL is available\n";
    $curlVersion = curl_version();
    echo "   Version: {$curlVersion['version']}\n";
} else {
    echo "❌ cURL is NOT available!\n";
    echo "   This is why send.php returns 500 error.\n";
}

echo "\n=== Done ===\n";
