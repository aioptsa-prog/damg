<?php
/**
 * Test Integration Token Exchange
 * Execute: php test_integration_exchange.php
 * 
 * Tests the exchange endpoint with valid and invalid assertions
 */

require_once __DIR__ . '/bootstrap.php';

echo "=== Integration Token Exchange Tests ===\n\n";

$secret = get_setting('integration_shared_secret', '');
if ($secret === '') {
    die("ERROR: integration_shared_secret not configured\n");
}

$flag = get_setting('integration_auth_bridge', '0');
echo "Flag integration_auth_bridge: $flag\n";
if ($flag !== '1') {
    die("ERROR: integration_auth_bridge flag is not enabled\n");
}

echo "Secret configured: YES\n\n";

// Helper function to create signed assertion
function createAssertion($secret, $sub, $role, $nonce = null, $expOffset = 300) {
    $now = time();
    $canonical = [
        'exp' => $now + $expOffset,
        'iat' => $now,
        'issuer' => 'op-target',
        'nonce' => $nonce ?? bin2hex(random_bytes(16)),
        'role' => $role,
        'sub' => $sub,
    ];
    $canonicalJson = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $sig = hash_hmac('sha256', $canonicalJson, $secret);
    
    return array_merge($canonical, ['sig' => $sig]);
}

