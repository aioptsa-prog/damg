<?php
/**
 * Metrics Dashboard API
 * GET /v1/api/metrics.php
 * 
 * Returns system metrics for monitoring:
 * - Jobs per hour
 * - Failure rate
 * - Average time per provider
 * - Queue depth
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../lib/api_auth.php';

// Require at least supervisor role
$user = require_min_role('supervisor');

$pdo = db();
$metrics = [
    'timestamp' => date('c'),
    'period' => '24h',
];

// 1. Jobs per hour (last 24h)
$stmt = $pdo->query("
    SELECT 
        strftime('%Y-%m-%d %H:00', created_at) as hour,
        COUNT(*) as count
    FROM internal_jobs
    WHERE created_at > datetime('now', '-24 hours')
    GROUP BY hour
    ORDER BY hour DESC
    LIMIT 24
");
$metrics['jobs_per_hour'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Job status breakdown
$stmt = $pdo->query("
    SELECT 
        status,
        COUNT(*) as count
    FROM internal_jobs
    WHERE created_at > datetime('now', '-24 hours')
    GROUP BY status
");
$statusCounts = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $statusCounts[$row['status']] = (int)$row['count'];
}
$metrics['job_status'] = $statusCounts;

// 3. Failure rate
$total = array_sum($statusCounts);
$failed = $statusCounts['failed'] ?? 0;
$metrics['failure_rate'] = $total > 0 ? round($failed / $total * 100, 2) : 0;

// 4. Average job duration (for completed jobs)
$stmt = $pdo->query("
    SELECT 
        AVG((julianday(finished_at) - julianday(claimed_at)) * 86400) as avg_duration_sec
    FROM internal_jobs
    WHERE status = 'done'
    AND finished_at IS NOT NULL
    AND claimed_at IS NOT NULL
    AND created_at > datetime('now', '-24 hours')
");
$avgDuration = $stmt->fetchColumn();
$metrics['avg_job_duration_sec'] = $avgDuration ? round($avgDuration, 2) : null;

// 5. Queue depth (pending jobs)
$stmt = $pdo->query("SELECT COUNT(*) FROM internal_jobs WHERE status = 'queued'");
$metrics['queue_depth'] = (int)$stmt->fetchColumn();

// 6. Active workers (jobs in processing)
$stmt = $pdo->query("
    SELECT COUNT(DISTINCT worker_id) 
    FROM internal_jobs 
    WHERE status = 'processing'
");
$metrics['active_workers'] = (int)$stmt->fetchColumn();

// 7. Leads added (last 24h)
$stmt = $pdo->query("
    SELECT COUNT(*) 
    FROM leads 
    WHERE created_at > datetime('now', '-24 hours')
");
$metrics['leads_added_24h'] = (int)$stmt->fetchColumn();

// 8. WhatsApp messages (last 24h)
try {
    $stmt = $pdo->query("
        SELECT COUNT(*) 
        FROM whatsapp_logs 
        WHERE created_at > datetime('now', '-24 hours')
    ");
    $metrics['whatsapp_sent_24h'] = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    $metrics['whatsapp_sent_24h'] = null;
}

// 9. API usage (from usage_counters)
$stmt = $pdo->query("
    SELECT kind, SUM(count) as total
    FROM usage_counters
    WHERE day >= date('now', '-7 days')
    GROUP BY kind
");
$metrics['api_usage_7d'] = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $metrics['api_usage_7d'][$row['kind']] = (int)$row['total'];
}

// 10. Rate limit hits (from rate_limits table)
try {
    $stmt = $pdo->query("
        SELECT COUNT(*) 
        FROM rate_limits 
        WHERE count >= 30
        AND window_start > datetime('now', '-24 hours')
    ");
    $metrics['rate_limit_hits_24h'] = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    $metrics['rate_limit_hits_24h'] = null;
}

echo json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
