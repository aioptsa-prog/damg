<?php
// Sends 35 requests to category_search.php with CSRF and summarizes results
require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$base = 'http://127.0.0.1:8080';
$cookieFile = __DIR__ . '/../storage/tmp/cookies.txt';
@mkdir(dirname($cookieFile), 0777, true);
@unlink($cookieFile);

// Bootstrap session and CSRF
$ch = curl_init($base.'/api/dev_session_bootstrap.php');
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEJAR=>$cookieFile,CURLOPT_COOKIEFILE=>$cookieFile]);
$raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
if($code!==200){ echo json_encode(['error'=>'bootstrap_failed','http_code'=>$code]); exit; }
$csrf = (json_decode($raw,true)['csrf'] ?? '');
if(!$csrf){ echo json_encode(['error'=>'no_csrf']); exit; }

$q = isset($argv[1]) ? (string)$argv[1] : 'Clinic';
$ok2xx = 0; $n429 = 0; $firstOk = null; $first429 = null;
for($i=0;$i<35;$i++){
  $url = $base.'/api/category_search.php?q='.rawurlencode($q).'&limit=5&csrf='.rawurlencode($csrf);
  $ch = curl_init($url);
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_COOKIEJAR=>$cookieFile,CURLOPT_COOKIEFILE=>$cookieFile]);
  $resp = curl_exec($ch); $c = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  if($c===200){ $ok2xx++; if($firstOk===null){ $firstOk = $resp; } }
  elseif($c===429){ $n429++; if($first429===null){ $first429 = $resp; } }
  usleep(20_000); // 20ms between requests
}

echo json_encode([
  'ok_2xx'=>$ok2xx,
  'n429'=>$n429,
  'first_ok_headers'=> $firstOk ? substr($firstOk,0,512) : null,
  'first_429_headers'=> $first429 ? substr($first429,0,512) : null,
  'first_429_body'=> $first429 ? substr(preg_replace('/^[\s\S]*\r\n\r\n/','',$first429),0,256) : null
], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),"\n";
