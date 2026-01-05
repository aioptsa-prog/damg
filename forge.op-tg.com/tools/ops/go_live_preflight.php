<?php
// CLI preflight for production go-live readiness
if (php_sapi_name() !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
require_once __DIR__ . '/../../bootstrap.php';
$pdo = db();

$out = [
  'ts' => gmdate('c'),
  'settings' => [],
  'checks' => [],
  'summary' => [ 'ok' => true, 'warnings' => [], 'errors' => [] ],
];

function addCheck(&$out, $name, $ok, $meta = []){
  $out['checks'][] = ['name'=>$name, 'ok'=>$ok] + $meta;
  if(!$ok){ $out['summary']['ok'] = false; $out['summary']['errors'][] = $name; }
}
function addWarn(&$out, $msg){ $out['summary']['warnings'][] = $msg; }

// Core settings
$internalEnabled = get_setting('internal_server_enabled','0')==='1';
$internalSecret  = trim((string)get_setting('internal_secret',''));
$perWorkerStrict = get_setting('per_worker_secret_required','0')==='1';
$updateChannel   = (string)get_setting('worker_update_channel','stable');
$alertWebhook    = trim((string)get_setting('alert_webhook_url',''));
$alertEmail      = trim((string)get_setting('alert_email',''));
$windowSec       = (int)get_setting('workers_online_window_sec','90'); if($windowSec < 30) $windowSec = 90;

$out['settings'] = [
  'internal_server_enabled'=>$internalEnabled,
  'internal_secret_set'=> $internalSecret !== '',
  'per_worker_secret_required'=>$perWorkerStrict,
  'worker_update_channel'=>$updateChannel,
  'alert_webhook_set'=> $alertWebhook !== '',
  'alert_email_set'=> $alertEmail !== '',
  'workers_online_window_sec'=>$windowSec,
];

addCheck($out, 'internal_server_enabled', $internalEnabled);
addCheck($out, 'internal_secret_present', $internalSecret !== '');

// Workers status
$offlineCut = date('Y-m-d H:i:s', time()-$windowSec);
$onlineCount = 0; $totalCount = 0; $offlineIds = [];
try{
  $totalCount = (int)$pdo->query("SELECT COUNT(*) c FROM internal_workers")->fetch()['c'];
  $onlineCount = (int)$pdo->query("SELECT COUNT(*) c FROM internal_workers WHERE last_seen >= datetime('now', '-".$windowSec." seconds')")->fetch()['c'];
  $st=$pdo->prepare("SELECT worker_id FROM internal_workers WHERE last_seen < ? ORDER BY last_seen ASC LIMIT 10"); $st->execute([$offlineCut]);
  $offlineIds = array_map(fn($r)=>$r['worker_id'], $st->fetchAll());
}catch(Throwable $e){}
addCheck($out, 'workers_some_online', $onlineCount>0, ['online'=>$onlineCount, 'total'=>$totalCount]);
if($totalCount>0 && $onlineCount===0){ addWarn($out, 'All workers appear offline within window'); }

// DLQ & stuck jobs
$dlq = 0; $stuck=0;
try{ $dlq = (int)$pdo->query("SELECT COUNT(*) c FROM dead_letter_jobs")->fetch()['c']; }catch(Throwable $e){}
try{ $stuck = (int)$pdo->query("SELECT COUNT(*) c FROM internal_jobs WHERE status='processing' AND lease_expires_at IS NOT NULL AND lease_expires_at < datetime('now')")->fetch()['c']; }catch(Throwable $e){}
addCheck($out, 'dlq_empty', $dlq===0, ['dlq'=>$dlq]);
addCheck($out, 'stuck_jobs_zero', $stuck===0, ['stuck'=>$stuck]);

// Latest artifacts presence (stable/canary) - best-effort
$root = realpath(__DIR__.'/../../');
$latestStable = $root . DIRECTORY_SEPARATOR . 'latest_stable.json';
$latestCanary = $root . DIRECTORY_SEPARATOR . 'latest_canary.json';
// Also check under releases/ as some setups store there
$relStable = $root . DIRECTORY_SEPARATOR . 'releases' . DIRECTORY_SEPARATOR . 'latest_stable.json';
$relCanary = $root . DIRECTORY_SEPARATOR . 'releases' . DIRECTORY_SEPARATOR . 'latest_canary.json';
$hasStable = file_exists($latestStable) || file_exists($relStable);
$hasCanary = file_exists($latestCanary) || file_exists($relCanary);
addCheck($out, 'latest_stable_present', $hasStable, []);
addCheck($out, 'latest_canary_present', $hasCanary, []);

// Alerts configured (warn if none)
if($alertWebhook==='' && $alertEmail===''){ addWarn($out, 'No alerts configured (webhook or email)'); }

// Print JSON
echo json_encode($out, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
