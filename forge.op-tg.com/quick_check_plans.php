<?php
$db = new PDO('sqlite:' . __DIR__ . '/database/optforge.db');
$stmt = $db->query("SELECT COUNT(*) as count FROM subscription_plans WHERE is_active = 1");
$result = $stmt->fetch(PDO::FETCH_ASSOC);
echo "Active plans: " . $result['count'] . "\n";

$stmt = $db->query("SELECT id, name, slug FROM subscription_plans WHERE is_active = 1 ORDER BY sort_order");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "- " . $row['name'] . " (" . $row['slug'] . ")\n";
}