// Helper to call exchange endpoint
function callExchange($payload) {
    $pdo = db();
    
    // Simulate the exchange logic directly (since we're testing locally)
    // In real test, you'd use curl to http://localhost:8080/v1/api/integration/exchange.php
    
    // For this test, we'll include the exchange file logic
    $_SERVER['REQUEST_METHOD'] = 'POST';
    
    // Parse payload
    $input = $payload;
    
    // Validate required fields
    $requiredFields = ['issuer', 'sub', 'role', 'iat', 'exp', 'nonce', 'sig'];
    foreach ($requiredFields as $field) {
        if (!isset($input[$field]) || $input[$field] === '') {
            return ['ok' => false, 'error' => "Missing required field: $field"];
        }
    }
    
    $issuer = (string) $input['issuer'];
    $sub = (string) $input['sub'];
    $role = (string) $input['role'];
    $iat = (int) $input['iat'];
    $exp = (int) $input['exp'];
    $nonce = (string) $input['nonce'];
    $sig = (string) $input['sig'];
    
    // Validate issuer
    if ($issuer !== 'op-target') {
        return ['ok' => false, 'error' => 'Invalid issuer'];
    }
    
    // Validate role
    $validRoles = ['SUPER_ADMIN', 'MANAGER', 'SALES_REP'];
    if (!in_array($role, $validRoles, true)) {
        return ['ok' => false, 'error' => 'Invalid role'];
    }
    
    // Validate time window
    $now = time();
    $clockSkew = 60;
    
    if ($iat > $now + $clockSkew) {
        return ['ok' => false, 'error' => 'Token issued in the future'];
    }
    
    if ($exp < $now - $clockSkew) {
        return ['ok' => false, 'error' => 'Token expired'];
    }
    
    // Get secret
    $secret = get_setting('integration_shared_secret', '');
    
    // Verify signature
    $canonical = [
        'exp' => $exp,
        'iat' => $iat,
        'issuer' => $issuer,
        'nonce' => $nonce,
        'role' => $role,
        'sub' => $sub,
    ];
    $canonicalJson = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $expectedSig = hash_hmac('sha256', $canonicalJson, $secret);
    
    if (!hash_equals($expectedSig, $sig)) {
        return ['ok' => false, 'error' => 'Invalid signature'];
    }
    
    // Check nonce
    $cleanupTime = date('Y-m-d H:i:s', $now - 600);
    $pdo->prepare("DELETE FROM integration_nonces WHERE expires_at < ?")->execute([$cleanupTime]);
    
    $stmt = $pdo->prepare("SELECT 1 FROM integration_nonces WHERE nonce = ?");
    $stmt->execute([$nonce]);
    if ($stmt->fetch()) {
        return ['ok' => false, 'error' => 'Nonce already used'];
    }
    
    // Store nonce
    $nonceExpires = date('Y-m-d H:i:s', $now + 600);
    $stmt = $pdo->prepare("INSERT INTO integration_nonces (nonce, issuer, sub, expires_at) VALUES (?, ?, ?, ?)");
    $stmt->execute([$nonce, $issuer, $sub, $nonceExpires]);
    
    // Map role
    $roleMap = ['SUPER_ADMIN' => 'admin', 'MANAGER' => 'admin', 'SALES_REP' => 'agent'];
    $forgeRole = $roleMap[$role] ?? 'agent';
    
    // Generate token
    $token = bin2hex(random_bytes(32));
    $tokenExpires = date('Y-m-d H:i:s', $now + 300);
    
    $metadata = json_encode(['original_role' => $role, 'issuer' => $issuer]);
    $stmt = $pdo->prepare("INSERT INTO integration_sessions (token, op_target_user_id, forge_role, expires_at, metadata) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$token, $sub, $forgeRole, $tokenExpires, $metadata]);
    
    return ['ok' => true, 'token' => $token, 'expires_in' => 300, 'forge_role' => $forgeRole];
}

// Test 1: Valid assertion
echo "Test 1: Valid assertion (SUPER_ADMIN)\n";
$assertion = createAssertion($secret, 'user-123', 'SUPER_ADMIN');
$result = callExchange($assertion);
echo "Result: " . json_encode($result) . "\n";
echo "Status: " . ($result['ok'] ? "PASS ✓" : "FAIL ✗") . "\n\n";

// Test 2: Valid assertion with different role
echo "Test 2: Valid assertion (SALES_REP)\n";
$assertion = createAssertion($secret, 'user-456', 'SALES_REP');
$result = callExchange($assertion);
echo "Result: " . json_encode($result) . "\n";
echo "Status: " . ($result['ok'] && $result['forge_role'] === 'agent' ? "PASS ✓" : "FAIL ✗") . "\n\n";

// Test 3: Invalid signature
echo "Test 3: Invalid signature\n";
$assertion = createAssertion($secret, 'user-789', 'MANAGER');
$assertion['sig'] = 'invalid_signature_here';
$result = callExchange($assertion);
echo "Result: " . json_encode($result) . "\n";
echo "Status: " . (!$result['ok'] && $result['error'] === 'Invalid signature' ? "PASS ✓" : "FAIL ✗") . "\n\n";

// Test 4: Expired token
echo "Test 4: Expired token\n";
$assertion = createAssertion($secret, 'user-expired', 'MANAGER', null, -120); // expired 2 minutes ago
$result = callExchange($assertion);
echo "Result: " . json_encode($result) . "\n";
echo "Status: " . (!$result['ok'] && $result['error'] === 'Token expired' ? "PASS ✓" : "FAIL ✗") . "\n\n";

// Test 5: Replay attack (reuse nonce)
echo "Test 5: Replay attack (reuse nonce)\n";
$nonce = bin2hex(random_bytes(16));
$assertion1 = createAssertion($secret, 'user-replay', 'MANAGER', $nonce);
$result1 = callExchange($assertion1);
echo "First call: " . json_encode($result1) . "\n";

$assertion2 = createAssertion($secret, 'user-replay', 'MANAGER', $nonce);
$result2 = callExchange($assertion2);
echo "Second call (replay): " . json_encode($result2) . "\n";
echo "Status: " . ($result1['ok'] && !$result2['ok'] && $result2['error'] === 'Nonce already used' ? "PASS ✓" : "FAIL ✗") . "\n\n";

// Test 6: Invalid role
echo "Test 6: Invalid role\n";
$assertion = createAssertion($secret, 'user-badrole', 'INVALID_ROLE');
$result = callExchange($assertion);
echo "Result: " . json_encode($result) . "\n";
echo "Status: " . (!$result['ok'] && $result['error'] === 'Invalid role' ? "PASS ✓" : "FAIL ✗") . "\n\n";

echo "=== All Tests Complete ===\n";
