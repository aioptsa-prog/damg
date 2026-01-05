<?php
/**
 * Temporary simplified pull_job for testing
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/system.php';
header('Content-Type: application/json; charset=utf-8');

$hdr = function (string $name) {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return $_SERVER[$key] ?? null;
};

try {
    // Simple auth check
    $secretHeader = $hdr('X-Internal-Secret') ?? '';
    $workerId = $hdr('X-Worker-Id') ?? 'unknown';

    $internalEnabled = get_setting('internal_server_enabled', '0') === '1';
    $internalSecret = get_setting('internal_secret', '');

    if (!$internalEnabled) {
        http_response_code(403);
        echo json_encode(['error' => 'internal_disabled']);
        exit;
    }

    // SIMPLIFIED: Just check the secret header
    if ($secretHeader !== $internalSecret) {
        http_response_code(401);
        echo json_encode(['error' => 'unauthorized', 'debug' => 'Secret mismatch']);
        exit;
    }

    // Update worker presence
    try {
        $pdo = db();
        $stmt = $pdo->prepare("
            INSERT INTO internal_workers (worker_id, last_seen, status) 
            VALUES (?, datetime('now'), 'pulling')
            ON CONFLICT(worker_id) DO UPDATE SET 
                last_seen=datetime('now'), 
                status='pulling'
        ");
        $stmt->execute([$workerId]);
    } catch (Throwable $e) {
    }

    // Check if system is stopped
    if (system_is_globally_stopped() || system_is_in_pause_window()) {
        echo json_encode(['job' => null, 'stopped' => true]);
        exit;
    }

    // Get database
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Pull a job
    $leaseReq = (int) ($_GET['lease_sec'] ?? 180);
    $leaseSec = max(30, min(600, $leaseReq));
    $now = date('Y-m-d H:i:s');
    $leaseUntil = date('Y-m-d H:i:s', time() + $leaseSec);

    $pdo->beginTransaction();

    // Find a queued job
    $stmt = $pdo->prepare("
        SELECT id FROM internal_jobs 
        WHERE status='queued' 
        ORDER BY created_at ASC 
        LIMIT 1
    ");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $pdo->commit();
        echo json_encode(['job' => null]);
        exit;
    }

    $jobId = (int) $row['id'];
    $attemptId = bin2hex(random_bytes(8));

    // Claim the job
    $upd = $pdo->prepare("
        UPDATE internal_jobs 
        SET status='processing',
            worker_id=?,
            attempt_id=?,
            claimed_at=?,
            updated_at=?,
            attempts=COALESCE(attempts,0)+1,
            lease_expires_at=?
        WHERE id=? AND status='queued'
    ");

    $upd->execute([$workerId, $attemptId, $now, $now, $leaseUntil, $jobId]);

    if ($upd->rowCount() === 0) {
        $pdo->commit();
        echo json_encode(['job' => null]);
        exit;
    }

    // Get the job details
    $sel = $pdo->prepare("
        SELECT id, query, ll, role, agent_id, last_cursor, radius_km, 
               lang, region, target_count, progress_count, result_count, 
               attempt_id, category_id
        FROM internal_jobs 
        WHERE id=? AND status='processing' AND worker_id=?
        LIMIT 1
    ");

    $sel->execute([$jobId, $workerId]);
    $job = $sel->fetch(PDO::FETCH_ASSOC);

    $pdo->commit();

    if (!$job) {
        echo json_encode(['job' => null]);
        exit;
    }

    // Return the job
    echo json_encode([
        'job' => [
            'id' => (int) $job['id'],
            'query' => $job['query'],
            'll' => $job['ll'],
            'role' => $job['role'] ?? null,
            'agent_id' => isset($job['agent_id']) ? (int) $job['agent_id'] : null,
            'last_cursor' => isset($job['last_cursor']) ? (int) $job['last_cursor'] : 0,
            'radius_km' => isset($job['radius_km']) ? (int) $job['radius_km'] : 0,
            'lang' => $job['lang'] ?? 'ar',
            'region' => $job['region'] ?? 'sa',
            'target_count' => isset($job['target_count']) ? (int) $job['target_count'] : null,
            'progress_count' => isset($job['progress_count']) ? (int) $job['progress_count'] : 0,
            'result_count' => isset($job['result_count']) ? (int) $job['result_count'] : 0,
            'attempt_id' => $job['attempt_id'] ?? $attemptId,
            'category_id' => isset($job['category_id']) ? (int) $job['category_id'] : null
        ],
        'lease_expires_at' => $leaseUntil,
        'lease_sec' => $leaseSec
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction())
        $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['error' => 'server_error', 'message' => $e->getMessage()]);
}
