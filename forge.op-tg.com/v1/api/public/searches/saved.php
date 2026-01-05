<?php
/**
 * Public API - Get User's Saved Searches
 * GET /v1/api/public/searches/saved.php
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

    // Get user's saved searches
    $stmt = $pdo->prepare("
        SELECT id, name, description, filters, result_count, last_run, created_at, updated_at
        FROM saved_searches
        WHERE user_id = ?
        ORDER BY updated_at DESC
    ");
    $stmt->execute([$user['id']]);

    $searches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format the searches
    $formatted_searches = array_map(function ($search) {
        return [
            'id' => (int) $search['id'],
            'name' => $search['name'],
            'description' => $search['description'],
            'filters' => json_decode($search['filters'], true),
            'result_count' => (int) $search['result_count'],
            'last_run' => $search['last_run'],
            'created_at' => $search['created_at'],
            'updated_at' => $search['updated_at']
        ];
    }, $searches);

    send_success(['searches' => $formatted_searches]);

} catch (Throwable $e) {
    error_log('Saved Searches API Error: ' . $e->getMessage());
    send_error('حدث خطأ في الخادم', 'SERVER_ERROR', 500);
}
