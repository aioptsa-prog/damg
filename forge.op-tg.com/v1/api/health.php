<?php
/**
 * Health Check Endpoint
 * GET /v1/api/health.php
 * 
 * Returns system health status including:
 * - Database connectivity
 * - Worker queue status
 * - Version info
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

$startTime = microtime(true);

$health = [
    'status' => 'healthy',
    'timestamp' => date('c'),
    'version' => '1.0.0',
    'checks' => [],
];

// 1. Database Check
try {
    require_once __DIR__ . '/../../bootstrap.php';
    $pdo = db();
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM sqlite_master WHERE type='table'");
    $tableCount = $stmt->fetchColumn();
    
    $health['checks']['database'] = [
        'status' => 'healthy',
        'type' => 'sqlite',
        'tables' => (int)$tableCount,
        'latency_ms' => round((microtime(true) - $startTime) * 1000, 2),
    ];
} catch (Exception $e) {
    $health['status'] = 'unhealthy';
    $health['checks']['database'] = [
        'status' => 'unhealthy',
        'error' => 'Database connection failed',
    ];
}

// 2. Worker Queue Status
try {
    $queueStart = microtime(true);
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) FILTER (WHERE status = 'queued') as queued,
            COUNT(*) FILTER (WHERE status = 'processing') as processing,
            COUNT(*) FILTER (WHERE status = 'done') as done,
            COUNT(*) FILTER (WHERE status = 'failed') as failed
        FROM internal_jobs
        WHERE created_at > datetime('now', '-24 hours')
    ");
    $queue = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$queue) {
        // SQLite doesn't support FILTER, use alternative
        $stmt = $pdo->query("
            SELECT 
                SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) as queued,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) as done,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
            FROM internal_jobs
            WHERE created_at > datetime('now', '-24 hours')
        ");
        $queue = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    $health['checks']['worker_queue'] = [
        'status' => 'healthy',
        'jobs_24h' => [
            'queued' => (int)($queue['queued'] ?? 0),
            'processing' => (int)($queue['processing'] ?? 0),
            'done' => (int)($queue['done'] ?? 0),
            'failed' => (int)($queue['failed'] ?? 0),
        ],
        'latency_ms' => round((microtime(true) - $queueStart) * 1000, 2),
    ];
    
    // Mark unhealthy if too many failed jobs
    $failedRatio = ($queue['failed'] ?? 0) / max(1, array_sum(array_map('intval', $queue ?: [])));
    if ($failedRatio > 0.5) {
        $health['checks']['worker_queue']['status'] = 'degraded';
        $health['checks']['worker_queue']['warning'] = 'High failure rate';
    }
} catch (Exception $e) {
    $health['checks']['worker_queue'] = [
        'status' => 'unknown',
        'error' => 'Could not query queue',
    ];
}

// 3. Disk Space (for SQLite)
try {
    $dbPath = realpath(__DIR__ . '/../../data/forge.sqlite');
    if ($dbPath && file_exists($dbPath)) {
        $dbSize = filesize($dbPath);
        $freeSpace = disk_free_space(dirname($dbPath));
        
        $health['checks']['disk'] = [
            'status' => $freeSpace > 100 * 1024 * 1024 ? 'healthy' : 'warning',
            'db_size_mb' => round($dbSize / 1024 / 1024, 2),
            'free_space_mb' => round($freeSpace / 1024 / 1024, 2),
        ];
    }
} catch (Exception $e) {
    // Ignore disk check errors
}

// 4. Feature Flags Status
try {
    require_once __DIR__ . '/../../lib/flags.php';
    $flags = integration_flags_all();
    $health['checks']['feature_flags'] = [
        'status' => 'healthy',
        'enabled' => array_keys(array_filter($flags)),
    ];
    // Add flags at root level for frontend compatibility (map lowercase to UPPERCASE)
    $health['flags'] = [
        'UNIFIED_LEAD_VIEW' => $flags['unified_lead_view'] ?? false,
        'AUTH_BRIDGE' => $flags['auth_bridge'] ?? false,
        'SURVEY_FROM_LEAD' => $flags['survey_from_lead'] ?? false,
        'SEND_FROM_REPORT' => $flags['send_from_report'] ?? false,
        'WORKER_ENRICH' => $flags['worker_enabled'] ?? false,
    ];
} catch (Exception $e) {
    // Provide default flags on error
    $health['flags'] = [
        'UNIFIED_LEAD_VIEW' => false,
        'AUTH_BRIDGE' => false,
        'SURVEY_FROM_LEAD' => false,
        'SEND_FROM_REPORT' => false,
        'WORKER_ENRICH' => false,
    ];
}

// Calculate total latency
$health['latency_ms'] = round((microtime(true) - $startTime) * 1000, 2);

// Set HTTP status based on health
if ($health['status'] === 'unhealthy') {
    http_response_code(503);
} else {
    http_response_code(200);
}

echo json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
