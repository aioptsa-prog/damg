<?php
require_once __DIR__ . '/../config/db.php';
$pdo = db();
$rows = $pdo->query("SELECT id,status,worker_id,last_progress_at,lease_expires_at FROM internal_jobs WHERE status='processing' ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['rows'=>$rows], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),"\n";