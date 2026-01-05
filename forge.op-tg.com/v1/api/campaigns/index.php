<?php
/**
 * Campaigns API - List User Campaigns
 * GET /v1/api/campaigns/index.php
 */

require_once __DIR__ . '/../bootstrap_api.php';
require_once __DIR__ . '/../../../lib/auth.php';

header('Content-Type: application/json');

// Require authentication (admin/agent auth)
$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'UNAUTHORIZED']);
    exit;
}

try {
    $pdo = db();

    // Get user's campaigns with linked job data
    $stmt = $pdo->prepare("
        SELECT 
            uc.id,
            uc.name,
            uc.description,
            uc.query,
            uc.city,
            uc.target_count,
            COALESCE(ij.result_count, uc.result_count, 0) as result_count,
            COALESCE(ij.progress_count, 0) as progress_count,
            CASE 
                WHEN ij.status = 'done' THEN 'completed'
                WHEN ij.status = 'error' THEN 'failed'
                WHEN ij.status IN ('queued', 'processing') THEN 'processing'
                ELSE uc.status 
            END as status,
            uc.created_at,
            uc.started_at,
            uc.completed_at,
            ij.id as job_id,
            ij.status as job_status,
            CASE 
                WHEN uc.target_count > 0 THEN ROUND((COALESCE(ij.result_count, uc.result_count, 0) * 100.0) / uc.target_count, 1)
                ELSE 0 
            END as progress_percent
        FROM user_campaigns uc
        LEFT JOIN internal_jobs ij ON uc.internal_job_id = ij.id
        WHERE uc.user_id = ?
        ORDER BY uc.created_at DESC
    ");
    $stmt->execute([$user['id']]);
    $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format response
    $result = [];
    foreach ($campaigns as $c) {
        $result[] = [
            'id' => (int) $c['id'],
            'name' => $c['name'],
            'description' => $c['description'],
            'query' => $c['query'],
            'city' => $c['city'],
            'target_count' => (int) $c['target_count'],
            'result_count' => (int) $c['result_count'],
            'progress_percent' => (float) $c['progress_percent'],
            'status' => $c['status'],
            'created_at' => $c['created_at'],
            'started_at' => $c['started_at'],
            'completed_at' => $c['completed_at']
        ];
    }

    echo json_encode([
        'ok' => true,
        'campaigns' => $result,
        'total' => count($result)
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'SERVER_ERROR', 'message' => $e->getMessage()]);
}
