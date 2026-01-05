<?php
// Safe Data Purge & Reset — removes obvious test/old operational data only; logs all actions.
require_once __DIR__ . '/../../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
$pdo = db();
$now = time();
$logDir = __DIR__ . '/../../storage/logs';
@mkdir($logDir, 0777, true);
$logFile = $logDir . '/cleanup.log';

function log_line($msg){
  global $logFile; @file_put_contents($logFile, '['.gmdate('c').'] '.$msg."\n", FILE_APPEND);
}

$summary = [ 'time'=>gmdate('c'), 'ops'=>[] ];

function do_delete($label, $sql, $params = []){
  global $pdo, $summary;
  try{
    // Count preview
    $countSql = preg_replace('/^DELETE\s+FROM/i','SELECT COUNT(*) AS c FROM', $sql, 1);
    $st = $pdo->prepare($countSql);
    $st->execute($params); $c = (int)($st->fetch(PDO::FETCH_ASSOC)['c'] ?? 0);
    if($c>0){ $del = $pdo->prepare($sql); $del->execute($params); }
    $summary['ops'][] = ['label'=>$label,'count'=>$c];
    log_line($label.' count='.$c);
  }catch(Throwable $e){ $summary['ops'][]=['label'=>$label,'error'=>$e->getMessage()]; log_line($label.' error='.$e->getMessage()); }
}

// 1) Test leads only (do not touch real leads):
//   - source='test' OR name LIKE '%test%' OR phone LIKE '000%'
do_delete(
  'leads(test-marked)',
  "DELETE FROM leads WHERE source='test' OR lower(name) LIKE '%test%' OR phone LIKE '000%'"
);

// Cascade assignments via FK (ON DELETE CASCADE). If not set, clear orphaned assignments.
do_delete(
  'assignments(orphaned)',
  "DELETE FROM assignments WHERE lead_id NOT IN (SELECT id FROM leads)"
);

// 2) Jobs done and older than 7 days
do_delete(
  'internal_jobs(done>7d)',
  "DELETE FROM internal_jobs WHERE status='done' AND finished_at < datetime('now','-7 days')"
);

// 3) job_attempts older than 14 days (by finished_at or started_at fallback)
do_delete(
  'job_attempts(>14d)',
  "DELETE FROM job_attempts WHERE (
      (finished_at IS NOT NULL AND finished_at < datetime('now','-14 days'))
      OR (finished_at IS NULL AND started_at < datetime('now','-14 days'))
    )"
);

// 4) hmac_replay entries older than 30 days
do_delete(
  'hmac_replay(>30d)',
  "DELETE FROM hmac_replay WHERE ts < :cut",
  [ ':cut' => time() - 30*86400 ]
);

// 5) usage_counters older than 30 days
do_delete(
  'usage_counters(>30d)',
  "DELETE FROM usage_counters WHERE day < :day",
  [ ':day' => date('Y-m-d', time()-30*86400) ]
);

// 6) alert_events older than 30 days
do_delete(
  'alert_events(>30d)',
  "DELETE FROM alert_events WHERE created_at < datetime('now','-30 days')"
);

// 7) Internal workers stale (>30d) — remove old inactive records to keep registry clean
do_delete(
  'internal_workers(stale>30d)',
  "DELETE FROM internal_workers WHERE last_seen < datetime('now','-30 days')"
);

echo json_encode(['ok'=>true,'summary'=>$summary], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
log_line('DONE');
?>
