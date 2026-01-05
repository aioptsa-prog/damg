<?php
// tools/monitor/synthetic_alert.php
// Read the last synthetic result and send a webhook alert if ok=false.
require_once __DIR__ . '/../../bootstrap.php';

function setting_or_env(string $key, string $env, $def=''){
  $v = get_setting($key, null);
  if($v===null || $v===''){
    $e = getenv($env);
    return ($e===false || $e==='') ? $def : $e;
  }
  return $v;
}

$logPath = __DIR__ . '/../../storage/logs/synthetic.log';
if(!file_exists($logPath)){
  echo json_encode(['ok'=>true,'message'=>'no synthetic log yet'])."\n"; exit(0);
}
// Read last non-empty line
$fh = fopen($logPath, 'rb'); $last = '';
if($fh){
  fseek($fh, 0, SEEK_END); $pos = ftell($fh);
  $buf = '';
  while($pos>0){
    $read = max(1, min(4096, $pos));
    $pos -= $read; fseek($fh, $pos);
    $chunk = fread($fh, $read);
    $buf = $chunk . $buf;
    $lines = explode("\n", $buf);
    if(count($lines)>1){
      for($i=count($lines)-1; $i>=0; $i--){ if(trim($lines[$i])!==''){ $last = $lines[$i]; break 2; }
      }
    }
  }
  fclose($fh);
}
if($last===''){ echo json_encode(['ok'=>true,'message'=>'no entries'])."\n"; exit(0); }

$obj = json_decode($last, true);
if(!is_array($obj)){ echo json_encode(['ok'=>true,'message'=>'invalid last log'])."\n"; exit(0); }

if(!empty($obj['ok'])){ echo json_encode(['ok'=>true,'message'=>'last synthetic ok'])."\n"; exit(0); }

$webhook = setting_or_env('synthetic_alert_webhook', 'SYNTH_ALERT_WEBHOOK', '');
if($webhook===''){ echo json_encode(['ok'=>false,'message'=>'synthetic failed; no webhook configured'])."\n"; exit(2); }

// Prepare payload
$payload = [
  'event' => 'synthetic_failed',
  'time'  => $obj['time'] ?? gmdate('c'),
  'samples' => $obj['samples'] ?? [],
  'base_url' => get_setting('worker_base_url','')
];

// Send POST
$ch = curl_init($webhook);
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_POSTFIELDS => json_encode($payload),
  CURLOPT_HTTPHEADER => [ 'Content-Type: application/json' ],
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_TIMEOUT => 10,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
if($resp===false){ $err = curl_error($ch); curl_close($ch); echo json_encode(['ok'=>false,'message'=>'curl_error','detail'=>$err])."\n"; exit(3); }
curl_close($ch);

echo json_encode(['ok'=>($code>=200 && $code<300), 'status'=>$code])."\n";
exit(($code>=200 && $code<300)?0:4);
