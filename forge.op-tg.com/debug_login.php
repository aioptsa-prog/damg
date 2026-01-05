<?php
/**
 * Debug Login Script - Comprehensive testing for login issues
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/bootstrap.php';

echo "=== LOGIN DEBUG TOOL ===\n\n";

$pdo = db();

// Step 1: Check database connection
echo "1. DATABASE CONNECTION\n";
echo "   Status: OK\n\n";

// Step 2: List all users
echo "2. ALL USERS IN DATABASE\n";
$users = $pdo->query("SELECT id, mobile, username, name, role, is_superadmin, active, password_hash FROM users")->fetchAll();
foreach ($users as $u) {
    echo "   ID: {$u['id']}\n";
    echo "   Mobile: {$u['mobile']}\n";
    echo "   Username: " . ($u['username'] ?? 'NULL') . "\n";
    echo "   Name: {$u['name']}\n";
    echo "   Role: {$u['role']}\n";
    echo "   Is Superadmin: {$u['is_superadmin']}\n";
    echo "   Active: {$u['active']}\n";
    echo "   Hash (first 20): " . substr($u['password_hash'], 0, 20) . "...\n";
    echo "   ---\n";
}

// Step 3: Test password verification for superadmin
echo "\n3. PASSWORD VERIFICATION TESTS\n";

$testCases = [
    ['username' => 'forge-admin', 'password' => 'Forge@2025!'],
    ['username' => 'admin', 'password' => 'Forge@2025!'],
    ['username' => 'forge-admin', 'password' => '@OpTarget20#30'],
    ['mobile' => '590000000', 'password' => 'Forge@2025!'],
];

foreach ($testCases as $i => $test) {
    echo "   Test " . ($i + 1) . ":\n";
    
    if (isset($test['username'])) {
        echo "   - Looking up by username: {$test['username']}\n";
        $st = $pdo->prepare("SELECT id, username, password_hash, is_superadmin FROM users WHERE username = ?");
        $st->execute([$test['username']]);
    } else {
        echo "   - Looking up by mobile: {$test['mobile']}\n";
        $st = $pdo->prepare("SELECT id, mobile, password_hash, is_superadmin FROM users WHERE mobile = ?");
        $st->execute([$test['mobile']]);
    }
    
    $user = $st->fetch();
    
    if (!$user) {
        echo "   - User NOT FOUND\n";
    } else {
        echo "   - User FOUND (ID: {$user['id']})\n";
        $verify = password_verify($test['password'], $user['password_hash']);
        echo "   - Password '{$test['password']}': " . ($verify ? "✓ VALID" : "✗ INVALID") . "\n";
        echo "   - Is Superadmin: " . ($user['is_superadmin'] ? "YES" : "NO") . "\n";
    }
    echo "\n";
}

// Step 4: Check auth_attempts (rate limiting)
echo "4. RATE LIMITING CHECK\n";
try {
    $attempts = $pdo->query("SELECT * FROM auth_attempts ORDER BY created_at DESC LIMIT 10")->fetchAll();
    echo "   Recent attempts: " . count($attempts) . "\n";
    foreach ($attempts as $a) {
        echo "   - IP: {$a['ip']}, Key: {$a['key']}, Time: {$a['created_at']}\n";
    }
} catch (Throwable $e) {
    echo "   Error: " . $e->getMessage() . "\n";
}

// Step 5: Check sessions table
echo "\n5. SESSIONS TABLE\n";
try {
    $sessions = $pdo->query("SELECT * FROM sessions ORDER BY created_at DESC LIMIT 5")->fetchAll();
    echo "   Active sessions: " . count($sessions) . "\n";
} catch (Throwable $e) {
    echo "   Error: " . $e->getMessage() . "\n";
}

// Step 6: Simulate login process
echo "\n6. SIMULATING LOGIN PROCESS\n";
$username = 'forge-admin';
$password = 'Forge@2025!';

echo "   Attempting login with username='$username', password='$password'\n";

$st = $pdo->prepare("SELECT id, username, password_hash, is_superadmin, role FROM users WHERE username = :u LIMIT 1");
$st->execute([':u' => $username]);
$u = $st->fetch();

if (!$u) {
    echo "   ✗ FAILED: User not found by username\n";
} else {
    echo "   ✓ User found: ID={$u['id']}, username={$u['username']}\n";
    
    $isSuperadmin = (int)($u['is_superadmin'] ?? 0) === 1;
    echo "   - Is superadmin check: " . ($isSuperadmin ? "PASS" : "FAIL") . "\n";
    
    $passwordValid = password_verify($password, (string)$u['password_hash']);
    echo "   - Password verify: " . ($passwordValid ? "PASS" : "FAIL") . "\n";
    
    if ($isSuperadmin && $passwordValid) {
        echo "   ✓ LOGIN SHOULD SUCCEED!\n";
    } else {
        echo "   ✗ LOGIN WILL FAIL\n";
        if (!$isSuperadmin) echo "     Reason: Not a superadmin\n";
        if (!$passwordValid) echo "     Reason: Wrong password\n";
    }
}

// Step 7: Check CSRF function
echo "\n7. CSRF FUNCTION CHECK\n";
if (function_exists('csrf_token')) {
    echo "   csrf_token() exists: YES\n";
    if (session_status() === PHP_SESSION_NONE) session_start();
    $token = csrf_token();
    echo "   Generated token: " . substr($token, 0, 20) . "...\n";
} else {
    echo "   csrf_token() exists: NO - THIS IS A PROBLEM!\n";
}

// Step 8: Check .env.php
echo "\n8. CONFIG CHECK\n";
try {
    $env = require __DIR__ . '/config/.env.php';
    echo "   SQLITE_PATH: " . ($env['SQLITE_PATH'] ?? 'NOT SET') . "\n";
    echo "   REMEMBER_DAYS: " . ($env['REMEMBER_DAYS'] ?? 'NOT SET') . "\n";
    echo "   REMEMBER_COOKIE: " . ($env['REMEMBER_COOKIE'] ?? 'NOT SET') . "\n";
} catch (Throwable $e) {
    echo "   Error loading config: " . $e->getMessage() . "\n";
}

echo "\n=== END DEBUG ===\n";
