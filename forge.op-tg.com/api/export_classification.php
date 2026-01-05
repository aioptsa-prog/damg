<?php
require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$u = current_user();
if(!$u || $u['role']!=='admin'){
  http_response_code(403);
  echo json_encode(['error'=>'forbidden']);
  exit;
}

// Optional CSRF check via query param to mitigate CSRF in GET downloads
$csrf = $_GET['csrf'] ?? '';
if(!csrf_verify($csrf)){
  http_response_code(400);
  echo json_encode(['error'=>'csrf']);
  exit;
}

$pdo = db();
// Categories with parent_name for convenience
$cats = $pdo->query("SELECT c.id, c.parent_id, c.name, c.created_at, p.name AS parent_name FROM categories c LEFT JOIN categories p ON p.id=c.parent_id ORDER BY c.id ASC")->fetchAll();
// Keywords and rules with category_name
$kws = $pdo->query("SELECT k.category_id, c.name AS category_name, k.keyword, k.created_at FROM category_keywords k JOIN categories c ON c.id=k.category_id ORDER BY k.id ASC")->fetchAll();
$rules = $pdo->query("SELECT r.category_id, c.name AS category_name, r.target, r.pattern, r.match_mode, r.weight, r.note, r.enabled, r.created_at FROM category_rules r JOIN categories c ON c.id=r.category_id ORDER BY r.id ASC")->fetchAll();

$payload = [
  'exported_at' => date('c'),
  'categories' => $cats,
  'keywords' => $kws,
  'rules' => $rules,
];

echo json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);