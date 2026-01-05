<?php
/**
 * Public API - Get User's Saved Lists
 * GET /v1/api/public/lists/saved.php
 */

require_once __DIR__ . '/../../bootstrap_api.php';

// Require authentication
$user = require_api_auth();

// Only allow GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

try {
    $pdo = db();

    // Get user's saved lists with item counts
    $stmt = $pdo->prepare("
        SELECT 
            sl.id, sl.name, sl.description, sl.color, sl.created_at, sl.updated_at,
            COUNT(sli.id) as items_count
        FROM saved_lists sl
        LEFT JOIN saved_list_items sli ON sl.id = sli.list_id
        WHERE sl.user_id = ?
        GROUP BY sl.id
        ORDER BY sl.updated_at DESC
    ");
    $stmt->execute([$user['id']]);

    $lists = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format the lists
    $formatted_lists = array_map(function ($list) {
        return [
            'id' => (int) $list['id'],
            'name' => $list['name'],
            'description' => $list['description'],
            'color' => $list['color'],
            'items_count' => (int) $list['items_count'],
            'created_at' => $list['created_at'],
            'updated_at' => $list['updated_at']
        ];
    }, $lists);

    send_success(['lists' => $formatted_lists]);

} catch (Throwable $e) {
    error_log('Saved Lists API Error: ' . $e->getMessage());
    send_error('حدث خطأ في الخادم', 'SERVER_ERROR', 500);
}
