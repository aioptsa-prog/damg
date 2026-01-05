<?php
require_once __DIR__ . '/config/db.php';

$pdo = db();
$pdo->exec("UPDATE jobs SET status='pending', started_at=NULL WHERE id=1");

echo "Job #1 reset to pending status.\n";

// Show current status
$job = $pdo->query("SELECT * FROM jobs WHERE id=1")->fetch(PDO::FETCH_ASSOC);
echo "Current status: {$job['status']}\n";
