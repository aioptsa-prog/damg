<?php
/**
 * Job Status Check API
 * GET /api/job_status.php?job_id=123
 * 
 * Returns the current status of a job so worker can check if it should stop
 */

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

// Get job_id
$job_id = isset($_GET['job_id']) ? (int) $_GET['job_id'] : 0;

if (!$job_id) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'job_id required']);
    exit;
}

try {
    $pdo = db();

    $stmt = $pdo->prepare("SELECT id, status FROM internal_jobs WHERE id = ?");
    $stmt->execute([$job_id]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$job) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'job_not_found']);
        exit;
    }

    // Check if job is paused, cancelled or failed
    $should_stop = in_array($job['status'], ['paused', 'cancelled', 'failed', 'done']);

    echo json_encode([
        'ok' => true,
        'job_id' => (int) $job['id'],
        'status' => $job['status'],
        'should_stop' => $should_stop
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
}
