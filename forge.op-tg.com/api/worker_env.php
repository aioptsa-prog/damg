<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

// Guard: admin session only
$u = current_user();
if(!$u || ($u['role'] ?? '') !== 'admin'){
  http_response_code(403);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['error'=>'forbidden']);
  exit;
}

header('Content-Type: text/plain; charset=utf-8');

$base = trim((string)get_setting('worker_base_url',''));
if($base===''){
  // derive from request if not set
  try{
    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $script = $_SERVER['SCRIPT_NAME'] ?? '/api/worker_env.php';
    $rootPath = rtrim(str_replace('\\','/', dirname(dirname($script))), '/');
    $base = ($host ? ($scheme.'://'.$host) : '') . ($rootPath ? $rootPath : '');
  }catch(Throwable $e){ $base = ''; }
}

$secret = (string)get_setting('internal_secret','');
$pull = (int)get_setting('worker_pull_interval_sec','30');
$headless = get_setting('worker_headless','0')==='1' ? 'true' : 'false';
$maxPages = (int)get_setting('worker_max_pages','5');
$lease = (int)get_setting('worker_lease_sec','180');
$repBatch = (int)get_setting('worker_report_batch_size','10');
$repEvery = (int)get_setting('worker_report_every_ms','15000');
$itemDelay = (int)get_setting('worker_item_delay_ms','800');
$confCode = trim((string)get_setting('worker_config_code',''));

$confUrl = '';
if($confCode !== '' && $base !== ''){ $confUrl = rtrim($base,'/').'/api/worker_config.php'; }

$workerId = trim((string)($_GET['worker_id'] ?? ''));
if($workerId===''){
  $workerId = 'wrk-' . substr(bin2hex(random_bytes(4)),0,8);
}

$lines = [];
if($base !== '') $lines[] = 'BASE_URL=' . $base;
if($secret !== '') $lines[] = 'INTERNAL_SECRET=' . $secret;
$lines[] = 'WORKER_ID=' . $workerId;
if($pull>0) $lines[] = 'PULL_INTERVAL_SEC=' . $pull;
$lines[] = 'HEADLESS=' . $headless;
if($maxPages>0) $lines[] = 'MAX_PAGES=' . $maxPages;
if($lease>0) $lines[] = 'LEASE_SEC=' . $lease;
if($repBatch>0) $lines[] = 'REPORT_BATCH_SIZE=' . $repBatch;
if($repEvery>0) $lines[] = 'REPORT_EVERY_MS=' . $repEvery;
$lines[] = 'ITEM_DELAY_MS=' . $itemDelay;
if($confUrl !== ''){ $lines[] = 'WORKER_CONF_URL=' . $confUrl; $lines[] = 'WORKER_CONF_CODE=' . $confCode; }

$env = implode("\n", $lines) . "\n";
$fname = 'worker.env';
header('Content-Disposition: attachment; filename="'.$fname.'"');
echo $env;
exit;
?>
