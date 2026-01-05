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

$lead = [
  'name' => trim((string)($data['name'] ?? '')),
  'gmap_types' => trim((string)($data['types'] ?? '')),
  'website' => trim((string)($data['website'] ?? '')),
  'email' => trim((string)($data['email'] ?? '')),
  'source_url' => trim((string)($data['source_url'] ?? '')),
  'city' => trim((string)($data['city'] ?? '')),
  'country' => trim((string)($data['country'] ?? '')),
  'phone' => preg_replace('/\D+/','', (string)($data['phone'] ?? '')),
];

$cls = classify_lead($lead);
$catName = null;
if(!empty($cls['category_id'])){
  $pdo = db();
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