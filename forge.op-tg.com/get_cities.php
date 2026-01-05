<?php
require_once __DIR__ . '/config/db.php';
$pdo = db();

echo "=== Cities in the System ===\n\n";

// Get unique cities from leads
$stmt = $pdo->query("SELECT DISTINCT city FROM leads WHERE city IS NOT NULL AND city != '' ORDER BY city");
$cities = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo "Found " . count($cities) . " cities:\n";
foreach ($cities as $city) {
    echo "- " . $city . "\n";
}

echo "\n\nAs JSON array for frontend:\n";
echo json_encode($cities, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
