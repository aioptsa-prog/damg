<?php
require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$u = current_user();
if(!$u || $u['role']!=='admin'){
  http_response_code(403);
  echo json_encode(['error'=>'forbidden']);
  exit;
}

if($_SERVER['REQUEST_METHOD']!=='POST'){
  http_response_code(405); echo json_encode(['error'=>'method_not_allowed']); exit;
}

if(!csrf_verify($_POST['csrf'] ?? '')){
  http_response_code(400); echo json_encode(['error'=>'csrf']); exit;
}

$json = $_POST['json'] ?? '';
if($json===''){
  http_response_code(400); echo json_encode(['error'=>'missing_json']); exit;
}

$data = json_decode($json, true);
if(!$data || !is_array($data)){
  http_response_code(400); echo json_encode(['error'=>'invalid_json']); exit;
}

$pdo = db();
$pdo->beginTransaction();
// Optional replace mode: clear existing keywords and rules first (safe: categories are preserved)
$replace = !empty($_POST['replace']);
if($replace){
  $pdo->exec('DELETE FROM category_keywords');
  $pdo->exec('DELETE FROM category_rules');
}
try{
  $catCreated=0; $kwInserted=0; $ruleInserted=0;
  // Insert/ensure categories by name; preserve hierarchy by matching parent by name when possible
  $mapNameToId = [];
  $selCat = $pdo->prepare('SELECT id FROM categories WHERE name=?');
  $insCat = $pdo->prepare('INSERT INTO categories(parent_id, name, created_at) VALUES(?,?,datetime(\'now\'))');
  if(!empty($data['categories']) && is_array($data['categories'])){
    // First pass: ensure all names exist without parent
    foreach($data['categories'] as $c){
      $name = trim((string)($c['name'] ?? ''));
      if($name==='') continue;
      $selCat->execute([$name]); $row=$selCat->fetch();
      if($row){ $mapNameToId[$name] = (int)$row['id']; }
      else { $insCat->execute([null, $name]); $mapNameToId[$name] = (int)$pdo->lastInsertId(); $catCreated++; }
    }
    // Second pass: update parents
    $updPar = $pdo->prepare('UPDATE categories SET parent_id=? WHERE id=?');
    foreach($data['categories'] as $c){
      $name = trim((string)($c['name'] ?? ''));
      $pname = null;
      // Accept {parent: 'اسم'} or {parent_name: 'اسم'} or use mapping from export
      if(isset($c['parent'])) $pname = trim((string)$c['parent']);
      if(!$pname && isset($c['parent_name'])) $pname = trim((string)$c['parent_name']);
      if(!$pname && isset($c['parent_id'])){
        // Try map parent_id -> parent name using categories payload
        foreach($data['categories'] as $cand){ if(($cand['id']??null)===($c['parent_id']??null) && !empty($cand['name'])){ $pname = $cand['name']; break; } }
      }
      $cid = $mapNameToId[$name] ?? null; $pid = $pname ? ($mapNameToId[$pname] ?? null) : null;
      if($cid){ $updPar->execute([$pid, $cid]); }
    }
  }

  // Keywords
  if(!empty($data['keywords']) && is_array($data['keywords'])){
    $insKw = $pdo->prepare('INSERT INTO category_keywords(category_id, keyword, created_at) VALUES(?,?,datetime(\'now\'))');
    foreach($data['keywords'] as $k){
      $kw = trim((string)($k['keyword'] ?? '')); if($kw==='') continue;
      $cid = null; $cname=null;
      if(isset($k['category'])) $cname = trim((string)$k['category']);
      if(!$cname && isset($k['category_name'])) $cname = trim((string)$k['category_name']);
      if(!$cname && isset($k['category_id'])){
        // Try resolve from categories payload
        if(!empty($data['categories']) && is_array($data['categories'])){
          foreach($data['categories'] as $c){ if(($c['id']??null)===($k['category_id']??null) && !empty($c['name'])){ $cname = $c['name']; break; } }
        }
      }
      if($cname){ $cid = $mapNameToId[$cname] ?? null; }
      if(!$cid) continue;
      $insKw->execute([$cid, $kw]); $kwInserted++;
    }
  }

  // Rules
  if(!empty($data['rules']) && is_array($data['rules'])){
    $insRule = $pdo->prepare('INSERT INTO category_rules(category_id, target, pattern, match_mode, weight, note, enabled, created_at) VALUES(?,?,?,?,?,?,?,datetime(\'now\'))');
    foreach($data['rules'] as $r){
      $target = trim((string)($r['target'] ?? 'name'));
      $pattern = trim((string)($r['pattern'] ?? ''));
      $mode = trim((string)($r['match_mode'] ?? 'contains'));
      $weight = (float)($r['weight'] ?? 1.0);
      $enabled = isset($r['enabled']) ? (int)!!$r['enabled'] : 1;
      $cname = null;
      if(isset($r['category'])) $cname = trim((string)$r['category']);
      if(!$cname && isset($r['category_name'])){ $cname = trim((string)$r['category_name']); }
      if(!$cname && isset($r['category_id'])){
        if(!empty($data['categories']) && is_array($data['categories'])){
          foreach($data['categories'] as $c){ if(($c['id']??null)===($r['category_id']??null) && !empty($c['name'])){ $cname = $c['name']; break; } }
        }
      }
      $cid = $cname ? ($mapNameToId[$cname] ?? null) : null;
      if(!$cid || $pattern==='') continue;
      $insRule->execute([$cid, $target, $pattern, $mode, $weight, trim((string)($r['note'] ?? '')), $enabled]); $ruleInserted++;
    }
  }

  $pdo->commit();
  // Log summary to storage/logs
  try{
    $logDir = __DIR__ . '/../storage/logs'; if(!is_dir($logDir)) @mkdir($logDir,0777,true);
    $who = (int)($u['id'] ?? 0);
    $line = sprintf("[%s] import_classification replace=%s cats_created=%d kw=%d rules=%d by_user=%d\n", date('c'), $replace?'1':'0', $catCreated, $kwInserted, $ruleInserted, $who);
    file_put_contents($logDir.'/classification_import.log', $line, FILE_APPEND);
  }catch(Throwable $e){}
  echo json_encode(['ok'=>true,'replace'=>$replace,'categories_created'=>$catCreated,'keywords'=>$kwInserted,'rules'=>$ruleInserted]);
}catch(Throwable $e){
  $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['error'=>'exception','message'=>$e->getMessage()]);
}