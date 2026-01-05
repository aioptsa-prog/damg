<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/categories.php';
header('Content-Type: application/json; charset=utf-8');

$cid = isset($argv[1]) ? (int)$argv[1] : 0;
if($cid<=0){ echo json_encode(['error'=>'missing_category_id']); exit; }

$pdo = db();
$ids = category_get_descendant_ids($cid);
if(empty($ids)) $ids = [$cid];
$ph = [];$params=[];
foreach($ids as $i=>$id){ $k=":cid$i"; $ph[]=$k; $params[$k]=(int)$id; }

$sql = "SELECT l.id,l.name,l.phone,l.city,l.country,l.created_at,l.category_id, c.name as category_name, c.slug as category_slug, c.path as category_path FROM leads l LEFT JOIN categories c ON c.id=l.category_id WHERE l.category_id IN (".implode(',', $ph).") ORDER BY l.id DESC LIMIT 5";
$st = $pdo->prepare($sql);
foreach($params as $k=>$v){ $st->bindValue($k,$v); }
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['row_count'=>count($rows),'rows'=>$rows], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),"\n";
