<?php
/**
 * Integration Job Create Endpoint
 * POST /v1/api/integration/jobs/create.php
 * 
 * Creates a new enrichment job for a lead.
 * 
 * Input:
 * {
 *   "opLeadId": "uuid",
 *   "forgeLeadId": 123,
 *   "modules": ["maps", "website"],
 *   "options": {"force": false, "maxDurationSec": 180}
 * }
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

$requestedBy = $authResult['user_id'] ?? 'unknown';

// === Get JSON input ===
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!$input || !is_array($input)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON input']);
    exit;
}

// === Validate required fields ===
$opLeadId = $input['opLeadId'] ?? null;
$forgeLeadId = $input['forgeLeadId'] ?? null;
$modules = $input['modules'] ?? [];
$options = $input['options'] ?? [];

if (!$opLeadId || !$forgeLeadId) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing opLeadId or forgeLeadId']);
    exit;
}

// === Validate modules allowlist ===
$allowedModules = ['maps', 'website'];

// Instagram only if enabled
if (integration_flag('instagram_enabled')) {
    $allowedModules[] = 'instagram';
}

$validModules = array_filter($modules, fn($m) => in_array($m, $allowedModules, true));
if (empty($validModules)) {
    http_response_code(400);
    echo json_encode([
        'ok' => false, 
        'error' => 'No valid modules specified',
        'allowed' => $allowedModules
    ]);
    exit;
}

// === Rate Limit Check (per user per day) ===
$pdo = db();
$maxJobsPerDay = (int) get_setting('integration_worker_max_jobs_per_user_day', '20');

$stmt = $pdo->prepare("
    SELECT COUNT(*) as cnt 
    FROM integration_jobs 
    WHERE requested_by = ? 
    AND created_at > datetime('now', '-1 day')
");
$stmt->execute([$requestedBy]);
$jobCount = (int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];

if ($jobCount >= $maxJobsPerDay) {
    http_response_code(429);
    echo json_encode([
        'ok' => false,
        'error' => 'Rate limit exceeded',
        'limit' => $maxJobsPerDay,
        'used' => $jobCount
    ]);
    exit;
}

// === Check for existing running job (prevent duplicates) ===
$force = (bool) ($options['force'] ?? false);

if (!$force) {
    $stmt = $pdo->prepare("
        SELECT id, status, progress 
        FROM integration_jobs 
        WHERE forge_lead_id = ? 
        AND status IN ('queued', 'running')
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$forgeLeadId]);
    $existingJob = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existingJob) {
        http_response_code(409);
        echo json_encode([
            'ok' => false,
            'error' => 'Job already in progress',
            'existingJobId' => $existingJob['id'],
            'status' => $existingJob['status'],
            'progress' => (int) $existingJob['progress']
        ]);
        exit;
    }
}

// === Generate job ID and correlation ID ===
$jobId = bin2hex(random_bytes(16));
$correlationId = 'job-' . substr($jobId, 0, 8) . '-' . time();

// === Create job ===
try {
    $pdo->beginTransaction();
    
    // Insert job
    $stmt = $pdo->prepare("
        INSERT INTO integration_jobs 
        (id, forge_lead_id, op_lead_id, requested_by, modules_json, options_json, status, progress, correlation_id)
        VALUES (?, ?, ?, ?, ?, ?, 'queued', 0, ?)
    ");
    $stmt->execute([
        $jobId,
        $forgeLeadId,
        $opLeadId,
        $requestedBy,
        json_encode($validModules),
        json_encode($options),
        $correlationId
    ]);
    
    // Insert job runs for each module
    foreach ($validModules as $module) {
        $runId = bin2hex(random_bytes(16));
        $stmt = $pdo->prepare("
            INSERT INTO integration_job_runs 
            (id, job_id, module, status, attempt)
            VALUES (?, ?, ?, 'pending', 0)
        ");
        $stmt->execute([$runId, $jobId, $module]);
    }
    
    $pdo->commit();
    
    // Log (no sensitive data)
    error_log("[INTEGRATION_JOB] Created job=$jobId forge_lead=$forgeLeadId modules=" . implode(',', $validModules) . " correlation=$correlationId");
    
    echo json_encode([
        'ok' => true,
        'jobId' => $jobId,
        'status' => 'queued',
        'modules' => $validModules,
        'correlationId' => $correlationId
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("[INTEGRATION_JOB] Error creating job: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to create job']);
}
