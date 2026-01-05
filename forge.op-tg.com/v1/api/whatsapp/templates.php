<?php
/**
 * WhatsApp Templates API
 * GET: جلب قوالب الرسائل
 * POST: إنشاء قالب جديد
 * PUT: تعديل قالب
 * DELETE: حذف قالب
 */

// CORS headers FIRST
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

// Handle OPTIONS immediately
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Load minimal bootstrap
require_once __DIR__ . '/bootstrap.php';

// التحقق من المصادقة (يدعم كلا من admin و public users)
$user = require_whatsapp_auth();

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $stmt = $pdo->prepare("
            SELECT * FROM whatsapp_templates 
            WHERE user_id = ? OR user_id = 0
            ORDER BY is_default DESC, created_at DESC
        ");
        $stmt->execute([$user['id']]);
        $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['ok' => true, 'templates' => $templates], JSON_UNESCAPED_UNICODE);

    } elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);

        $name = $input['name'] ?? '';
        $content_type = $input['content_type'] ?? 'text';
        $message_text = $input['message_text'] ?? '';
        $media_url = $input['media_url'] ?? '';
        $is_default = $input['is_default'] ?? 0;

        if (empty($name)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'اسم القالب مطلوب'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($is_default) {
            $pdo->prepare("UPDATE whatsapp_templates SET is_default = 0 WHERE user_id = ?")->execute([$user['id']]);
        }

        $stmt = $pdo->prepare("
            INSERT INTO whatsapp_templates (user_id, name, content_type, message_text, media_url, is_default)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$user['id'], $name, $content_type, $message_text, $media_url, $is_default ? 1 : 0]);

        echo json_encode(['ok' => true, 'message' => 'تم إنشاء القالب', 'id' => $pdo->lastInsertId()], JSON_UNESCAPED_UNICODE);

    } elseif ($method === 'PUT') {
        $input = json_decode(file_get_contents('php://input'), true);

        $id = $input['id'] ?? null;
        if (!$id) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'معرف القالب مطلوب'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id FROM whatsapp_templates WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user['id']]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'لا يمكنك تعديل هذا القالب'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $name = $input['name'] ?? '';
        $content_type = $input['content_type'] ?? 'text';
        $message_text = $input['message_text'] ?? '';
        $media_url = $input['media_url'] ?? '';
        $is_default = $input['is_default'] ?? 0;

        if ($is_default) {
            $pdo->prepare("UPDATE whatsapp_templates SET is_default = 0 WHERE user_id = ?")->execute([$user['id']]);
        }

        $stmt = $pdo->prepare("
            UPDATE whatsapp_templates 
            SET name = ?, content_type = ?, message_text = ?, media_url = ?, is_default = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$name, $content_type, $message_text, $media_url, $is_default ? 1 : 0, $id]);

        echo json_encode(['ok' => true, 'message' => 'تم تحديث القالب'], JSON_UNESCAPED_UNICODE);

    } elseif ($method === 'DELETE') {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'معرف القالب مطلوب'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id FROM whatsapp_templates WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $user['id']]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'لا يمكنك حذف هذا القالب'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $pdo->prepare("DELETE FROM whatsapp_templates WHERE id = ?")->execute([$id]);
        echo json_encode(['ok' => true, 'message' => 'تم حذف القالب'], JSON_UNESCAPED_UNICODE);

    } else {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    }

} catch (Exception $e) {
    error_log("WhatsApp Templates Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
