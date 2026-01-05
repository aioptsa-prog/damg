<?php
/**
 * Integration Token Exchange Endpoint
 * POST /v1/api/integration/exchange.php
 * 
 * Receives HMAC-signed assertion from OP-Target and returns a short-lived
 * integration access token for forge APIs.
 * 
 * SECURITY:
 * - Behind INTEGRATION_AUTH_BRIDGE flag
 * - Uses separate INTEGRATION_SHARED_SECRET (not JWT_SECRET)
 * - Nonce-based replay attack prevention
 * - Short-lived tokens (5 minutes)
 * 
 * @since Phase 1
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../bootstrap.php';
require_once __DIR__ . '/../../../lib/flags.php';

// === Feature Flag Check ===
if (!integration_flag('auth_bridge')) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Not found']);
    exit;
}

// === Only POST allowed ===
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// === Get JSON input ===
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!$input || !is_array($input)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON input']);
    exit;
}

// === Validate required fields ===
$requiredFields = ['issuer', 'sub', 'role', 'iat', 'exp', 'nonce', 'sig'];
foreach ($requiredFields as $field) {
    if (!isset($input[$field]) || $input[$field] === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => "Missing required field: $field"]);
        exit;
    }
}

$issuer = (string) $input['issuer'];
$sub = (string) $input['sub'];
$role = (string) $input['role'];
$iat = (int) $input['iat'];
$exp = (int) $input['exp'];
$nonce = (string) $input['nonce'];
$sig = (string) $input['sig'];

// === Validate issuer ===
if ($issuer !== 'op-target') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid issuer']);
    exit;
}

// === Validate role ===
$validRoles = ['SUPER_ADMIN', 'MANAGER', 'SALES_REP'];
if (!in_array($role, $validRoles, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid role']);
    exit;
}

// === Validate time window ===
$now = time();
$clockSkew = 60; // Allow 60 seconds clock skew

if ($iat > $now + $clockSkew) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Token issued in the future']);
    exit;
}

if ($exp < $now - $clockSkew) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Token expired']);
    exit;
}

// Max token lifetime: 5 minutes
if ($exp - $iat > 300) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Token lifetime too long']);
    exit;
}

// === Get integration secret ===
$secret = get_setting('integration_shared_secret', '');
if ($secret === '') {
    error_log('[INTEGRATION] exchange.php: integration_shared_secret not configured');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Integration not configured']);
    exit;
}

// === Verify signature ===
// Canonical JSON: all fields except 'sig', sorted by key
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
    error_log("[INTEGRATION] exchange.php: Invalid signature for sub=$sub");
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Invalid signature']);
    exit;
}

// === Check nonce for replay attack ===
$pdo = db();

// First, cleanup expired nonces (older than 10 minutes)
$cleanupTime = date('Y-m-d H:i:s', $now - 600);
$pdo->prepare("DELETE FROM integration_nonces WHERE expires_at < ?")->execute([$cleanupTime]);

// Check if nonce already used
$stmt = $pdo->prepare("SELECT 1 FROM integration_nonces WHERE nonce = ?");
$stmt->execute([$nonce]);
if ($stmt->fetch()) {
    error_log("[INTEGRATION] exchange.php: Replay attack detected, nonce=$nonce");
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Nonce already used']);
    exit;
}

// Store nonce
$nonceExpires = date('Y-m-d H:i:s', $now + 600); // Keep for 10 minutes
$stmt = $pdo->prepare("INSERT INTO integration_nonces (nonce, issuer, sub, expires_at) VALUES (?, ?, ?, ?)");
$stmt->execute([$nonce, $issuer, $sub, $nonceExpires]);

// === Map OP-Target role to forge role ===
$roleMap = [
    'SUPER_ADMIN' => 'admin',
    'MANAGER' => 'admin',
    'SALES_REP' => 'agent',
];
$forgeRole = $roleMap[$role] ?? 'agent';

// === Generate integration token ===
$token = bin2hex(random_bytes(32));
$tokenExpires = date('Y-m-d H:i:s', $now + 300); // 5 minutes

// Store in integration_sessions
$metadata = json_encode([
    'original_role' => $role,
    'issuer' => $issuer,
]);
$stmt = $pdo->prepare("INSERT INTO integration_sessions (token, op_target_user_id, forge_role, expires_at, metadata) VALUES (?, ?, ?, ?, ?)");
$stmt->execute([$token, $sub, $forgeRole, $tokenExpires, $metadata]);

// === Log successful exchange ===
error_log("[INTEGRATION] exchange.php: Token issued for sub=$sub, role=$role->$forgeRole, expires=$tokenExpires");

// === Return token ===
echo json_encode([
    'ok' => true,
    'token' => $token,
    'expires_in' => 300,
    'forge_role' => $forgeRole,
]);
