<?php
// Create a dev job and report a small set of results twice to test category fill + duplicates
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/security.php';
header('Content-Type: application/json; charset=utf-8');

$base = 'http://127.0.0.1:8080';
$cid = isset($argv[1]) ? (int)$argv[1] : 0;
$userQuery = isset($argv[2]) ? (string)$argv[2] : 'Dermatology';
if($cid<=0){ echo json_encode(['error'=>'missing_category_id']); exit; }

// 1) Enable internal server (local-only)
$r = http_get_json($base.'/api/dev_enable_internal.php?confirm=1');
if(!$r||empty($r['ok'])){ echo json_encode(['error'=>'enable_internal_failed']); exit; }

// 2) Create job via dev_add_job_noauth
$jobResp = http_get_json($base.'/api/dev_add_job_noauth.php?q='.rawurlencode($userQuery).'&target=5');
if(!$jobResp || empty($jobResp['job_id'])){ echo json_encode(['error'=>'job_create_failed']); exit; }
$jobId = (int)$jobResp['job_id'];

// Compose items (include duplicates on same phones)
$phones = ['0551234501','0551234502','0551234503','0551234501'];
$items = [];
foreach($phones as $i=>$p){
  $items[] = [
    'name' => $userQuery.' Clinic #'.($i+1),
    'city' => 'Riyadh',
    'country' => 'SA',
    'phone' => $p,
    'gmap_types' => 'doctor,health,clinic',
  ];
}

$payload = [
  'job_id' => $jobId,
  'category_id' => $cid,
  'items' => $items,
  'cursor' => 4,
  'done' => true,
];
$raw = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

function sign_headers($method,$path,$raw){
  $ts = (string)time();
  $sha = hash('sha256',(string)$raw);
  $msg = strtoupper($method).'|'.$path.'|'.$sha.'|'.$ts;
  $sign = hash_hmac('sha256', $msg, hmac_secret());
  return [
    'X-Worker-Id: dev-worker-1',
    'X-Auth-Ts: '.$ts,
    'X-Auth-Sign: '.$sign,
    'Content-Type: application/json',
  ];
}

// 3) First report
$path = '/api/report_results.php';
$ch = curl_init($base.$path);
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$raw,CURLOPT_HTTPHEADER=>sign_headers('POST',$path,$raw)]);
$r1 = curl_exec($ch); $c1 = curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
$j1 = json_decode((string)$r1,true) ?: [];

// 4) Second report (same payload; expect added=0, duplicates>= first run)
$ch = curl_init($base.$path);
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$raw,CURLOPT_HTTPHEADER=>sign_headers('POST',$path,$raw)]);
$r2 = curl_exec($ch); $c2 = curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
$j2 = json_decode((string)$r2,true) ?: [];

// 5) Verify category fill for these phones
$pdo = db();
$cntFilled = (int)$pdo->query("SELECT COUNT(*) FROM leads WHERE phone_norm LIKE '96655%' AND category_id IS NOT NULL AND substr(name,1,10) LIKE 'Dermatolog' ")->fetchColumn();

echo json_encode([
  'job_id'=>$jobId,
  'first' => ['http'=>$c1, 'added'=>$j1['added']??null, 'duplicates'=>$j1['duplicates']??null],
  'second'=> ['http'=>$c2, 'added'=>$j2['added']??null, 'duplicates'=>$j2['duplicates']??null],
  'category_filled_count' => $cntFilled,
], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),"\n";
