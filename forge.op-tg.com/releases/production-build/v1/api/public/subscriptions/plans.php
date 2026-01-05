<?php
/**
 * Public API - Get Subscription Plans
 * GET /v1/api/public/subscriptions/plans.php
 */

require_once __DIR__ . '/../../bootstrap_api.php';

// Only allow GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    send_error('Method not allowed', 'METHOD_NOT_ALLOWED', 405);
}

try {
    $pdo = db();

    // Fetch all subscription plans
    $stmt = $pdo->query("
        SELECT * FROM subscription_plans
        WHERE is_active = 1
        ORDER BY sort_order ASC
    ");

    $plans_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format plans for frontend
    $formatted_plans = array_map(function ($plan) {
        return [
            'id' => (int) $plan['id'],
            'name' => $plan['name'],
            'slug' => $plan['slug'],
            'description' => $plan['description'],
            'pricing' => [
                'monthly' => (float) $plan['price_monthly'],
                'yearly' => (float) $plan['price_yearly'],
                'currency' => $plan['currency']
            ],
            'quotas' => [
                'phone' => (int) $plan['credits_phone'],
                'email' => (int) $plan['credits_email'],
                'export' => (int) $plan['credits_export']
            ],
            'limits' => [
                'saved_searches' => (int) $plan['max_saved_searches'],
                'saved_lists' => (int) $plan['max_saved_lists'],
                'list_items' => (int) $plan['max_list_items']
            ],
            'features' => json_decode($plan['features'] ?? '[]', true)
        ];
    }, $plans_data);

    send_success(['plans' => $formatted_plans]);

} catch (Throwable $e) {
    error_log('Plans API Error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    send_error('حدث خطأ في الخادم', 'SERVER_ERROR', 500);
}
