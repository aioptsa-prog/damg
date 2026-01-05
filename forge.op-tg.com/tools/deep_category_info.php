<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/categories.php';
header('Content-Type: application/json; charset=utf-8');

$pdo = db();
$hints = isset($argv[1]) ? (string)$argv[1] : '';

// Try to find a deep category (depth >=3) matching hints; else pick the deepest available
$cat = null;
if($hints !== ''){
  $st = $pdo->prepare("SELECT id, name, path, depth FROM categories WHERE COALESCE(depth,0)>=3 AND (name LIKE :h OR slug LIKE :h OR COALESCE(path,name) LIKE :h) ORDER BY depth DESC, id ASC LIMIT 1");
  $st->execute([':h'=>'%'.$hints.'%']);
  $cat = $st->fetch(PDO::FETCH_ASSOC);
}
if(!$cat){
  $cat = $pdo->query("SELECT id, name, path, depth FROM categories WHERE COALESCE(depth,0)>=3 ORDER BY depth DESC, id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
}
if(!$cat){ echo json_encode(['error'=>'no_deep_category_found']); exit; }

$catId = (int)$cat['id'];
$descIds = category_get_descendant_ids($catId);
$descCount = max(0, count($descIds) - 1);

// Sample up to 3 deep descendant paths (prefer deeper ones)
$samples = [];
try{
  $st = $pdo->prepare("SELECT path, depth FROM categories WHERE id IN (".implode(',', array_map('intval',$descIds)).") ORDER BY depth DESC, path ASC LIMIT 20");
  $st->execute();
  while(($r = $st->fetch(PDO::FETCH_ASSOC)) && count($samples)<3){
    if((int)$r['depth'] >= ((int)$cat['depth'] + 2)) $samples[] = (string)$r['path'];
  }
  // If we didn't reach 3, fill with whatever is next
  $st->execute();
  while(count($samples)<3 && ($r = $st->fetch(PDO::FETCH_ASSOC))){ $samples[] = (string)$r['path']; }
}catch(Throwable $e){}

echo json_encode([
  'id' => $catId,
  'path' => $cat['path'] ?? $cat['name'],
  'depth' => (int)$cat['depth'],
  'descendants_count' => $descCount,
  'deep_samples' => $samples,
], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), "\n";
