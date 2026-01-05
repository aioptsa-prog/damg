<?php
// Quick test to check if plans exist in database
require_once __DIR__ . '/lib/db.php';

try {
    $db = get_db_connection();
    $stmt = $db->query("SELECT COUNT(*) as count FROM subscription_plans WHERE is_active = 1");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    echo "Active plans count: " . $result['count'] . "\n\n";

    $stmt = $db->query("SELECT id, name, slug, price_monthly FROM subscription_plans WHERE is_active = 1 ORDER BY sort_order");
    $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Plans:\n";
    foreach ($plans as $plan) {
        echo "- ID: {$plan['id']}, Name: {$plan['name']}, Slug: {$plan['slug']}, Price: {$plan['price_monthly']} SAR\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
