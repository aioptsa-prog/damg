<?php
if (php_sapi_name() !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
require_once __DIR__ . '/../../bootstrap.php';
$jid = (int)($argv[1] ?? 0);
$worker = $argv[2] ?? 'test-worker';
$minutesAgo = isset($argv[3]) ? (int)$argv[3] : 10;
if(!$jid){ fwrite(STDERR, "Usage: php tools/diag/set_job_processing.php <job_id> [worker_id] [minutes_ago]\n"); exit(1); }
$pdo = db();
$claimed = date('Y-m-d H:i:s', time() - ($minutesAgo * 60));
$leaseExpired = date('Y-m-d H:i:s', time() - ($minutesAgo * 60) + 60); // 1 minute after claim
$st = $pdo->prepare("UPDATE internal_jobs SET status='processing', worker_id=?, claimed_at=?, lease_expires_at=?, attempts=COALESCE(attempts,0)+1, updated_at=datetime('now') WHERE id=?");
$st->execute([$worker, $claimed, $leaseExpired, $jid]);
if($st->rowCount()===0){ fwrite(STDERR, "No job updated\n"); exit(1); }
echo "Job $jid marked processing for $worker with expired lease\n";
