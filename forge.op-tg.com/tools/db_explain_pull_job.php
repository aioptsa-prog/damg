<?php
// tools/db_explain_pull_job.php
require_once __DIR__ . '/../bootstrap.php';
$pdo = db();
$now = date('Y-m-d H:i:s');
$sql = "EXPLAIN QUERY PLAN SELECT id FROM internal_jobs WHERE (status='queued' OR (status='processing' AND (lease_expires_at IS NULL OR lease_expires_at < :now))) AND (next_retry_at IS NULL OR next_retry_at <= :now) AND (max_attempts IS NULL OR COALESCE(attempts,0) < max_attempts) ORDER BY COALESCE(priority,0) DESC, COALESCE(queued_at, created_at) ASC, id ASC LIMIT 1";
$st = $pdo->prepare($sql);
$st->execute([':now'=>$now]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($rows, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
