<?php
// Burst test for api/category_search.php with session + CSRF bootstrap
require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$base = 'http://127.0.0.1:8080';
$cookieFile = __DIR__ . '/../storage/tmp/cookies.txt';
@mkdir(dirname($cookieFile), 0777, true);
@unlink($cookieFile);

// 1) Bootstrap session and get CSRF
$ch = curl_init($base.'/api/dev_session_bootstrap.php');
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEJAR=>$cookieFile,CURLOPT_COOKIEFILE=>$cookieFile]);
$raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
if($code!==200){ echo json_encode(['error'=>'bootstrap_failed','http_code'=>$code,'raw'=>$raw]); exit; }
$resp = json_decode($raw,true); $csrf = $resp['csrf'] ?? '';
if(!$csrf){ echo json_encode(['error'=>'no_csrf']); exit; }

// 2) Fire 35 requests (auto CSRF)
$q = isset($argv[1]) ? (string)$argv[1] : 'Coffee';
$limit = 10;
$count200 = 0; $count429 = 0; $samples = [];
for($i=0;$i<35;$i++){
  $url = $base.'/api/category_search.php?q='.rawurlencode($q).'&limit='.$limit.'&csrf='.rawurlencode($csrf);
  $ch = curl_init($url);
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_COOKIEJAR=>$cookieFile,CURLOPT_COOKIEFILE=>$cookieFile]);
  $resp = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  // Split headers and body (first blank line)
  $parts = preg_split("/\r?\n\r?\n/", (string)$resp, 2);
  $hdrs = $parts[0] ?? '';
  $body = $parts[1] ?? '';
  if($c===200){
    $count200++;
    if(count($samples)<2){
      $decoded = json_decode($body,true);
      $samples[] = is_array($decoded) ? $decoded : ['body_sample'=>substr($body,0,200)];
    }
  } elseif($c===429){
    $count429++;
    if(count($samples)<2){ $samples[] = ['headers'=>substr($hdrs,0,256),'body'=>substr($body,0,200)]; }
  }
  usleep(20_000); // 20ms between requests
}

echo json_encode([
  'q'=>$q,
  'count_2xx'=>$count200,
  'count_429'=>$count429,
  'samples'=>array_slice($samples,0,2),
], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),"\n";
