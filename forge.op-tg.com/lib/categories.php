<?php
require_once __DIR__.'/../config/db.php';

function category_get_path(int $categoryId): ?string{
  try{
    $pdo = db();
    $path = [];
    $cur = $categoryId;
    $guard = 0;
    while($cur && $guard++ < 50){
      $st = $pdo->prepare("SELECT id,name,parent_id FROM categories WHERE id=? LIMIT 1");
      $st->execute([$cur]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if(!$row) break;
      array_unshift($path, (string)$row['name']);
      $cur = $row['parent_id'] ?? null;
    }
    return count($path)>0 ? implode(' / ', $path) : null;
  }catch(Throwable $e){ return null; }
}

function category_get_descendant_ids(int $categoryId): array{
  $ids = [$categoryId];
  try{
    $pdo = db();
    $queue = [$categoryId];
    $guard = 0;
    while(!empty($queue) && $guard++ < 10000){
      $id = array_shift($queue);
      $st = $pdo->prepare("SELECT id FROM categories WHERE parent_id=? AND is_active=1");
      $st->execute([$id]);
      while($row = $st->fetch(PDO::FETCH_ASSOC)){
        $cid = (int)$row['id'];
        if(!in_array($cid, $ids, true)){
          $ids[] = $cid; $queue[] = $cid;
        }
      }
    }
  }catch(Throwable $e){}
  return $ids;
}

function category_get_keywords(int $categoryId): array{
  try{
    $pdo = db();
    $st = $pdo->prepare("SELECT keyword FROM category_keywords WHERE category_id=? ORDER BY keyword ASC");
    $st->execute([$categoryId]);
    $out=[]; while($r=$st->fetch(PDO::FETCH_ASSOC)){ $out[]=(string)$r['keyword']; }
    return $out;
  }catch(Throwable $e){ return []; }
}

function category_get_templates(int $categoryId): array{
  try{
    $pdo = db();
    $st = $pdo->prepare("SELECT template FROM category_query_templates WHERE category_id=? ORDER BY id ASC");
    $st->execute([$categoryId]);
    $out=[]; while($r=$st->fetch(PDO::FETCH_ASSOC)){ $out[]=(string)$r['template']; }
    return $out;
  }catch(Throwable $e){ return []; }
}
