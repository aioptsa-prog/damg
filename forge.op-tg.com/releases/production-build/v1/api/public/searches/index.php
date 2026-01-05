<?php
/**
 * Public API - Manage Saved Searches
 * GET/POST/PUT/DELETE /v1/api/public/searches/index.php
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
            // List user's saved searches
            $stmt = $pdo->prepare("
                SELECT * FROM saved_searches 
                WHERE user_id = ? 
                ORDER BY created_at DESC
            ");
            $stmt->execute([$user['id']]);
            $searches = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $searches = array_map(function ($search) {
                return [
                    'id' => (int) $search['id'],
                    'name' => $search['name'],
                    'description' => $search['description'],
                    'filters' => json_decode($search['filters'], true),
                    'result_count' => (int) $search['result_count'],
                    'last_run' => $search['last_run'],
                    'created_at' => $search['created_at']
                ];
            }, $searches);

            send_success(['searches' => $searches]);
            break;

        case 'POST':
            // Create new saved search
            $input = get_json_input();
            validate_required_fields($input, ['name', 'filters']);

            // Check quota
            $subscription = get_user_subscription($user['id']);
            $max_searches = (int) $subscription['max_saved_searches'];

            if ($max_searches > 0) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM saved_searches WHERE user_id = ?");
                $stmt->execute([$user['id']]);
                $count = $stmt->fetchColumn();

                if ($count >= $max_searches) {
                    send_error(
                        "لقد وصلت للحد الأقصى من البحوثات المحفوظة ($max_searches)",
                        'QUOTA_EXCEEDED',
                        403
                    );
                }
            }

            $stmt = $pdo->prepare("
                INSERT INTO saved_searches (user_id, name, description, filters)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $user['id'],
                trim($input['name']),
                trim($input['description'] ?? ''),
                json_encode($input['filters'])
            ]);

            $search_id = $pdo->lastInsertId();

            send_success([
                'search_id' => $search_id,
                'message' => 'تم حفظ البحث بنجاح'
            ]);
            break;

        case 'DELETE':
            // Delete saved search
            $input = get_json_input();
            validate_required_fields($input, ['search_id']);

            $stmt = $pdo->prepare("
                DELETE FROM saved_searches 
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$input['search_id'], $user['id']]);

            if ($stmt->rowCount() === 0) {
                send_error('البحث غير موجود', 'NOT_FOUND', 404);
            }

            send_success([], 'تم حذف البحث');
            break;

        default:
            send_error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
    }

} catch (Throwable $e) {
    error_log('Saved Searches API Error: ' . $e->getMessage());
    send_error('حدث خطأ في الخادم', 'SERVER_ERROR', 500);
}
