<?php
// Bypass test - check database directly
$db_path = __DIR__ . '/database/optforge.db';

if (!file_exists($db_path)) {
    die("Database not found at: $db_path");
}

try {
    $pdo = new PDO('sqlite:' . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->query("SELECT COUNT(*) as count FROM subscription_plans WHERE is_active = 1");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    echo "<h2>Active Plans Count: " . $result['count'] . "</h2>";

    $stmt = $pdo->query("SELECT id, name, slug, price_monthly FROM subscription_plans WHERE is_active = 1 ORDER BY sort_order");
    $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<h3>Plans:</h3><ul>";
    foreach ($plans as $plan) {
        echo "<li>ID: {$plan['id']}, Name: {$plan['name']}, Slug: {$plan['slug']}, Price: {$plan['price_monthly']} SAR</li>";
    }
    echo "</ul>";
} catch (Exception $e) {
    echo "<h2>Error:</h2>";
    echo "<pre>" . $e->getMessage() . "</pre>";
}
