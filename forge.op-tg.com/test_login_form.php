<?php
/**
 * Test Login Form - Simulates exact login process with debugging
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/bootstrap.php';

$pdo = db();

// Simulate POST data
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

// Test data
$username = 'forge-admin';
$password = 'Forge@2025!';
$loginType = 'superadmin';

echo "=== LOGIN FORM SIMULATION ===\n\n";

// Step 1: Check rate limiting
echo "1. RATE LIMIT CHECK\n";
$ip = $_SERVER['REMOTE_ADDR'];
$key = $username;
$stRL = $pdo->prepare("SELECT COUNT(*) c FROM auth_attempts WHERE ip = ? AND key = ? AND created_at > datetime('now','-10 minutes')");
$stRL->execute([$ip, $key]);
$attempts = (int)$stRL->fetch()['c'];
echo "   IP: $ip\n";
echo "   Key: $key\n";
echo "   Attempts in last 10 min: $attempts\n";
if ($attempts >= 5) {
    echo "   ✗ BLOCKED by rate limit!\n";
} else {
    echo "   ✓ Rate limit OK\n";
}

// Step 2: User lookup
echo "\n2. USER LOOKUP\n";
echo "   Username: '$username'\n";
$st = $pdo->prepare("SELECT id, username, password_hash, is_superadmin, role FROM users WHERE username = :u LIMIT 1");
$st->execute([':u' => $username]);
$u = $st->fetch();

if (!$u) {
    echo "   ✗ User NOT FOUND!\n";
    exit;
}

echo "   ✓ User found:\n";
echo "     - ID: {$u['id']}\n";
echo "     - Username: {$u['username']}\n";
echo "     - Role: {$u['role']}\n";
echo "     - is_superadmin: {$u['is_superadmin']}\n";
echo "     - Hash: " . substr($u['password_hash'], 0, 30) . "...\n";

// Step 3: Superadmin check
echo "\n3. SUPERADMIN CHECK\n";
$isSuperadmin = (int)($u['is_superadmin'] ?? 0);
echo "   is_superadmin value: $isSuperadmin\n";
echo "   Check (int)(\$u['is_superadmin'] ?? 0) === 1: " . (($isSuperadmin === 1) ? "TRUE" : "FALSE") . "\n";

// Step 4: Password verification
echo "\n4. PASSWORD VERIFICATION\n";
echo "   Password to verify: '$password'\n";
echo "   Hash from DB: {$u['password_hash']}\n";
$passwordValid = password_verify($password, (string)$u['password_hash']);
echo "   password_verify() result: " . ($passwordValid ? "TRUE" : "FALSE") . "\n";

// Step 5: Combined check (exact logic from login.php line 38)
echo "\n5. COMBINED LOGIN CHECK (exact code from login.php)\n";
$condition = !$u || (int)($u['is_superadmin'] ?? 0) !== 1 || !password_verify($password, (string)$u['password_hash']);
echo "   Condition: !u || is_superadmin !== 1 || !password_verify\n";
echo "   - !u = " . (!$u ? "true" : "false") . "\n";
echo "   - (int)(is_superadmin ?? 0) !== 1 = " . ((int)($u['is_superadmin'] ?? 0) !== 1 ? "true" : "false") . "\n";
echo "   - !password_verify = " . (!password_verify($password, (string)$u['password_hash']) ? "true" : "false") . "\n";
echo "   FINAL CONDITION (should be false for success): " . ($condition ? "TRUE (FAIL)" : "FALSE (SUCCESS)") . "\n";

// Step 6: Final verdict
echo "\n6. FINAL VERDICT\n";
if (!$condition) {
    echo "   ✓✓✓ LOGIN SHOULD SUCCEED! ✓✓✓\n";
} else {
    echo "   ✗✗✗ LOGIN WILL FAIL ✗✗✗\n";
}

// Step 7: Test with different password
echo "\n7. TESTING OTHER PASSWORDS\n";
$testPasswords = ['Forge@2025!', 'Forge@2025', '@OpTarget20#30', 'admin', ''];
foreach ($testPasswords as $p) {
    $result = password_verify($p, (string)$u['password_hash']);
    echo "   '$p' => " . ($result ? "VALID" : "invalid") . "\n";
}

echo "\n=== END ===\n";
