<?php
// Export CSV via HTTP with admin session/CSRF; verify columns and show sample rows
require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$base = 'http://127.0.0.1:8080';
$cid = isset($argv[1]) ? (int)$argv[1] : 0;
if($cid<=0){ echo json_encode(['error'=>'missing_category_id']); exit; }

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

$url = $base.'/api/export_leads.php?category_id='.$cid.'&include_descendants=1&csrf='.rawurlencode($csrf);
$ch = curl_init($url);
curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEJAR=>$cookieFile,CURLOPT_COOKIEFILE=>$cookieFile]);
$csv = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
if($code!==200){ echo json_encode(['error'=>'export_http_'.$code]); exit; }

// Parse CSV: skip BOM and sep=, first line might be sep=,
$csv = preg_replace('/^\xEF\xBB\xBF/','',$csv);
$lines = preg_split('/\r?\n/',$csv,-1,PREG_SPLIT_NO_EMPTY);
if(!$lines){ echo json_encode(['error'=>'empty_csv']); exit; }
if(isset($lines[0]) && stripos($lines[0],'sep=')===0){ array_shift($lines); }
$header = str_getcsv($lines[0]);
$rows = [];
for($i=1;$i<count($lines);$i++){
  $row = str_getcsv($lines[$i]); if(!$row || count($row)<3) continue; $rows[] = $row;
}

// Check category columns
$hasName = in_array('category_name',$header,true);
$hasSlug = in_array('category_slug',$header,true);
$hasPath = in_array('category_path',$header,true);

// Build first two rows (mask sensitive fields like phone to last 3 digits)
$maskIdx = array_search('phone',$header,true);
$firstTwo = [];
foreach(array_slice($rows,0,2) as $r){
  if($maskIdx !== false && isset($r[$maskIdx])){
    $p = preg_replace('/\D+/','',$r[$maskIdx]);
    $r[$maskIdx] = $p === '' ? '' : str_repeat('•', max(0, strlen($p)-3)).substr($p,-3);
  }
  $firstTwo[] = $r;
}

echo json_encode([
  'columns' => $header,
  'has_category_columns' => ($hasName && $hasSlug && $hasPath),
  'row_count' => count($rows),
  'first_two_rows' => $firstTwo,
], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),"\n";
