<?php
require_once __DIR__ . '/config/db.php';
$pdo = db();

$stmt = $pdo->query("SELECT COUNT(*) as cnt FROM categories WHERE is_active = 1");
$count = $stmt->fetch()['cnt'];
echo "Active categories: $count\n";

$stmt = $pdo->query("SELECT id, name FROM categories WHERE is_active = 1 LIMIT 10");
echo "\nFirst 10 categories:\n";
while ($r = $stmt->fetch()) {
    echo "- " . $r['name'] . "\n";
}
