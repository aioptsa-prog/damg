<?php
/**
 * Direct API Test - Diagnose why templates not showing
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/config/db.php';

echo "=== WhatsApp API Diagnostic ===\n\n";

$pdo = db();

// 1. Check if tables exist
echo "1. Checking tables...\n";
$tables = ['whatsapp_settings', 'whatsapp_templates', 'whatsapp_logs'];
foreach ($tables as $table) {
    $exists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='$table'")->fetch();
    echo "   - $table: " . ($exists ? "✅ EXISTS" : "❌ MISSING") . "\n";
}

// 2. Check templates in database
echo "\n2. Templates in database:\n";
$templates = $pdo->query("SELECT * FROM whatsapp_templates")->fetchAll(PDO::FETCH_ASSOC);
if (count($templates) === 0) {
    echo "   ⚠️ No templates found!\n";
} else {
    foreach ($templates as $t) {
        echo "   - [{$t['id']}] {$t['name']} (user_id: {$t['user_id']})\n";
    }
}

// 3. Check sessions
echo "\n3. Active sessions:\n";
$sessions = $pdo->query("SELECT s.*, u.name as user_name FROM sessions s JOIN users u ON s.user_id = u.id WHERE expires_at > datetime('now') LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
echo "   Admin sessions (sessions/users table): " . count($sessions) . "\n";
foreach ($sessions as $s) {
    echo "   - User: {$s['user_name']} (ID: {$s['user_id']})\n";
}

$publicSessions = $pdo->query("SELECT s.*, u.name as user_name FROM public_sessions s JOIN public_users u ON s.user_id = u.id WHERE expires_at > datetime('now') LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
echo "   Public sessions (public_sessions/public_users table): " . count($publicSessions) . "\n";
foreach ($publicSessions as $s) {
    echo "   - User: {$s['user_name']} (ID: {$s['user_id']})\n";
}

// 4. Get a valid admin token and test API
echo "\n4. Testing API with fresh admin token...\n";
$user = $pdo->query("SELECT * FROM users WHERE active = 1 LIMIT 1")->fetch();
if (!$user) {
    echo "   ❌ No active admin users found!\n";
} else {
    echo "   Admin user: {$user['name']} (ID: {$user['id']})\n";

    // Create fresh token
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expires = date('Y-m-d H:i:s', time() + 86400);

    $pdo->prepare("INSERT INTO sessions (user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, datetime('now'))")
        ->execute([$user['id'], $tokenHash, $expires]);

    echo "   Created token: " . substr($token, 0, 20) . "...\n";

    // Test templates API
    echo "\n5. Direct API simulation...\n";

    // Set up environment
    $_SERVER['HTTP_AUTHORIZATION'] = "Bearer $token";
    $_SERVER['REQUEST_METHOD'] = 'GET';

    // Include auth libs
    require_once __DIR__ . '/lib/auth.php';
    require_once __DIR__ . '/lib/public_auth.php';

    // Test current_user()
    $authUser = current_user();
    if ($authUser) {
        echo "   ✅ current_user() works! User: {$authUser['name']}\n";
    } else {
        echo "   ❌ current_user() returned null\n";
    }

    // Test current_public_user()
    $publicUser = current_public_user();
    if ($publicUser) {
        echo "   ✅ current_public_user() works! User: {$publicUser['name']}\n";
    } else {
        echo "   ❌ current_public_user() returned null\n";
    }
}

echo "\n=== Done ===\n";
