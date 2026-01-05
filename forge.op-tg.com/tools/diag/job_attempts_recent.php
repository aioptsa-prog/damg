<?php
if (php_sapi_name() !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
require_once __DIR__ . '/../../bootstrap.php';
$pdo = db();
$limit = isset($argv[1]) ? max(1, (int)$argv[1]) : 10;
$st = $pdo->prepare("SELECT id, job_id, worker_id, started_at, finished_at, success, log_excerpt, attempt_id FROM job_attempts ORDER BY id DESC LIMIT ?");
$st->execute([$limit]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),"\n";
