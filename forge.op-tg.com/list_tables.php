<?php
require_once 'config/db.php';

$pdo = db();

echo "All tables in database:\n";
echo "======================\n\n";

// For SQLite
$stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($tables as $table) {
    $count = $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
    echo "  - $table: $count records\n";
}
