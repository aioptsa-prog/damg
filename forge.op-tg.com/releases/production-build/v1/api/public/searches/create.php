<?php
/**
 * Public API - Save a Search
 * POST /v1/api/public/searches/create.php
 */

require_once __DIR__ . '/../../bootstrap_api.php';

// Require authentication
$user = require_api_auth();

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

try {
    $data = get_json_input();

    // Validate required fields
    validate_required_fields($data, ['name', 'filters']);

    // Check user's plan limits
    $pdo = db();

    // Get user's subscription to check limits
    $stmt = $pdo->prepare("
        SELECT sp.max_saved_searches
        FROM user_subscriptions us
        INNER JOIN subscription_plans sp ON us.plan_id = sp.id
        WHERE us.user_id = ? AND us.status = 'active'
        ORDER BY us.created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$user['id']]);
    $subscription = $stmt->fetch(PDO::FETCH_ASSOC);

    $max_searches = $subscription ? (int) $subscription['max_saved_searches'] : 5; // Default to free plan limit

    // Check if user has reached limit (0 means unlimited)
    if ($max_searches > 0) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM saved_searches WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        $current_count = (int) $stmt->fetchColumn();

        if ($current_count >= $max_searches) {
            send_error(
                "لقد وصلت إلى الحد الأقصى من البحوثات المحفوظة ($max_searches). قم بترقية باقتك لحفظ المزيد.",
                'LIMIT_REACHED',
                403
            );
        }
    }

    // Save the search
    $stmt = $pdo->prepare("
        INSERT INTO saved_searches (user_id, name, description, filters)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([
        $user['id'],
        $data['name'],
        $data['description'] ?? null,
        json_encode($data['filters'])
    ]);

    $search_id = $pdo->lastInsertId();

    // Get the created search
    $stmt = $pdo->prepare("SELECT * FROM saved_searches WHERE id = ?");
    $stmt->execute([$search_id]);
    $search = $stmt->fetch(PDO::FETCH_ASSOC);

    send_success([
        'message' => 'تم حفظ البحث بنجاح',
        'search' => [
            'id' => (int) $search['id'],
            'name' => $search['name'],
            'description' => $search['description'],
            'filters' => json_decode($search['filters'], true),
            'result_count' => 0,
            'last_run' => null,
            'created_at' => $search['created_at'],
            'updated_at' => $search['updated_at']
        ]
    ]);

} catch (Throwable $e) {
    error_log('Create Saved Search Error: ' . $e->getMessage());
    send_error('حدث خطأ في الخادم', 'SERVER_ERROR', 500);
}
