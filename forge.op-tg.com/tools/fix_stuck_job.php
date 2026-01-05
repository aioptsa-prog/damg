<?php
require_once __DIR__ . '/../config/db.php';
$jid = (int)($argv[1] ?? 0);
if(!$jid){ fwrite(STDERR, "Usage: php tools/fix_stuck_job.php <job_id>\n"); exit(1); }
$pdo = db();
$pdo->beginTransaction();
try{
  $st = $pdo->prepare("UPDATE internal_jobs SET status='queued', worker_id=NULL, lease_expires_at=NULL, last_progress_at=NULL, updated_at=datetime('now') WHERE id=?");
  $st->execute([$jid]);
  $pdo->prepare("INSERT INTO job_attempts(job_id, started_at, finished_at, success, log_excerpt) VALUES(?, datetime('now'), datetime('now'), 0, 'requeue_manual')")->execute([$jid]);
  $pdo->commit();
  echo "OK requeued job #$jid\n";
}catch(Throwable $e){ $pdo->rollBack(); fwrite(STDERR, "ERR: ".$e->getMessage()."\n"); exit(1);}