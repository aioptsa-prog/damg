<?php
require_once __DIR__ . '/config/db.php';

$pdo = db();

echo "========================================\n";
echo "  Checking Users Table Structure\n";
echo "========================================\n\n";

// Get users table structure
$result = $pdo->query("PRAGMA table_info(users)");
$columns = $result->fetchAll(PDO::FETCH_ASSOC);

echo "Users table columns:\n";
foreach ($columns as $col) {
    echo "- {$col['name']} ({$col['type']})\n";
}

echo "\n";

// Check a sample user
$stmt = $pdo->query("SELECT * FROM users LIMIT 1");
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user) {
    echo "Sample user columns:\n";
    foreach (array_keys($user) as $key) {
        echo "- $key\n";
    }
}

echo "\n========================================\n";
echo "  Checking public_users Table\n";
echo "========================================\n\n";

$result = $pdo->query("PRAGMA table_info(public_users)");
$columns = $result->fetchAll(PDO::FETCH_ASSOC);

echo "public_users table columns:\n";
foreach ($columns as $col) {
    echo "- {$col['name']} ({$col['type']})\n";
}
