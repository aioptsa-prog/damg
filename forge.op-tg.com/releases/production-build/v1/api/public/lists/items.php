<?php
/**
 * Public API - Manage List Items
 * GET/POST/DELETE /v1/api/public/lists/items.php
 */

require_once __DIR__ . '/../../bootstrap_api.php';
require_once __DIR__ . '/../../../../lib/public_auth.php';
require_once __DIR__ . '/../../../../lib/subscriptions.php';

$method = $_SERVER['REQUEST_METHOD'];
$user = require_public_auth();
$pdo = db();

try {
    switch ($method) {
        case 'GET':
            // Get list items
            $list_id = (int) ($_GET['list_id'] ?? 0);

            if (!$list_id) {
                send_error('معرف القائمة مطلوب', 'MISSING_FIELD', 400);
            }

            // Verify list ownership
            $stmt = $pdo->prepare("SELECT id FROM saved_lists WHERE id = ? AND user_id = ?");
            $stmt->execute([$list_id, $user['id']]);
            if (!$stmt->fetch()) {
                send_error('القائمة غير موجودة', 'NOT_FOUND', 404);
            }

            // Get items with lead data
            $stmt = $pdo->prepare("
                SELECT 
                    sli.id,
                    sli.lead_id,
                    sli.notes,
                    sli.added_at,
                    l.name,
                    l.city,
                    l.phone,
                    c.name as category_name
                FROM saved_list_items sli
                INNER JOIN leads l ON sli.lead_id = l.id
                LEFT JOIN categories c ON l.category_id = c.id
                WHERE sli.list_id = ?
                ORDER BY sli.added_at DESC
            ");
            $stmt->execute([$list_id]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $items = array_map(function ($item) {
                return [
                    'id' => (int) $item['id'],
                    'lead_id' => (int) $item['lead_id'],
                    'notes' => $item['notes'],
                    'added_at' => $item['added_at'],
                    'lead' => [
                        'name' => $item['name'],
                        'city' => $item['city'],
                        'phone' => $item['phone'],
                        'category' => $item['category_name']
                    ]
                ];
            }, $items);

            send_success(['items' => $items]);
            break;

        case 'POST':
            // Add lead to list
            $input = get_json_input();
            validate_required_fields($input, ['list_id', 'lead_id']);

            $list_id = (int) $input['list_id'];
            $lead_id = (int) $input['lead_id'];
            $notes = trim($input['notes'] ?? '');

            // Verify list ownership
            $stmt = $pdo->prepare("SELECT id FROM saved_lists WHERE id = ? AND user_id = ?");
            $stmt->execute([$list_id, $user['id']]);
            if (!$stmt->fetch()) {
                send_error('القائمة غير موجودة', 'NOT_FOUND', 404);
            }

            // Check if already in list
            $stmt = $pdo->prepare("SELECT id FROM saved_list_items WHERE list_id = ? AND lead_id = ?");
            $stmt->execute([$list_id, $lead_id]);
            if ($stmt->fetch()) {
                send_error('العميل موجود بالفعل في هذه القائمة', 'ALREADY_EXISTS', 409);
            }

            // Add to list
            $stmt = $pdo->prepare("
                INSERT INTO saved_list_items (list_id, lead_id, notes)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$list_id, $lead_id, $notes]);

            send_success([
                'item_id' => $pdo->lastInsertId()
            ], 'تم إضافة العميل للقائمة');
            break;

        case 'DELETE':
            // Remove item from list
            $input = get_json_input();
            validate_required_fields($input, ['item_id']);

            // Verify ownership through list
            $stmt = $pdo->prepare("
                DELETE FROM saved_list_items 
                WHERE id = ? 
                  AND list_id IN (SELECT id FROM saved_lists WHERE user_id = ?)
            ");
            $stmt->execute([$input['item_id'], $user['id']]);

            if ($stmt->rowCount() === 0) {
                send_error('العنصر غير موجود', 'NOT_FOUND', 404);
            }

            send_success([], 'تم إزالة العميل من القائمة');
            break;

        default:
            send_error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
    }

} catch (Throwable $e) {
    error_log('List Items API Error: ' . $e->getMessage());
    send_error('حدث خطأ في الخادم', 'SERVER_ERROR', 500);
}
