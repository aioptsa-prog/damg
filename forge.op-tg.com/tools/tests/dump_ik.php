<?php
require_once __DIR__ . '/../../bootstrap.php';
$pdo = db();
$rows = $pdo->query("SELECT id, job_id, ikey, created_at FROM idempotency_keys ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
foreach($rows as $r){ echo json_encode($r, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),"\n"; }
