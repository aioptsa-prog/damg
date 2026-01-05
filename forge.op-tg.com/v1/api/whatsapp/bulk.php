<?php
/**
 * WhatsApp Bulk Campaigns API
 * POST: إنشاء حملة جديدة
 * GET: جلب الحملات وحالتها
 * Security: CORS Allowlist
 */

require_once __DIR__ . '/../../../lib/cors.php';
handle_cors(['GET', 'POST', 'DELETE', 'OPTIONS']);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/bootstrap.php';

$user = require_whatsapp_auth();
$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        // جلب الحملات
        $campaign_id = $_GET['id'] ?? null;

        if ($campaign_id) {
            // جلب حملة محددة مع تفاصيلها
            $stmt = $pdo->prepare("SELECT * FROM whatsapp_bulk_campaigns WHERE id = ? AND user_id = ?");
            $stmt->execute([$campaign_id, $user['id']]);
            $campaign = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$campaign) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'الحملة غير موجودة'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // جلب الرسائل
            $stmt = $pdo->prepare("SELECT * FROM whatsapp_queue WHERE campaign_id = ? ORDER BY id");
            $stmt->execute([$campaign_id]);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'ok' => true,
                'campaign' => $campaign,
                'messages' => $messages
            ], JSON_UNESCAPED_UNICODE);
        } else {
            // جلب كل الحملات
            $stmt = $pdo->prepare("
                SELECT * FROM whatsapp_bulk_campaigns 
                WHERE user_id = ? 
                ORDER BY created_at DESC 
                LIMIT 50
            ");
            $stmt->execute([$user['id']]);
            $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['ok' => true, 'campaigns' => $campaigns], JSON_UNESCAPED_UNICODE);
        }

    } elseif ($method === 'POST') {
        // إنشاء حملة جديدة
        $input = json_decode(file_get_contents('php://input'), true);

        $name = $input['name'] ?? 'حملة ' . date('Y-m-d H:i');
        $template_id = $input['template_id'] ?? null;
        $message_text = $input['message_text'] ?? '';
        $recipients = $input['recipients'] ?? []; // [{number, name, lead_id}]

        if (empty($recipients)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'يرجى تحديد مستلمين'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // جلب القالب إذا تم تحديده
        if ($template_id && empty($message_text)) {
            $stmt = $pdo->prepare("SELECT message_text FROM whatsapp_templates WHERE id = ?");
            $stmt->execute([$template_id]);
            $template = $stmt->fetch();
            if ($template) {
                $message_text = $template['message_text'];
            }
        }

        if (empty($message_text)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'يرجى تحديد نص الرسالة أو قالب'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // إنشاء الحملة
        $stmt = $pdo->prepare("
            INSERT INTO whatsapp_bulk_campaigns (user_id, template_id, name, message_text, status, total_count)
            VALUES (?, ?, ?, ?, 'processing', ?)
        ");
        $stmt->execute([$user['id'], $template_id, $name, $message_text, count($recipients)]);
        $campaignId = $pdo->lastInsertId();

        // إضافة الرسائل للقائمة
        $stmt = $pdo->prepare("
            INSERT INTO whatsapp_queue (campaign_id, lead_id, recipient_number, recipient_name, message_text, status)
            VALUES (?, ?, ?, ?, ?, 'pending')
        ");

        foreach ($recipients as $recipient) {
            $personalizedMsg = str_replace('{{name}}', $recipient['name'] ?? 'عميلنا العزيز', $message_text);
            $stmt->execute([
                $campaignId,
                $recipient['lead_id'] ?? null,
                $recipient['number'],
                $recipient['name'] ?? '',
                $personalizedMsg
            ]);
        }

        echo json_encode([
            'ok' => true,
            'message' => 'تم إنشاء الحملة بنجاح',
            'campaign_id' => $campaignId,
            'total' => count($recipients)
        ], JSON_UNESCAPED_UNICODE);

    } elseif ($method === 'DELETE') {
        // إلغاء حملة
        $campaign_id = $_GET['id'] ?? null;

        if (!$campaign_id) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'معرف الحملة مطلوب'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // التحقق من ملكية الحملة
        $stmt = $pdo->prepare("SELECT id FROM whatsapp_bulk_campaigns WHERE id = ? AND user_id = ?");
        $stmt->execute([$campaign_id, $user['id']]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'لا يمكنك إلغاء هذه الحملة'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // إلغاء الحملة والرسائل المعلقة
        $pdo->prepare("UPDATE whatsapp_bulk_campaigns SET status = 'cancelled' WHERE id = ?")->execute([$campaign_id]);
        $pdo->prepare("UPDATE whatsapp_queue SET status = 'cancelled' WHERE campaign_id = ? AND status = 'pending'")->execute([$campaign_id]);

        echo json_encode(['ok' => true, 'message' => 'تم إلغاء الحملة'], JSON_UNESCAPED_UNICODE);

    } else {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    }

} catch (Exception $e) {
    error_log("Bulk Campaign Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
