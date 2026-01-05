<?php
/**
 * Change Password API
 * POST - تغيير كلمة المرور
 * Security: CORS Allowlist
 */

require_once __DIR__ . '/../../../lib/cors.php';
handle_cors(['POST', 'OPTIONS']);
header('Content-Type: application/json');

require_once __DIR__ . '/../bootstrap_api.php';
require_once __DIR__ . '/../../../lib/auth.php';
require_once __DIR__ . '/../../../lib/public_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED']);
    exit;
}

// Get user (either admin or public user)
$user = current_user();
$isPublicUser = false;
if (!$user) {
    $user = current_public_user();
    $isPublicUser = true;
}

if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'UNAUTHORIZED']);
    exit;
}

try {
    $pdo = db();
    $input = json_decode(file_get_contents('php://input'), true);

    $currentPassword = $input['current_password'] ?? '';
    $newPassword = $input['new_password'] ?? '';
    $confirmPassword = $input['confirm_password'] ?? '';

    // Validation
    if (empty($currentPassword) || empty($newPassword)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'كلمة المرور الحالية والجديدة مطلوبة'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($newPassword !== $confirmPassword) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'كلمة المرور الجديدة غير متطابقة'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (strlen($newPassword) < 6) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'كلمة المرور يجب أن تكون 6 أحرف على الأقل'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Get current password hash
    $table = $isPublicUser ? 'public_users' : 'users';
    $stmt = $pdo->prepare("SELECT password FROM $table WHERE id = ?");
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'المستخدم غير موجود'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Verify current password
    if (!password_verify($currentPassword, $row['password'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'كلمة المرور الحالية غير صحيحة'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Update password
    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE $table SET password = ?, updated_at = datetime('now') WHERE id = ?");
    $stmt->execute([$newHash, $user['id']]);

    echo json_encode([
        'ok' => true,
        'message' => 'تم تغيير كلمة المرور بنجاح'
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("Change Password Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
