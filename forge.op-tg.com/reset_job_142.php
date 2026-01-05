<?php
require_once __DIR__ . '/config/db.php';

$pdo = db();
$pdo->exec("UPDATE internal_jobs SET status='queued', worker_id=NULL, lease_expires_at=NULL WHERE id=142");

echo "✓ Job 142 reset to queued\n";

$job = $pdo->query("SELECT id, status, worker_id FROM internal_jobs WHERE id=142")->fetch(PDO::FETCH_ASSOC);
echo "Current: ID={$job['id']}, Status={$job['status']}, Worker={$job['worker_id']}\n";
