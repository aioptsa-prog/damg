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
if(!is_array($data)) $data = $_POST;
if(!csrf_verify($data['csrf'] ?? '')){
  http_response_code(400);
  echo json_encode(['error'=>'csrf']);
  exit;
}

$id = (int)($data['id'] ?? 0);
if($id<=0){ http_response_code(400); echo json_encode(['error'=>'missing_id']); exit; }

$pdo = db();
$L = $pdo->prepare("SELECT id, phone, name, city, country, website, email, gmap_types AS types, source_url, category_id FROM leads WHERE id=?");
$L->execute([$id]);
$L = $L->fetch();
if(!$L){ http_response_code(404); echo json_encode(['error'=>'not_found']); exit; }

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

$catName = null;
if(!empty($cls['category_id'])){
  $st=$pdo->prepare('SELECT name FROM categories WHERE id=?'); $st->execute([$cls['category_id']]);
  $row=$st->fetch(); $catName=$row ? $row['name'] : null;
}

echo json_encode([
  'ok'=>true,
  'score'=>$cls['score'] ?? 0,
  'category_id'=>$cls['category_id'] ?? null,
  'category_name'=>$catName,
  'threshold'=>(float)get_setting('classify_threshold','1.0'),
  'matched'=>$cls['matched'] ?? [],
]);