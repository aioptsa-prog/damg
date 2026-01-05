<?php
/**
 * Campaigns API - Delete Campaign
 * POST /v1/api/campaigns/delete.php
 */

require_once __DIR__ . '/../bootstrap_api.php';
require_once __DIR__ . '/../../../lib/auth.php';

header('Content-Type: application/json');

// Require authentication
$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'UNAUTHORIZED']);
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED']);
    exit;
}

// Parse JSON input
$input = json_decode(file_get_contents('php://input'), true);

$campaign_id = (int) ($input['campaign_id'] ?? 0);

if (!$campaign_id) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'INVALID_REQUEST', 'message' => 'معرف الحملة مطلوب']);
    exit;
}

try {
    $pdo = db();

    // Verify campaign belongs to user
    $stmt = $pdo->prepare("SELECT * FROM user_campaigns WHERE id = ? AND user_id = ?");
    $stmt->execute([$campaign_id, $user['id']]);
    $campaign = $stmt->fetch();

    if (!$campaign) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'NOT_FOUND', 'message' => 'الحملة غير موجودة']);
        exit;
    }

    // Delete associated job if exists
    if ($campaign['internal_job_id']) {
        $pdo->prepare("DELETE FROM internal_jobs WHERE id = ?")->execute([$campaign['internal_job_id']]);
    }

    // Delete campaign
    $pdo->prepare("DELETE FROM user_campaigns WHERE id = ?")->execute([$campaign_id]);

    echo json_encode([
        'ok' => true,
        'message' => 'تم حذف الحملة بنجاح'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'SERVER_ERROR', 'message' => $e->getMessage()]);
}
