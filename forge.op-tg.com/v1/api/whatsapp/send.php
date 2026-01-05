<?php
/**
 * WhatsApp Send API
 */

// CORS headers FIRST
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/bootstrap.php';

$user = require_whatsapp_auth();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);

    $recipient_number = $input['recipient_number'] ?? '';
    $recipient_name = $input['recipient_name'] ?? '';
    $lead_id = $input['lead_id'] ?? null;
    $template_id = $input['template_id'] ?? null;
    $message_text = $input['message_text'] ?? '';
    $content_type = $input['content_type'] ?? 'text';
    $media_url = $input['media_url'] ?? '';

    // تنسيق رقم الهاتف السعودي - إضافة 966 تلقائياً
    $recipient_number = formatSaudiPhone($recipient_number);

    if (empty($recipient_number)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'رقم المستلم مطلوب'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM whatsapp_settings WHERE user_id = ? AND is_active = 1");
    $stmt->execute([$user['id']]);
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$settings) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'إعدادات الواتساب غير مفعّلة'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (empty($settings['auth_token']) || empty($settings['sender_number'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'يرجى إكمال إعدادات الواتساب'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($template_id) {
        $stmt = $pdo->prepare("SELECT * FROM whatsapp_templates WHERE id = ?");
        $stmt->execute([$template_id]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($template) {
            $message_text = $template['message_text'];
            $content_type = $template['content_type'];
            if (!empty($template['media_url']))
                $media_url = $template['media_url'];
        }
    }

    $message_text = str_replace('{{name}}', $recipient_name ?: 'عميلنا العزيز', $message_text);

    $payload = [
        'requestType' => 'POST',
        'token' => $settings['auth_token'],
        'from' => $settings['sender_number'],
        'to' => $recipient_number,
        'messageType' => $content_type
    ];

    switch ($content_type) {
        case 'text':
            $payload['text'] = $message_text;
            break;
        case 'image':
            $payload['imageUrl'] = $media_url;
            if (!empty($message_text))
                $payload['caption'] = $message_text;
            break;
        case 'video':
            $payload['videoUrl'] = $media_url;
            if (!empty($message_text))
                $payload['caption'] = $message_text;
            break;
        case 'document':
            $payload['docUrl'] = $media_url;
            if (!empty($message_text))
                $payload['caption'] = $message_text;
            break;
        case 'audio':
            $payload['aacUrl'] = $media_url;
            break;
    }

    // Use file_get_contents instead of cURL (cURL not available)
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

    // Get HTTP status code
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
        $error_message = 'خطأ اتصال: ' . ($error['message'] ?? 'Unknown error');
    } else {
        $responseData = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300)
            $status = 'sent';
        else
            $error_message = $responseData['message'] ?? 'فشل الإرسال (HTTP ' . $httpCode . ')';
    }

    $pdo->prepare("INSERT INTO whatsapp_logs (user_id, lead_id, template_id, recipient_number, recipient_name, message_text, content_type, status, api_response, error_message) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$user['id'], $lead_id, $template_id, $recipient_number, $recipient_name, $message_text, $content_type, $status, $response, $error_message]);

    if ($status === 'sent')
        echo json_encode(['ok' => true, 'message' => 'تم إرسال الرسالة بنجاح'], JSON_UNESCAPED_UNICODE);
    else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $error_message], JSON_UNESCAPED_UNICODE);
    }

} catch (Exception $e) {
    error_log("WhatsApp Send Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
