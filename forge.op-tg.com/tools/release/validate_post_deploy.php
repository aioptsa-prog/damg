<?php
require_once __DIR__ . '/../../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
$pdo = db();

$since = $_GET['since'] ?? '15 minutes';
$minWorkers = (int)($_GET['min_workers'] ?? 1);

$workersOnline = (int)$pdo->query("SELECT COUNT(*) c FROM internal_workers WHERE last_seen > datetime('now','-120 seconds')")->fetch()['c'];
$dlqLast15 = (int)$pdo->query("SELECT COUNT(*) c FROM dead_letter_jobs WHERE created_at > datetime('now','-15 minutes')")->fetch()['c'];
$ingestedLast15 = (int)$pdo->query("SELECT COUNT(*) c FROM internal_jobs WHERE status='done' AND finished_at > datetime('now','-15 minutes')")->fetch()['c'];
// Version distribution (last 24h)
$verRows = $pdo->query("SELECT COALESCE(version,'') v, COUNT(*) c FROM internal_workers WHERE last_seen > datetime('now','-24 hours') GROUP BY v ORDER BY c DESC")->fetchAll(PDO::FETCH_ASSOC);

$defaultCh = get_setting('worker_update_channel','stable');
$roll = (int)get_setting('rollout_canary_percent','0');

$go = true; $reasons = [];
if($workersOnline < $minWorkers){ $go=false; $reasons[]='workers_online_lt_threshold'; }
if($dlqLast15 > 0){ $reasons[]='dlq_recent='.strval($dlqLast15); }
if($ingestedLast15 < 1){ $reasons[]='low_throughput'; }

$resp = ['go'=>$go,'decision'=>$go?'GO':'NO-GO','workers_online'=>$workersOnline,'dlq_last15'=>$dlqLast15,'ingested_last15'=>$ingestedLast15,'channel'=>$defaultCh,'canary_percent'=>$roll,'versions'=>$verRows];
if($reasons){ $resp['reasons']=$reasons; }

echo json_encode($resp, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
