<?php
// Smoke test: per-worker command ack loop
// Usage: php tools/tests/ack_loop_smoke.php <worker_id> [base_url]
// Default base_url: http://127.0.0.1:8080

require_once __DIR__ . '/../../bootstrap.php';

$wid = isset($argv[1]) ? trim((string)$argv[1]) : '';
$base = isset($argv[2]) ? trim((string)$argv[2]) : '';
if($base===''){ $base = rtrim((string)(get_setting('worker_base_url','') ?: 'http://127.0.0.1:8080'), '/'); }
if($wid===''){
  fwrite(STDERR, "Usage: php tools/tests/ack_loop_smoke.php <worker_id> [base_url]\n");
  exit(2);
}

$pdo = db();
$secret = (string)get_setting('internal_secret','');
if($secret===''){
  fwrite(STDERR, "internal_secret is empty; set it in settings first.\n");
  exit(2);
}

// 1) Create a pending per-worker command
$rev = time();
$cmd = 'pause';
try{
  $raw = get_setting('worker_commands_json','{}');
  $map = json_decode($raw, true); if(!is_array($map)) $map = [];
  $map[$wid] = [ 'command'=>$cmd, 'rev'=>$rev ];
  set_setting('worker_commands_json', json_encode($map, JSON_UNESCAPED_UNICODE));
  echo "set pending: $wid cmd=$cmd rev=$rev\n";
}catch(Throwable $e){ fwrite(STDERR, "failed to set pending: ".$e->getMessage()."\n"); exit(1); }

// 2) POST to /api/worker_metrics.php with last_applied_command_rev=rev and proper HMAC headers
$path = '/api/worker_metrics.php';
$body = json_encode([
  'worker_id' => $wid,
  'version' => 'test-ack',
  'connected' => true,
  'paused' => false,
  'active' => false,
  'uptimeSec' => 1,
  'last_applied_command_rev' => $rev,
]);
// HMAC: method|path|sha256(body)|ts
$ts = (string)time();
$bodySha = hash('sha256', $body);
$toSign = 'POST|'.$path.'|'.$bodySha.'|'.$ts;
$sign = hash_hmac('sha256', $toSign, $secret);
$headers = [
  'Content-Type: application/json',
  'X-Worker-Id: '.$wid,
  'X-Internal-Secret: '.$secret,
  'X-Auth-Ts: '.$ts,
  'X-Auth-Sign: '.$sign,
];
$url = $base.$path;
$ctx = stream_context_create([
  'http'=>[
    'method'=>'POST',
    'header'=>implode("\r\n", $headers),
    'content'=>$body,
    'ignore_errors'=>true,
    'timeout'=>8,
  ]
]);
$resp = @file_get_contents($url, false, $ctx);
$httpOk = false; $httpCode = 0;
if(isset($http_response_header) && is_array($http_response_header)){
  foreach($http_response_header as $line){ if(preg_match('/^HTTP\/\S+\s+(\d{3})/i', $line, $m)){ $httpCode = (int)$m[1]; $httpOk = ($httpCode>=200 && $httpCode<300); break; } }
}
$jr = json_decode($resp, true);
if(!$httpOk){
  fwrite(STDERR, "metrics http error code=$httpCode body=".substr((string)$resp,0,200)."\n");
  exit(1);
}
if(!$jr || empty($jr['ok'])){
  fwrite(STDERR, "metrics returned non-ok body=".substr((string)$resp,0,200)."\n");
  exit(1);
}

// 3) Verify the pending command was cleared
$raw2 = get_setting('worker_commands_json','{}');
$map2 = json_decode($raw2, true); if(!is_array($map2)) $map2 = [];
if(isset($map2[$wid])){
  fwrite(STDERR, "FAIL: pending command still present after ack. entry=".json_encode($map2[$wid])."\n");
  exit(1);
}

echo "PASS: ack cleared pending command for $wid (rev=$rev)\n";
exit(0);
