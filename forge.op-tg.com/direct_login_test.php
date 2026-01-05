<?php
/**
 * Direct Login Test - Bypasses form, directly tests login logic
 * Access via: http://localhost:8000/direct_login_test.php
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/bootstrap.php';

$pdo = db();
$result = [];

// Test credentials
$username = 'forge-admin';
$password = 'Forge@2025!';

echo "<h1>Direct Login Test</h1>";
echo "<pre>";

// Step 1: Find user
echo "1. Looking up user '$username'...\n";
$st = $pdo->prepare("SELECT id, username, password_hash, is_superadmin, role FROM users WHERE username = :u LIMIT 1");
$st->execute([':u' => $username]);
$u = $st->fetch();

if (!$u) {
    echo "   ERROR: User not found!\n";
    exit;
}

echo "   Found: ID={$u['id']}, role={$u['role']}, is_superadmin={$u['is_superadmin']}\n";

// Step 2: Verify password
echo "\n2. Verifying password...\n";
$valid = password_verify($password, $u['password_hash']);
echo "   Result: " . ($valid ? "VALID" : "INVALID") . "\n";

// Step 3: Check superadmin
echo "\n3. Checking superadmin status...\n";
$isSuperadmin = (int)($u['is_superadmin'] ?? 0) === 1;
echo "   Result: " . ($isSuperadmin ? "YES" : "NO") . "\n";

// Step 4: Attempt actual login
if ($valid && $isSuperadmin) {
    echo "\n4. Attempting to create session...\n";
    
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    session_regenerate_id(true);
    
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['role'] = 'admin';
    $_SESSION['is_superadmin'] = 1;
    $_SESSION['username'] = (string)$u['username'];
    
    echo "   Session created!\n";
    echo "   Session ID: " . session_id() . "\n";
    echo "   \$_SESSION['uid'] = {$_SESSION['uid']}\n";
    echo "   \$_SESSION['role'] = {$_SESSION['role']}\n";
    echo "   \$_SESSION['is_superadmin'] = {$_SESSION['is_superadmin']}\n";
    
    echo "\n<b style='color:green'>✓ LOGIN SUCCESSFUL!</b>\n";
    echo "\n<a href='/admin/dashboard.php'>Click here to go to Dashboard</a>\n";
} else {
    echo "\n<b style='color:red'>✗ LOGIN FAILED</b>\n";
    if (!$valid) echo "   Reason: Invalid password\n";
    if (!$isSuperadmin) echo "   Reason: Not a superadmin\n";
}

echo "</pre>";
