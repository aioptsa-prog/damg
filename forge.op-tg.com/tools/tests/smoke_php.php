<?php
// tools/tests/smoke_php.php - quick API smoke checks
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../lib/security.php';

function http_json($method,$url,$bodyArr){
  $ch = curl_init($url);
  $body = $bodyArr? json_encode($bodyArr): '';
  $ts = time(); $path = parse_url($url, PHP_URL_PATH) ?: '/';
  $sign = hmac_sign($method,$path, hmac_body_sha256($body), $ts);
  $hdrs = [
    'Content-Type: application/json',
    'X-Auth-Ts: '.$ts,
    'X-Auth-Sign: '.$sign,
    'X-Worker-Id: smoke-php'
  ];
  curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$hdrs,CURLOPT_POSTFIELDS=>$body,CURLOPT_HEADER=>false, CURLOPT_TIMEOUT=>10]);
  $t0 = microtime(true); $resp = curl_exec($ch); $dt = (microtime(true)-$t0)*1000.0;
  $code = curl_getinfo($ch,CURLINFO_HTTP_CODE);
  $err = $resp===false ? curl_error($ch) : '';
  curl_close($ch); return [$code, $dt, $resp, $err];
}

$base = rtrim((string)(get_setting('worker_base_url','') ?: (getenv('SMOKE_BASE_URL')?:'http://127.0.0.1:8080')), '/');

$ok = true; $items = [];
// 1) health
[$c,$lat,$body,$err] = http_json('GET', $base.'/api/health.php', null);
$items[] = ['step'=>'health','code'=>$c,'lat_ms'=>round($lat,1)]; if($c!==200) $ok=false;
// 2) heartbeat
[$c,$lat,$body,$err] = http_json('POST', $base.'/api/heartbeat.php', ['ping'=>1]);
$items[] = ['step'=>'heartbeat','code'=>$c,'lat_ms'=>round($lat,1)]; if($c!==200) $ok=false;
// 3) pull_job no jobs
[$c,$lat,$body,$err] = http_json('POST', $base.'/api/pull_job.php', []);
$items[] = ['step'=>'pull_job','code'=>$c,'lat_ms'=>round($lat,1)]; if(!in_array($c,[200,204],true)) $ok=false;
// 4) replay 409 test
// Reuse same body + ts to trigger replay detection (if enabled)
$payload = json_encode([]);
$ts = time(); $path = '/api/pull_job.php'; $sign = hmac_sign('POST',$path, hmac_body_sha256($payload), $ts);
for($i=0;$i<2;$i++){
  $ch = curl_init($base.$path);
  curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>'POST',CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>[
    'Content-Type: application/json','X-Auth-Ts: '.$ts,'X-Auth-Sign: '.$sign,'X-Worker-Id: smoke-php'
  ],CURLOPT_POSTFIELDS=>$payload, CURLOPT_TIMEOUT=>10]);
  $resp = curl_exec($ch); $code = curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
  if($i===1){ $items[] = ['step'=>'replay_second_call','code'=>$code]; if($code!==409){ /* not fatal */ } }
}

echo json_encode(['ok'=>$ok,'base'=>$base,'steps'=>$items], JSON_UNESCAPED_SLASHES)."\n";
exit($ok?0:1);
