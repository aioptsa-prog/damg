<?php
/**
 * WhatsApp Queue Processor
 * يعالج رسالة واحدة من القائمة في كل استدعاء
 * يُستدعى بشكل دوري من Frontend (كل 5 ثوانٍ)
 */

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/bootstrap.php';

$user = require_whatsapp_auth();
$pdo = db();

try {
    // جلب إعدادات الواتساب
    $stmt = $pdo->prepare("SELECT * FROM whatsapp_settings WHERE user_id = ? AND is_active = 1");
    $stmt->execute([$user['id']]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$settings || empty($settings['auth_token']) || empty($settings['sender_number'])) {
        echo json_encode([
            'ok' => false,
            'error' => 'إعدادات الواتساب غير مكتملة',
            'processed' => 0
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // جلب رسالة معلقة واحدة من حملات المستخدم
    $stmt = $pdo->prepare("
        SELECT q.*, c.user_id 
        FROM whatsapp_queue q
        INNER JOIN whatsapp_bulk_campaigns c ON q.campaign_id = c.id
        WHERE c.user_id = ? 
          AND c.status = 'processing'
          AND q.status = 'pending'
        ORDER BY q.id ASC
        LIMIT 1
    ");
    $stmt->execute([$user['id']]);
    $message = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$message) {
        // لا توجد رسائل معلقة
        echo json_encode([
            'ok' => true,
            'processed' => 0,
            'message' => 'لا توجد رسائل معلقة'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // تحديث عدد المحاولات
    $pdo->prepare("UPDATE whatsapp_queue SET attempts = attempts + 1 WHERE id = ?")->execute([$message['id']]);

    // إرسال الرسالة
    $payload = [
        'requestType' => 'POST',
        'token' => $settings['auth_token'],
        'from' => $settings['sender_number'],
        'to' => $message['recipient_number'],
        'messageType' => 'text',
        'text' => $message['message_text']
    ];

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $settings['auth_token']
            ],
            'content' => json_encode($payload),
            'timeout' => 30,
            'ignore_errors' => true
        ]
    ]);

    $response = @file_get_contents($settings['api_url'], false, $context);

    $httpCode = 0;
    if (isset($http_response_header)) {
        foreach ($http_response_header as $header) {
            if (preg_match('/^HTTP\/\d+\.\d+\s+(\d+)/', $header, $matches)) {
                $httpCode = (int) $matches[1];
            }
        }
    }

    $status = 'failed';
    $error_message = null;

    if ($response === false) {
        $error = error_get_last();
        $error_message = 'خطأ اتصال: ' . ($error['message'] ?? 'Unknown');
    } elseif ($httpCode >= 200 && $httpCode < 300) {
        $status = 'sent';
    } else {
        $responseData = json_decode($response, true);
        $error_message = $responseData['message'] ?? "HTTP $httpCode";
    }

    // تحديث حالة الرسالة
    $pdo->prepare("
        UPDATE whatsapp_queue 
        SET status = ?, error_message = ?, processed_at = datetime('now')
        WHERE id = ?
    ")->execute([$status, $error_message, $message['id']]);

    // تحديث إحصائيات الحملة
    if ($status === 'sent') {
        $pdo->prepare("UPDATE whatsapp_bulk_campaigns SET sent_count = sent_count + 1 WHERE id = ?")->execute([$message['campaign_id']]);
    } else {
        $pdo->prepare("UPDATE whatsapp_bulk_campaigns SET failed_count = failed_count + 1 WHERE id = ?")->execute([$message['campaign_id']]);
    }

    // تسجيل في whatsapp_logs
    $pdo->prepare("
        INSERT INTO whatsapp_logs (user_id, lead_id, recipient_number, recipient_name, message_text, content_type, status, api_response, error_message)
        VALUES (?, ?, ?, ?, ?, 'text', ?, ?, ?)
    ")->execute([
                $user['id'],
                $message['lead_id'],
                $message['recipient_number'],
                $message['recipient_name'],
                $message['message_text'],
                $status,
                $response,
                $error_message
            ]);

    // التحقق من اكتمال الحملة
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM whatsapp_queue WHERE campaign_id = ? AND status = 'pending'");
    $stmt->execute([$message['campaign_id']]);
    $remaining = $stmt->fetchColumn();

    if ($remaining == 0) {
        $pdo->prepare("
            UPDATE whatsapp_bulk_campaigns 
            SET status = 'completed', completed_at = datetime('now'), updated_at = datetime('now')
            WHERE id = ?
        ")->execute([$message['campaign_id']]);
    }

    // جلب حالة الحملة الحالية
    $stmt = $pdo->prepare("SELECT * FROM whatsapp_bulk_campaigns WHERE id = ?");
    $stmt->execute([$message['campaign_id']]);
    $campaign = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'processed' => 1,
        'status' => $status,
        'remaining' => (int) $remaining,
        'campaign' => $campaign
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("Queue Processor Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
