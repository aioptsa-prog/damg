<?php
/**
 * Public API - Create a New List
 * POST /v1/api/public/lists/create.php
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
    validate_required_fields($data, ['name']);

    // Check user's plan limits
    $pdo = db();

    // Get user's subscription to check limits
    $stmt = $pdo->prepare("
        SELECT sp.max_saved_lists
        FROM user_subscriptions us
        INNER JOIN subscription_plans sp ON us.plan_id = sp.id
        WHERE us.user_id = ? AND us.status = 'active'
        ORDER BY us.created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$user['id']]);
    $subscription = $stmt->fetch(PDO::FETCH_ASSOC);

    $max_lists = $subscription ? (int) $subscription['max_saved_lists'] : 2; // Default to free plan limit

    // Check if user has reached limit (0 means unlimited)
    if ($max_lists > 0) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM saved_lists WHERE user_id = ?");
        $stmt->execute([$user['id']]);
        $current_count = (int) $stmt->fetchColumn();

        if ($current_count >= $max_lists) {
            send_error(
                "لقد وصلت إلى الحد الأقصى من القوائم المحفوظة ($max_lists). قم بترقية باقتك لإنشاء المزيد.",
                'LIMIT_REACHED',
                403
            );
        }
    }

    // Create the list
    $stmt = $pdo->prepare("
        INSERT INTO saved_lists (user_id, name, description, color)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([
        $user['id'],
        $data['name'],
        $data['description'] ?? null,
        $data['color'] ?? '#3b82f6' // Default blue color
    ]);

    $list_id = $pdo->lastInsertId();

    // Get the created list
    $stmt = $pdo->prepare("SELECT * FROM saved_lists WHERE id = ?");
    $stmt->execute([$list_id]);
    $list = $stmt->fetch(PDO::FETCH_ASSOC);

    send_success([
        'message' => 'تم إنشاء القائمة بنجاح',
        'list' => [
            'id' => (int) $list['id'],
            'name' => $list['name'],
            'description' => $list['description'],
            'color' => $list['color'],
            'items_count' => 0,
            'created_at' => $list['created_at'],
            'updated_at' => $list['updated_at']
        ]
    ]);

} catch (Throwable $e) {
    error_log('Create List Error: ' . $e->getMessage());
    send_error('حدث خطأ في الخادم', 'SERVER_ERROR', 500);
}
