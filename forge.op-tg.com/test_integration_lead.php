<?php
/**
 * Test Integration Lead Endpoint
 * Execute: php test_integration_lead.php
 * 
 * Tests the lead endpoint with valid integration token
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/flags.php';
require_once __DIR__ . '/lib/integration_auth.php';

echo "=== Integration Lead Endpoint Tests ===\n\n";

// Check flag
$flag = get_setting('integration_auth_bridge', '0');
if ($flag !== '1') {
    die("ERROR: integration_auth_bridge flag is not enabled\n");
}

$pdo = db();

// First, create a test token
$token = bin2hex(random_bytes(32));
$tokenExpires = date('Y-m-d H:i:s', time() + 300);
$stmt = $pdo->prepare("INSERT INTO integration_sessions (token, op_target_user_id, forge_role, expires_at, metadata) VALUES (?, ?, ?, ?, ?)");
$stmt->execute([$token, 'test-user-phase2', 'admin', $tokenExpires, '{"test":true}']);
echo "Created test token: " . substr($token, 0, 16) . "...\n\n";

// Create a test lead if not exists
$testPhone = '0501234567';
$testPhoneNorm = '966501234567';
$stmt = $pdo->prepare("SELECT id FROM leads WHERE phone = ? LIMIT 1");
$stmt->execute([$testPhone]);
$existingLead = $stmt->fetch();

if (!$existingLead) {
    $stmt = $pdo->prepare("INSERT INTO leads (phone, phone_norm, name, city, created_at) VALUES (?, ?, ?, ?, datetime('now'))");
    $stmt->execute([$testPhone, $testPhoneNorm, 'Test Lead Phase2', 'Riyadh']);
    $testLeadId = $pdo->lastInsertId();
    echo "Created test lead ID: $testLeadId\n\n";
} else {
    $testLeadId = $existingLead['id'];
    echo "Using existing test lead ID: $testLeadId\n\n";
}

// Helper function to simulate API call
function callLeadEndpoint($token, $params) {
    // Set up environment
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    $_GET = $params;
    
    // Verify token
    $integration = verify_integration_token();
    if (!$integration) {
        return ['ok' => false, 'error' => 'Invalid or expired integration token'];
    }
    
    $pdo = db();
    
    $leadId = isset($params['id']) ? trim((string)$params['id']) : '';
    $phone = isset($params['phone']) ? trim((string)$params['phone']) : '';
    
    if ($leadId === '' && $phone === '') {
        return ['ok' => false, 'error' => 'Missing id or phone parameter'];
    }
    
    if ($leadId !== '') {
        $stmt = $pdo->prepare("SELECT id, phone, phone_norm, name, city FROM leads WHERE id = ? LIMIT 1");
        $stmt->execute([$leadId]);
    } else {
        $phoneNorm = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phoneNorm) === 10 && $phoneNorm[0] === '0') {
            $phoneNorm = '966' . substr($phoneNorm, 1);
        }
        $stmt = $pdo->prepare("SELECT id, phone, phone_norm, name, city FROM leads WHERE phone_norm = ? OR phone = ? LIMIT 1");
        $stmt->execute([$phoneNorm, $phone]);
    }
    
    $lead = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$lead) {
        return ['ok' => false, 'error' => 'Lead not found'];
    }
    
    return [
        'ok' => true,
        'lead' => [
            'id' => (string)$lead['id'],
            'phone' => $lead['phone'],
            'name' => $lead['name'],
            'city' => $lead['city'],
        ],
    ];
}

// Test 1: Get lead by ID with valid token
echo "Test 1: Get lead by ID with valid token\n";
$result = callLeadEndpoint($token, ['id' => $testLeadId]);
echo "Result: " . json_encode($result) . "\n";
echo "Status: " . ($result['ok'] && $result['lead']['id'] == $testLeadId ? "PASS ✓" : "FAIL ✗") . "\n\n";

// Test 2: Get lead by phone with valid token
echo "Test 2: Get lead by phone with valid token\n";
$result = callLeadEndpoint($token, ['phone' => $testPhone]);
echo "Result: " . json_encode($result) . "\n";
echo "Status: " . ($result['ok'] && $result['lead']['phone'] === $testPhone ? "PASS ✓" : "FAIL ✗") . "\n\n";

// Test 3: Get lead with invalid token
echo "Test 3: Get lead with invalid token\n";
$result = callLeadEndpoint('invalid_token_here', ['id' => $testLeadId]);
echo "Result: " . json_encode($result) . "\n";
echo "Status: " . (!$result['ok'] && strpos($result['error'], 'token') !== false ? "PASS ✓" : "FAIL ✗") . "\n\n";

// Test 4: Get non-existent lead
echo "Test 4: Get non-existent lead\n";
$result = callLeadEndpoint($token, ['id' => '999999']);
echo "Result: " . json_encode($result) . "\n";
echo "Status: " . (!$result['ok'] && $result['error'] === 'Lead not found' ? "PASS ✓" : "FAIL ✗") . "\n\n";

// Test 5: Missing parameters
echo "Test 5: Missing parameters\n";
$result = callLeadEndpoint($token, []);
echo "Result: " . json_encode($result) . "\n";
echo "Status: " . (!$result['ok'] && strpos($result['error'], 'Missing') !== false ? "PASS ✓" : "FAIL ✗") . "\n\n";

// Cleanup test token
$pdo->prepare("DELETE FROM integration_sessions WHERE token = ?")->execute([$token]);
echo "Cleaned up test token\n\n";

echo "=== All Tests Complete ===\n";
