<?php
require_once __DIR__ . '/config/db.php';
$pdo = db();

echo "=== Categories in the System ===\n\n";

$stmt = $pdo->query("SELECT id, name FROM categories ORDER BY name");
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($categories) . " categories:\n";
foreach ($categories as $cat) {
    echo "- " . $cat['name'] . "\n";
}

echo "\n\nAs JSON:\n";
echo json_encode(array_column($categories, 'name'), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
