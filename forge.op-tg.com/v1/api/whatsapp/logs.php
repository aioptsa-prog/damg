<?php
/**
 * WhatsApp Logs API
 * Security: CORS Allowlist
 */

require_once __DIR__ . '/../../../lib/cors.php';
handle_cors(['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS']);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/bootstrap.php';

$user = require_whatsapp_auth();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(100, max(10, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $where = "user_id = ?";
    $params = [$user['id']];

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM whatsapp_logs WHERE $where");
    $countStmt->execute($params);
    $total = $countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT l.*, t.name as template_name
        FROM whatsapp_logs l
        LEFT JOIN whatsapp_templates t ON l.template_id = t.id
        WHERE l.$where
        ORDER BY l.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $statsStmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
        FROM whatsapp_logs WHERE user_id = ?
    ");
    $statsStmt->execute([$user['id']]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'logs' => $logs,
        'pagination' => ['page' => $page, 'limit' => $limit, 'total' => (int) $total, 'pages' => ceil($total / $limit)],
        'stats' => [
            'total' => (int) ($stats['total'] ?? 0),
            'sent' => (int) ($stats['sent'] ?? 0),
            'failed' => (int) ($stats['failed'] ?? 0),
            'success_rate' => ($stats['total'] ?? 0) > 0 ? round((($stats['sent'] ?? 0) / $stats['total']) * 100, 1) : 0
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("WhatsApp Logs Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
