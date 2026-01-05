<?php
/**
 * Integration Job Status Endpoint
 * GET /v1/api/integration/jobs/status.php?jobId=...
 * 
 * Returns job status and module progress.
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

// === Get job ID ===
$jobId = $_GET['jobId'] ?? null;

if (!$jobId) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing jobId parameter']);
    exit;
}

// === Fetch job ===
$pdo = db();

$stmt = $pdo->prepare("
    SELECT id, forge_lead_id, op_lead_id, status, progress, 
           created_at, started_at, finished_at, last_error, correlation_id
    FROM integration_jobs 
    WHERE id = ?
");
$stmt->execute([$jobId]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$job) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Job not found']);
    exit;
}

// === Fetch module runs ===
$stmt = $pdo->prepare("
    SELECT module, status, attempt, started_at, finished_at, error_code, error_message
    FROM integration_job_runs 
    WHERE job_id = ?
    ORDER BY id
");
$stmt->execute([$jobId]);
$runs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Format modules array
$modules = array_map(function($run) {
    return [
        'module' => $run['module'],
        'status' => $run['status'],
        'attempt' => (int) $run['attempt'],
        'error_code' => $run['error_code'],
        'started_at' => $run['started_at'],
        'finished_at' => $run['finished_at']
    ];
}, $runs);

echo json_encode([
    'ok' => true,
    'jobId' => $job['id'],
    'forgeLeadId' => (int) $job['forge_lead_id'],
    'opLeadId' => $job['op_lead_id'],
    'status' => $job['status'],
    'progress' => (int) $job['progress'],
    'modules' => $modules,
    'created_at' => $job['created_at'],
    'started_at' => $job['started_at'],
    'finished_at' => $job['finished_at'],
    'last_error' => $job['last_error'],
    'correlationId' => $job['correlation_id']
]);
