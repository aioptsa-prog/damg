<?php
require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$q = isset($argv[1]) ? (string)$argv[1] : '';
if(mb_strlen($q) < 2){ echo json_encode(['error'=>'query_too_short']); exit; }

$base = 'http://127.0.0.1:8080';
$cookieFile = __DIR__ . '/../storage/tmp/cookies.txt';
@mkdir(dirname($cookieFile), 0777, true);
@unlink($cookieFile);

// Bootstrap session and get CSRF
$ch = curl_init($base.'/api/dev_session_bootstrap.php');
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEJAR=>$cookieFile,CURLOPT_COOKIEFILE=>$cookieFile]);
$raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
if($code!==200){ echo json_encode(['error'=>'bootstrap_failed','http_code'=>$code]); exit; }
$csrf = (json_decode($raw,true)['csrf'] ?? '');
if(!$csrf){ echo json_encode(['error'=>'no_csrf']); exit; }

$url = $base.'/api/category_search.php?q='.rawurlencode($q).'&limit=5&csrf='.rawurlencode($csrf);
$ch = curl_init($url);
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEJAR=>$cookieFile,CURLOPT_COOKIEFILE=>$cookieFile]);
$body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
if($code!==200){ echo json_encode(['error'=>'http_'.$code]); exit; }
$rows = json_decode($body,true);
$out = [];
foreach(array_slice($rows,0,5) as $r){
  $out[] = [
    'id' => (int)$r['id'],
    'path' => (string)($r['path'] ?? $r['name']),
    'icon' => $r['icon'] ?? ['type'=>'fa','value'=>'fa-folder-tree']
  ];
}
echo json_encode(['q'=>$q,'suggestions'=>$out], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),"\n";
