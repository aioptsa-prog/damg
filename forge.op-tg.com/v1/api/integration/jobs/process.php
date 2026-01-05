<?php
/**
 * Integration Job Processor
 * Called by worker to process pending jobs
 * 
 * GET /v1/api/integration/jobs/process.php - Get next job to process
 * POST /v1/api/integration/jobs/process.php - Update job/module status
 * 
 * @since Phase 6
 */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../../bootstrap.php';
require_once __DIR__ . '/../../../../lib/flags.php';

// === Feature Flag Check ===
if (!integration_flag('worker_enabled')) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Not found']);
    exit;
}

// === Validate Worker Secret ===
$workerSecret = $_SERVER['HTTP_X_WORKER_SECRET'] ?? $_SERVER['HTTP_X_INTERNAL_SECRET'] ?? '';
$expectedSecret = getenv('INTERNAL_SECRET') ?: get_setting('internal_secret', '');

if (empty($workerSecret) || $workerSecret !== $expectedSecret) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$pdo = db();

// === GET: Fetch next job to process ===
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $concurrency = (int) get_setting('integration_worker_concurrency', '1');
    
    // Count currently running jobs
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM integration_jobs WHERE status = 'running'");
    $runningCount = (int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
    
    if ($runningCount >= $concurrency) {
        echo json_encode(['ok' => true, 'job' => null, 'reason' => 'concurrency_limit']);
        exit;
    }
    
    // Get next queued job (FIFO)
    $stmt = $pdo->query("
        SELECT id, forge_lead_id, op_lead_id, modules_json, options_json, correlation_id
        FROM integration_jobs 
        WHERE status = 'queued'
        ORDER BY created_at ASC
        LIMIT 1
    ");
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$job) {
        echo json_encode(['ok' => true, 'job' => null, 'reason' => 'no_jobs']);
        exit;
    }
    
    // Lock job (set to running)
    $stmt = $pdo->prepare("
        UPDATE integration_jobs 
        SET status = 'running', started_at = datetime('now')
        WHERE id = ? AND status = 'queued'
    ");
    $stmt->execute([$job['id']]);
    
    if ($stmt->rowCount() === 0) {
        // Race condition - job was taken by another worker
        echo json_encode(['ok' => true, 'job' => null, 'reason' => 'race_condition']);
        exit;
    }
    
    // Get module runs
    $stmt = $pdo->prepare("
        SELECT id, module, status, attempt
        FROM integration_job_runs 
        WHERE job_id = ?
        ORDER BY id
    ");
    $stmt->execute([$job['id']]);
    $modules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get lead data from leads table
    $stmt = $pdo->prepare("SELECT * FROM leads WHERE id = ?");
    $stmt->execute([$job['forge_lead_id']]);
    $lead = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'ok' => true,
        'job' => [
            'id' => $job['id'],
            'forgeLeadId' => (int) $job['forge_lead_id'],
            'opLeadId' => $job['op_lead_id'],
            'modules' => json_decode($job['modules_json'], true),
            'options' => json_decode($job['options_json'], true),
            'correlationId' => $job['correlation_id'],
            'moduleRuns' => $modules,
            'lead' => $lead
        ]
    ]);
    exit;
}

// === POST: Update job/module status ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    
    if (!$input || !is_array($input)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON input']);
        exit;
    }
    
    $action = $input['action'] ?? '';
    $jobId = $input['jobId'] ?? '';
    
    if (!$jobId) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing jobId']);
        exit;
    }
    
    switch ($action) {
        case 'module_start':
            $module = $input['module'] ?? '';
            $stmt = $pdo->prepare("
                UPDATE integration_job_runs 
                SET status = 'running', started_at = datetime('now'), attempt = attempt + 1
                WHERE job_id = ? AND module = ?
            ");
            $stmt->execute([$jobId, $module]);
            echo json_encode(['ok' => true]);
            break;
            
        case 'module_success':
            $module = $input['module'] ?? '';
            $output = $input['output'] ?? [];
            $stmt = $pdo->prepare("
                UPDATE integration_job_runs 
                SET status = 'success', finished_at = datetime('now'), output_json = ?
                WHERE job_id = ? AND module = ?
            ");
            $stmt->execute([json_encode($output), $jobId, $module]);
            
            // Update job progress
            updateJobProgress($pdo, $jobId);
            echo json_encode(['ok' => true]);
            break;
            
        case 'module_failed':
            $module = $input['module'] ?? '';
            $errorCode = $input['errorCode'] ?? 'unknown';
            $errorMessage = $input['errorMessage'] ?? '';
            $stmt = $pdo->prepare("
                UPDATE integration_job_runs 
                SET status = 'failed', finished_at = datetime('now'), error_code = ?, error_message = ?
                WHERE job_id = ? AND module = ?
            ");
            $stmt->execute([$errorCode, $errorMessage, $jobId, $module]);
            
            // Update job progress
            updateJobProgress($pdo, $jobId);
            echo json_encode(['ok' => true]);
            break;
            
        case 'module_skipped':
            $module = $input['module'] ?? '';
            $stmt = $pdo->prepare("
                UPDATE integration_job_runs 
                SET status = 'skipped', finished_at = datetime('now')
                WHERE job_id = ? AND module = ?
            ");
            $stmt->execute([$jobId, $module]);
            
            // Update job progress
            updateJobProgress($pdo, $jobId);
            echo json_encode(['ok' => true]);
            break;
            
        case 'job_complete':
            $snapshot = $input['snapshot'] ?? [];
            $status = $input['status'] ?? 'success'; // success, partial, failed
            $lastError = $input['lastError'] ?? null;
            
            // Update job
            $stmt = $pdo->prepare("
                UPDATE integration_jobs 
                SET status = ?, finished_at = datetime('now'), progress = 100, last_error = ?
                WHERE id = ?
            ");
            $stmt->execute([$status, $lastError, $jobId]);
            
            // Get forge_lead_id
            $stmt = $pdo->prepare("SELECT forge_lead_id FROM integration_jobs WHERE id = ?");
            $stmt->execute([$jobId]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($job && !empty($snapshot)) {
                // Save snapshot
                $snapshotId = bin2hex(random_bytes(16));
                $stmt = $pdo->prepare("
                    INSERT INTO lead_snapshots (id, forge_lead_id, job_id, source, snapshot_json)
                    VALUES (?, ?, ?, 'worker', ?)
                ");
                $stmt->execute([$snapshotId, $job['forge_lead_id'], $jobId, json_encode($snapshot)]);
                
                error_log("[INTEGRATION_JOB] Completed job=$jobId status=$status snapshot_id=$snapshotId");
            }
            
            echo json_encode(['ok' => true, 'status' => $status]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => 'Method not allowed']);

// === Helper Functions ===
function updateJobProgress(PDO $pdo, string $jobId): void {
    // Calculate progress based on completed modules
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status IN ('success', 'failed', 'skipped') THEN 1 ELSE 0 END) as done
        FROM integration_job_runs 
        WHERE job_id = ?
    ");
    $stmt->execute([$jobId]);
    $counts = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $progress = $counts['total'] > 0 
        ? (int) round(($counts['done'] / $counts['total']) * 100)
        : 0;
    
    $stmt = $pdo->prepare("UPDATE integration_jobs SET progress = ? WHERE id = ?");
    $stmt->execute([$progress, $jobId]);
}
