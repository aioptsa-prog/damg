<?php
// Capture ingestion evidence: run ingest_probe, then dump today's usage_counters to JSON files under storage/logs/validation.
if (php_sapi_name() !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
require_once __DIR__ . '/../../bootstrap.php';

$dir = __DIR__ . '/../../storage/logs/validation';
if(!is_dir($dir)) @mkdir($dir,0777,true);
$probeOut = $dir . '/ingestion_probe.json';
$countersOut = $dir . '/usage_counters_today.json';

try{
  // Run the probe programmatically by including it in a sub-process using php -f to keep isolation
  $cmd = PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/ingest_probe.php');
  $json = shell_exec($cmd);
  if(!$json){ throw new RuntimeException('probe_no_output'); }
  file_put_contents($probeOut, $json);
  $data = json_decode($json, true);
  if(!is_array($data) || empty($data['ok'])){ throw new RuntimeException('probe_failed'); }

  // Dump today's usage_counters snapshot (ingest_* only)
  $pdo = db();
  $rows = $pdo->query("SELECT day, kind, count FROM usage_counters WHERE day = date('now') AND kind LIKE 'ingest_%' ORDER BY kind")->fetchAll(PDO::FETCH_ASSOC);
  file_put_contents($countersOut, json_encode(['ok'=>true,'rows'=>$rows], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));

  echo json_encode(['ok'=>true,'probe'=>$probeOut,'counters'=>$countersOut], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit(0);
}catch(Throwable $e){ fwrite(STDERR, $e->getMessage()."\n"); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); exit(2); }
