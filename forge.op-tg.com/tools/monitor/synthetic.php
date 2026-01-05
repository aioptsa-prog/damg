<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../lib/security.php';

// Synthetic checks: health, heartbeat, pull_job (mock), report_results (mock)
$base = rtrim((string)(get_setting('worker_base_url','') ?: ''), '/');
if($base===''){
  // best-effort derive
  $host = getenv('SYNTH_BASE_URL') ?: 'http://127.0.0.1:8080';
  $base = rtrim($host,'/');
}
$wid = getenv('SYNTH_WORKER_ID') ?: 'synth-monitor';
$secret = get_setting('internal_secret','');

function http_json($method,$url,$bodyArr){
  $ch = curl_init($url);
  $body = $bodyArr? json_encode($bodyArr): '';
  $ts = time(); $path = parse_url($url, PHP_URL_PATH) ?: '/';
  $sign = hmac_sign($method,$path, hmac_body_sha256($body), $ts);
  $hdrs = [
    'Content-Type: application/json',
    'X-Auth-Ts: '.$ts,
    'X-Auth-Sign: '.$sign,
    'X-Worker-Id: '.(getenv('SYNTH_WORKER_ID')?:'synth-monitor')
  ];
  curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$hdrs,CURLOPT_POSTFIELDS=>$body,CURLOPT_HEADER=>true]);
  $t0 = microtime(true); $resp = curl_exec($ch); $dt = (microtime(true)-$t0)*1000.0;
  $code = curl_getinfo($ch,CURLINFO_HTTP_CODE); $hsz = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
  $hdr = substr((string)$resp,0,(int)$hsz); $bodyOut = substr((string)$resp,(int)$hsz);
  curl_close($ch); return [$code,$dt,$bodyOut,$hdr];
}

$targets = [
  ['GET','/api/health.php',null],
  ['POST','/api/heartbeat.php',['ping'=>1]],
  ['POST','/api/pull_job.php',['mock'=>1]],
  ['POST','/api/report_results.php',['mock'=>1,'job_id'=>0,'results'=>[]]],
];

$logPath = __DIR__ . '/../../storage/logs/synthetic.log';
@mkdir(dirname($logPath),0777,true);

$out = ['time'=>gmdate('c'),'ok'=>true,'samples'=>[]];
foreach($targets as $t){
  [$m,$p,$b] = $t; $url = $base.$p; try{
    [$code,$lat,$body,$hdr] = http_json($m,$url,$b);
    $item = ['m'=>$m,'p'=>$p,'code'=>$code,'lat_ms'=>round($lat,1)];
    // If HTTPS is enforced and base is http, we'll likely see 308/301 redirects; include Location for clarity
    if($code >= 300 && $code < 400){
      if(preg_match('/\nLocation:\s*([^\r\n]+)/i', (string)$hdr, $mm)){
        $item['redirect_to'] = trim($mm[1]);
      }
    }
    if($code>=500) $out['ok']=false;
    $out['samples'][] = $item;
  }catch(Throwable $e){ $out['ok']=false; $out['samples'][]=['m'=>$m,'p'=>$p,'code'=>0,'lat_ms'=>0]; }
}
file_put_contents($logPath, json_encode($out, JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);

// Emit alert event when synthetic fails
if(!$out['ok']){
  try{
    $pdo = db();
    $pdo->prepare("INSERT INTO alert_events(kind,message,payload,created_at) VALUES(?,?,?,datetime('now'))")
        ->execute(['synthetic_fail','Synthetic monitor detected failure', json_encode($out, JSON_UNESCAPED_SLASHES)]);
  }catch(Throwable $e){ /* best-effort */ }
}

echo json_encode($out, JSON_UNESCAPED_SLASHES)."\n";
