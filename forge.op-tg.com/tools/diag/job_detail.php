<?php
if (php_sapi_name() !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
require_once __DIR__ . '/../../bootstrap.php';
$pdo = db();
$jid = (int)($argv[1] ?? 0);
if(!$jid){ fwrite(STDERR, "Usage: php tools/diag/job_detail.php <job_id>\n"); exit(1); }
$st = $pdo->prepare("SELECT id,status,worker_id,claimed_at,lease_expires_at,attempts,attempt_id,last_progress_at,updated_at FROM internal_jobs WHERE id=?");
$st->execute([$jid]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if(!$row){ fwrite(STDERR, "Job not found\n"); exit(1); }
echo json_encode($row, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE),"\n";
