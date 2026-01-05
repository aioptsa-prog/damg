<?php
require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../lib/csrf.php';
$u = require_role('admin');

if($_SERVER['REQUEST_METHOD']!=='POST'){ http_response_code(405); echo 'Method Not Allowed'; exit; }
if(!csrf_verify($_POST['csrf'] ?? '')){ http_response_code(400); echo 'CSRF failed'; exit; }
$batch = trim((string)($_POST['batch_id'] ?? ''));
if($batch===''){ http_response_code(400); echo 'missing batch_id'; exit; }

$pdo = db();
$limit = 50000;
// Count first to protect large outputs
$stc = $pdo->prepare('SELECT COUNT(*) c FROM places WHERE batch_id=?');
$stc->execute([$batch]);
$total = (int)($stc->fetch()['c'] ?? 0);
if($total > $limit){
  header('Content-Type: text/plain; charset=utf-8');
  http_response_code(413);
  echo "Batch too large (".$total.") for inline export. Please narrow your query or use CLI export later.";
  exit;
}

$st = $pdo->prepare("SELECT place_id, name, phone, address, lat, lng, website, types_json, source, source_url, collected_at, last_seen_at, batch_id FROM places WHERE batch_id=? ORDER BY id ASC");
$st->execute([$batch]);

$fname = 'places_'.preg_replace('/[^A-Za-z0-9_\-]/','_', $batch).'.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$fname.'"');
header('Pragma: no-cache'); header('Expires: 0');

$out = fopen('php://output','w');
// UTF-8 BOM for Excel friendliness
fwrite($out, "\xEF\xBB\xBF");
// header row
fputcsv($out, ['place_id','name','phone','address','lat','lng','website','types_json','source','source_url','collected_at','last_seen_at','batch_id']);
while($row = $st->fetch(PDO::FETCH_NUM)){
  fputcsv($out, $row);
}
fflush($out); fclose($out);
exit;
