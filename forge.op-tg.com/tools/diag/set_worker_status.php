<?php
if (php_sapi_name() !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
require_once __DIR__ . '/../../bootstrap.php';
$worker = $argv[1] ?? '';
$status = $argv[2] ?? 'idle';
$activeJob = isset($argv[3]) ? $argv[3] : null;
if($worker===''){ fwrite(STDERR, "Usage: php tools/diag/set_worker_status.php <worker_id> [status] [active_job_id|null]\n"); exit(1); }
$pdo = db();
$st = $pdo->prepare("UPDATE internal_workers SET status=?, active_job_id=? WHERE worker_id=?");
$st->execute([$status, $activeJob!==null? $activeJob : null, $worker]);
if($st->rowCount()===0){ fwrite(STDERR, "Worker not found\n"); exit(1); }
echo "OK updated $worker status to $status\n";
