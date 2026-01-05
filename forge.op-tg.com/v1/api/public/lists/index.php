<?php
/**
 * Public API - Manage Saved Lists
 * GET/POST/DELETE /v1/api/public/lists/index.php
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
            // List user's saved lists with item counts
            $stmt = $pdo->prepare("
                SELECT 
                    sl.*,
                    COUNT(sli.id) as items_count
                FROM saved_lists sl
                LEFT JOIN saved_list_items sli ON sl.id = sli.list_id
                WHERE sl.user_id = ?
                GROUP BY sl.id
                ORDER BY sl.created_at DESC
            ");
            $stmt->execute([$user['id']]);
            $lists = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $lists = array_map(function ($list) {
                return [
                    'id' => (int) $list['id'],
                    'name' => $list['name'],
                    'description' => $list['description'],
                    'color' => $list['color'],
                    'items_count' => (int) $list['items_count'],
                    'created_at' => $list['created_at']
                ];
            }, $lists);

            send_success(['lists' => $lists]);
            break;

        case 'POST':
            // Create new list
            $input = get_json_input();
            validate_required_fields($input, ['name']);

            // Check quota
            $subscription = get_user_subscription($user['id']);
            $max_lists = (int) $subscription['max_saved_lists'];

            if ($max_lists > 0) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM saved_lists WHERE user_id = ?");
                $stmt->execute([$user['id']]);
                $count = $stmt->fetchColumn();

                if ($count >= $max_lists) {
                    send_error(
                        "لقد وصلت للحد الأقصى من القوائم المحفوظة ($max_lists)",
                        'QUOTA_EXCEEDED',
                        403
                    );
                }
            }

            $stmt = $pdo->prepare("
                INSERT INTO saved_lists (user_id, name, description, color)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $user['id'],
                trim($input['name']),
                trim($input['description'] ?? ''),
                trim($input['color'] ?? '#3B82F6')
            ]);

            $list_id = $pdo->lastInsertId();

            send_success([
                'list_id' => $list_id,
                'message' => 'تم إنشاء القائمة بنجاح'
            ]);
            break;

        case 'DELETE':
            // Delete list
            $input = get_json_input();
            validate_required_fields($input, ['list_id']);

            $stmt = $pdo->prepare("
                DELETE FROM saved_lists 
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$input['list_id'], $user['id']]);

            if ($stmt->rowCount() === 0) {
                send_error('القائمة غير موجودة', 'NOT_FOUND', 404);
            }

            send_success([], 'تم حذف القائمة');
            break;

        default:
            send_error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
    }

} catch (Throwable $e) {
    error_log('Saved Lists API Error: ' . $e->getMessage());
    send_error('حدث خطأ في الخادم', 'SERVER_ERROR', 500);
}
