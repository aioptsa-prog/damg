<?php
/**
 * WhatsApp Settings API
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
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $stmt = $pdo->prepare("SELECT * FROM whatsapp_settings WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$settings) {
            $settings = [
                'api_url' => 'https://wa.washeej.com/api/qr/rest/send_message',
                'auth_token' => '',
                'sender_number' => '',
                'is_active' => 0
            ];
        }

        if (!empty($settings['auth_token'])) {
            $settings['auth_token_masked'] = substr($settings['auth_token'], 0, 10) . '...' . substr($settings['auth_token'], -5);
        } else {
            $settings['auth_token_masked'] = '';
        }

        echo json_encode(['ok' => true, 'settings' => $settings], JSON_UNESCAPED_UNICODE);

    } elseif ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);

        $api_url = $input['api_url'] ?? 'https://wa.washeej.com/api/qr/rest/send_message';
        $auth_token = $input['auth_token'] ?? '';
        $sender_number = $input['sender_number'] ?? '';
        $is_active = $input['is_active'] ?? 0;

        $stmt = $pdo->prepare("SELECT id FROM whatsapp_settings WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        $existing = $stmt->fetch();

        if ($existing) {
            $sql = "UPDATE whatsapp_settings SET api_url = ?, sender_number = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP";
            $params = [$api_url, $sender_number, $is_active ? 1 : 0];
            if (!empty($auth_token)) {
                $sql .= ", auth_token = ?";
                $params[] = $auth_token;
            }
            $sql .= " WHERE user_id = ?";
            $params[] = $user['id'];
            $pdo->prepare($sql)->execute($params);
        } else {
            $pdo->prepare("INSERT INTO whatsapp_settings (user_id, api_url, auth_token, sender_number, is_active) VALUES (?, ?, ?, ?, ?)")
                ->execute([$user['id'], $api_url, $auth_token, $sender_number, $is_active ? 1 : 0]);
        }

        echo json_encode(['ok' => true, 'message' => 'تم حفظ الإعدادات بنجاح'], JSON_UNESCAPED_UNICODE);

    } else {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    }

} catch (Exception $e) {
    error_log("WhatsApp Settings Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
