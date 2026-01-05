<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/classify.php';
header('Content-Type: application/json; charset=utf-8');

$u = current_user();
if(!$u || $u['role']!=='admin'){
  http_response_code(403);
  echo json_encode(['error'=>'forbidden']);
  exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw,true);
if(!is_array($data)) $data = $_POST; // fallback for form-encoded

if(!csrf_verify($data['csrf'] ?? '')){
  http_response_code(400);
  echo json_encode(['error'=>'csrf']);
  exit;
}

$limit = max(1, min(1000, (int)($data['limit'] ?? 200)));
$onlyEmpty = !empty($data['only_empty']);
$override = !empty($data['override']);

$pdo = db();
$where = $onlyEmpty ? 'WHERE category_id IS NULL' : '';
$leads = $pdo->query("SELECT id, phone, name, city, country, website, email, gmap_types AS types, source_url, category_id FROM leads $where ORDER BY id DESC LIMIT ".$limit)->fetchAll();

$upd = $pdo->prepare("UPDATE leads SET category_id=:cid WHERE id=:id");
$updated=0; $processed=0; $skipped=0;
foreach($leads as $L){
  $processed++;
  $cls = classify_lead([
    'name'=>$L['name'] ?? '',
    'gmap_types'=>$L['types'] ?? '',
    'website'=>$L['website'] ?? '',
    'email'=>$L['email'] ?? '',
    'source_url'=>$L['source_url'] ?? '',
    'city'=>$L['city'] ?? '',
    'country'=>$L['country'] ?? '',
    'phone'=>$L['phone'] ?? '',
  ]);
  $cid = $cls['category_id'] ?? null;
  if($cid && ($override || (empty($L['category_id'])))){
    $upd->execute([':cid'=>$cid, ':id'=>$L['id']]);
    $updated++;
  } else {
    $skipped++;
  }
}

$remaining = null;
if($onlyEmpty){
  $remaining = (int)$pdo->query("SELECT COUNT(*) c FROM leads WHERE category_id IS NULL")->fetch()['c'];
}

echo json_encode(['ok'=>true,'processed'=>$processed,'updated'=>$updated,'skipped'=>$skipped,'remaining'=>$remaining]);