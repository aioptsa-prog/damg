<?php
/**
 * Integration Lead Snapshot Endpoint
 * GET /v1/api/integration/leads/snapshot.php?forgeLeadId=123
 * 
 * Returns the latest snapshot for a lead.
 * 
 * @since Phase 6
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../../bootstrap.php';
require_once __DIR__ . '/../../../../lib/flags.php';
require_once __DIR__ . '/../../../../lib/integration_auth.php';

// === Feature Flag Check ===
if (!integration_flag('auth_bridge') || !integration_flag('worker_enabled')) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Not found']);
    exit;
}

// === Only GET allowed ===
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// === Validate Integration Token ===
$authResult = validate_integration_token();
if (!$authResult['valid']) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => $authResult['error'] ?? 'Unauthorized']);
    exit;
}

// === Get forge lead ID ===
$forgeLeadId = $_GET['forgeLeadId'] ?? null;

if (!$forgeLeadId) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing forgeLeadId parameter']);
    exit;
}

// === Fetch latest snapshot ===
$pdo = db();

$stmt = $pdo->prepare("
    SELECT id, forge_lead_id, job_id, source, snapshot_json, created_at
    FROM lead_snapshots 
    WHERE forge_lead_id = ?
    ORDER BY created_at DESC
    LIMIT 1
");
$stmt->execute([$forgeLeadId]);
$snapshot = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$snapshot) {
    http_response_code(404);
    echo json_encode([
        'ok' => false, 
        'error' => 'No snapshot found',
        'forgeLeadId' => (int) $forgeLeadId
    ]);
    exit;
}

// Parse snapshot JSON
$snapshotData = json_decode($snapshot['snapshot_json'], true) ?? [];

echo json_encode([
    'ok' => true,
    'forgeLeadId' => (int) $snapshot['forge_lead_id'],
    'snapshot' => $snapshotData,
    'source' => $snapshot['source'],
    'jobId' => $snapshot['job_id'],
    'created_at' => $snapshot['created_at']
]);
