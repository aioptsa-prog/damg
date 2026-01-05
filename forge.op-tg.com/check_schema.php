<?php
require 'config/db.php';
$pdo = db();

echo "=== LEADS TABLE ===\n";
$result = $pdo->query("PRAGMA table_info(leads)")->fetchAll();
foreach ($result as $col) {
    echo "- " . $col['name'] . " (" . $col['type'] . ")\n";
}

echo "\n=== USER_CAMPAIGNS TABLE ===\n";
$result = $pdo->query("PRAGMA table_info(user_campaigns)")->fetchAll();
foreach ($result as $col) {
    echo "- " . $col['name'] . " (" . $col['type'] . ")\n";
}

echo "\n=== SAMPLE LEADS ===\n";
$result = $pdo->query("SELECT id, name, campaign_id, created_at FROM leads LIMIT 3")->fetchAll();
foreach ($result as $row) {
    echo "ID: {$row['id']}, Name: {$row['name']}, Campaign: " . ($row['campaign_id'] ?? 'NULL') . "\n";
}
