<?php
/**
 * Integration Job Cancel Endpoint
 * POST /v1/api/integration/jobs/cancel.php
 * 
 * Cancels a running or queued job.
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

// === Only POST allowed ===
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

// === Get JSON input ===
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

$jobId = $input['jobId'] ?? $_GET['jobId'] ?? null;

if (!$jobId) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing jobId']);
    exit;
}

// === Update job status ===
$pdo = db();

$stmt = $pdo->prepare("
    UPDATE integration_jobs 
    SET status = 'cancelled', finished_at = datetime('now')
    WHERE id = ? AND status IN ('queued', 'running')
");
$stmt->execute([$jobId]);

if ($stmt->rowCount() === 0) {
    // Check if job exists
    $check = $pdo->prepare("SELECT status FROM integration_jobs WHERE id = ?");
    $check->execute([$jobId]);
    $job = $check->fetch(PDO::FETCH_ASSOC);
    
    if (!$job) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Job not found']);
        exit;
    }
    
    echo json_encode([
        'ok' => false,
        'error' => 'Job cannot be cancelled',
        'currentStatus' => $job['status']
    ]);
    exit;
}

// Cancel pending module runs
$stmt = $pdo->prepare("
    UPDATE integration_job_runs 
    SET status = 'skipped', finished_at = datetime('now')
    WHERE job_id = ? AND status IN ('pending', 'running')
");
$stmt->execute([$jobId]);

error_log("[INTEGRATION_JOB] Cancelled job=$jobId");

echo json_encode([
    'ok' => true,
    'jobId' => $jobId,
    'status' => 'cancelled'
]);
