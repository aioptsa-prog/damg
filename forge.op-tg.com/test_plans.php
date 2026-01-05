<?php
// Test the plans API directly
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include bootstrap
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/response.php';

// Simulate the API request
$_SERVER['REQUEST_METHOD'] = 'GET';

try {
    $db = get_db_connection();

    // Fetch all subscription plans
    $stmt = $db->prepare("
        SELECT 
            id, name, slug, description,
            price_monthly, price_yearly, currency,
            quota_phone, quota_email, quota_export,
            limit_saved_searches, limit_saved_lists, limit_list_items,
            features, is_active
        FROM subscription_plans
        WHERE is_active = TRUE
        ORDER BY price_monthly ASC
    ");

    $stmt->execute();
    $plans_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format plans for frontend
    $plans = [];
    foreach ($plans_data as $plan) {
        $plans[] = [
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
                'phone' => (int) $plan['quota_phone'],
                'email' => (int) $plan['quota_email'],
                'export' => (int) $plan['quota_export']
            ],
            'limits' => [
                'saved_searches' => (int) $plan['limit_saved_searches'],
                'saved_lists' => (int) $plan['limit_saved_lists'],
                'list_items' => (int) $plan['limit_list_items']
            ],
            'features' => json_decode($plan['features'], true) ?? []
        ];
    }

    echo "Plans found: " . count($plans) . "\n";
    echo json_encode(['ok' => true, 'plans' => $plans], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
}
