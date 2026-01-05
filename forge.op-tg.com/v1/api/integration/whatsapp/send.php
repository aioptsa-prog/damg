<?php
/**
 * Integration WhatsApp Send Endpoint
 * POST /v1/api/integration/whatsapp/send.php
 * 
 * Sends WhatsApp message via integration token (from OP-Target).
 * Uses the existing Washeej provider.
 * 
 * SECURITY:
 * - CORS Allowlist
 * - Behind INTEGRATION_AUTH_BRIDGE flag
 * - Requires valid integration token
 * - Rate limited (10 messages per minute per token)
 * - No sensitive data in logs
 * 
 * @since Phase 4
 */

declare(strict_types=1);

// ============================================
// CORS Allowlist (Critical Security Fix)
// ============================================
$allowedOriginsEnv = getenv('ALLOWED_ORIGINS') ?: 'http://localhost:3000';
$allowedOrigins = array_map('trim', explode(',', $allowedOriginsEnv));

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($origin && in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header("Vary: Origin");
    header("Access-Control-Allow-Methods: POST, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Integration-Token");
} else if ($origin) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'CORS: origin not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// Preflight
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../../../../bootstrap.php';
require_once __DIR__ . '/../../../../lib/flags.php';
require_once __DIR__ . '/../../../../lib/integration_auth.php';

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

// === Verify integration token ===
$integration = verify_integration_token();
if (!$integration) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Invalid or expired integration token']);
    exit;
}

// === Rate limiting (simple in-memory check via DB) ===
$pdo = db();
$rateLimitKey = 'integration_wa_' . substr(hash('sha256', $integration['op_target_user_id']), 0, 16);
$rateLimitWindow = 60; // 1 minute
$rateLimitMax = 10; // 10 messages per minute

// Check rate limit
$stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM audit_logs WHERE actor_user_id = ? AND action = 'integration_whatsapp_send' AND created_at > datetime('now', '-60 seconds')");
$stmt->execute([$rateLimitKey]);
$rateCount = (int)$stmt->fetch(PDO::FETCH_ASSOC)['cnt'];

if ($rateCount >= $rateLimitMax) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Rate limit exceeded', 'retry_after' => 60]);
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
$phone = trim((string)($input['phone'] ?? ''));
$message = trim((string)($input['message'] ?? ''));
$dryRun = (bool)($input['dry_run'] ?? false);

if ($phone === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing phone number']);
    exit;
}

if ($message === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing message']);
    exit;
}

// === Normalize phone number ===
$phoneNorm = preg_replace('/[^0-9]/', '', $phone);
if (strlen($phoneNorm) === 10 && $phoneNorm[0] === '0') {
    $phoneNorm = '966' . substr($phoneNorm, 1);
} elseif (strlen($phoneNorm) === 9) {
    $phoneNorm = '966' . $phoneNorm;
}

// === Get WhatsApp settings (use system default for integration) ===
$settings = null;

// First try to get integration-specific settings
$stmt = $pdo->prepare("SELECT value FROM settings WHERE key = 'integration_whatsapp_settings'");
$stmt->execute();
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if ($row && $row['value']) {
    $settings = json_decode($row['value'], true);
}

// Fallback to first active user settings
if (!$settings || empty($settings['auth_token'])) {
    $stmt = $pdo->prepare("SELECT * FROM whatsapp_settings WHERE is_active = 1 LIMIT 1");
    $stmt->execute();
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$settings || empty($settings['auth_token']) || empty($settings['sender_number'])) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'WhatsApp not configured']);
    exit;
}

// === Dry run mode ===
if ($dryRun) {
    // Log the dry run
    $stmt = $pdo->prepare("INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, after, created_at) VALUES (?, ?, ?, ?, ?, datetime('now'))");
    $stmt->execute([
        $rateLimitKey,
        'integration_whatsapp_send',
        'whatsapp_dry_run',
        $phoneNorm,
        json_encode(['dry_run' => true, 'message_length' => strlen($message)])
    ]);
    
    echo json_encode([
        'ok' => true,
        'dry_run' => true,
        'would_send_to' => $phoneNorm,
        'message_length' => strlen($message),
    ]);
    exit;
}

// === Send via Washeej API ===
$payload = [
    'requestType' => 'POST',
    'token' => $settings['auth_token'],
    'from' => $settings['sender_number'],
    'to' => $phoneNorm,
    'messageType' => 'text',
    'text' => $message,
];

$apiUrl = 'https://api.washeej.sa/v1/send';

$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $settings['auth_token']
        ],
        'content' => json_encode($payload),
        'timeout' => 30,
        'ignore_errors' => true
    ]
]);

$response = @file_get_contents($apiUrl, false, $context);
$httpCode = 0;

// Parse response headers for status code
if (isset($http_response_header)) {
    foreach ($http_response_header as $header) {
        if (preg_match('/HTTP\/\d\.\d\s+(\d+)/', $header, $matches)) {
            $httpCode = (int)$matches[1];
        }
    }
}

$responseData = $response ? json_decode($response, true) : null;
$success = $httpCode >= 200 && $httpCode < 300;

// === Log to audit (no sensitive data) ===
$messageHash = hash('sha256', $message);
$stmt = $pdo->prepare("INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, after, created_at) VALUES (?, ?, ?, ?, ?, datetime('now'))");
$stmt->execute([
    $rateLimitKey,
    'integration_whatsapp_send',
    'whatsapp_message',
    $phoneNorm,
    json_encode([
        'success' => $success,
        'http_code' => $httpCode,
        'message_hash' => substr($messageHash, 0, 16),
        'op_target_user' => $integration['op_target_user_id'],
    ])
]);

// === Return response ===
if ($success) {
    error_log("[INTEGRATION] whatsapp/send: Message sent to $phoneNorm by " . $integration['op_target_user_id']);
    echo json_encode([
        'ok' => true,
        'sent_to' => $phoneNorm,
        'message_id' => $responseData['messageId'] ?? null,
        'provider_response' => $responseData,
    ]);
} else {
    error_log("[INTEGRATION] whatsapp/send: Failed to send to $phoneNorm, HTTP $httpCode");
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'error' => 'Failed to send message',
        'http_code' => $httpCode,
        'provider_error' => $responseData['error'] ?? $responseData['message'] ?? null,
    ]);
}
