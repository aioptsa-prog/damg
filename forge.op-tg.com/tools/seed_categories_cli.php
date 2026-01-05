<?php
// CLI seeder for categories based on docs/taxonomy_seed.json
// Usage: php tools/seed_categories_cli.php dry|run

require_once __DIR__ . '/../bootstrap.php';
$mode = $argv[1] ?? 'dry';
$pdo = db();

function cslug($s){ $s=trim(mb_strtolower($s)); $s=preg_replace('/[\s_]+/u','-',$s); $s=preg_replace('/[^\p{L}\p{N}\-]+/u','',$s); $s=trim($s,'-'); if($s===''){ $s='cat-'.substr(sha1(uniqid('',true)),0,6);} return $s; }
function node_get_name(array $node){ $n=$node['name'] ?? ($node['name_ar'] ?? ($node['name_en'] ?? '')); $n = is_string($n)?trim($n):''; if($n==='') $n='الفئة'; return $n; }
function parse_icon($icon){ if(is_array($icon)){ $t=$icon['type']??'fa'; $v=$icon['value']??''; if($t!=='fa'&&$t!=='img') $t='fa'; if(!$v) $v='fa-folder-tree'; return [$t,$v]; } if(is_string($icon) && $icon!=='') return ['fa',$icon]; return ['fa','fa-folder-tree']; }
function load_seed_tree(){ $file = __DIR__.'/../docs/taxonomy_seed.json'; if(is_file($file)){ $j=json_decode(file_get_contents($file),true); if(is_array($j)) return $j; } fwrite(STDERR,"Seed file not found or invalid\n"); exit(2);} 

function dry_run_calculate(PDO $pdo, &$node){ $ins=0;$upd=0;$skip=0;$kws=0; $q=$pdo->prepare("SELECT id FROM categories WHERE slug=?");
  $slug = $node['slug'] ?? cslug(node_get_name($node)); $q->execute([$slug]); $ex=$q->fetch(PDO::FETCH_ASSOC);
  if($ex){ $skip++; } else { $ins++; }
  if(!empty($node['keywords']) && is_array($node['keywords'])){ $kws += count($node['keywords']); }
  if(!empty($node['children']) && is_array($node['children'])){
    foreach($node['children'] as &$ch){ $r = dry_run_calculate($pdo, $ch); $ins+=$r[0]; $upd+=$r[1]; $skip+=$r[2]; $kws+=$r[3]; }
  }
  return [$ins,$upd,$skip,$kws];
}

function recalc_paths(PDO $pdo){
  try{
    $all = $pdo->query("SELECT id,parent_id,name FROM categories")->fetchAll(PDO::FETCH_ASSOC);
    $map=[]; foreach($all as $r){ $map[(int)$r['id']] = ['p'=>$r['parent_id']? (int)$r['parent_id'] : null, 'name'=>$r['name']]; }
    $depths=[]; $paths=[];
    $calc = function($id) use (&$calc,&$map,&$depths,&$paths){ if(isset($depths[$id])) return $depths[$id]; $v=$map[$id]??null; if(!$v){ $depths[$id]=0; $paths[$id]=null; return 0; } $p=$v['p']; if($p && $p===$id){ $depths[$id]=0; $paths[$id]=$v['name']; return 0; } $d=0; $names=[$v['name']]; $guard=0; while($p && isset($map[$p]) && $guard++<50){ $d++; array_unshift($names,$map[$p]['name']); $np=$map[$p]['p']; if($np===$p){ break; } $p=$np; }
      $depths[$id]=$d; $paths[$id]=implode(' / ', $names); return $d; };
    foreach(array_keys($map) as $id){ $calc($id); }
    $pdo->beginTransaction();
    $st = $pdo->prepare("UPDATE categories SET depth=:d, path=:p, updated_at=datetime('now') WHERE id=:id");
    foreach($depths as $id=>$d){ $st->execute([':d'=>$d, ':p'=>$paths[$id]??null, ':id'=>$id]); }
    $pdo->commit();
  }catch(Throwable $e){ try{$pdo->rollBack();}catch(Throwable $e2){} }
}

function upsert_category(PDO $pdo, $node, $parentId, $userId, $batchId, &$log){
  $name = node_get_name($node);
  $slug = $node['slug'] ?? cslug($name);
  $st=$pdo->prepare("SELECT id,name,slug FROM categories WHERE slug=?"); $st->execute([$slug]); $ex=$st->fetch(PDO::FETCH_ASSOC);
  if($ex){
    $upd = $pdo->prepare("UPDATE categories SET name=:n, parent_id=:p, is_active=1, updated_at=datetime('now') WHERE id=:id");
    $upd->execute([':n'=>$name, ':p'=>$parentId, ':id'=>$ex['id']]);
    $log[]=['action'=>'seed.update','category_id'=>(int)$ex['id'],'batch_id'=>$batchId,'payload'=>['slug'=>$slug]];
    return (int)$ex['id'];
  } else {
    $ins = $pdo->prepare("INSERT INTO categories(parent_id,name,slug,is_active,created_by_user_id,created_at,updated_at) VALUES(?,?,?,?,?,?,datetime('now'))");
    $ins->execute([$parentId,$name,$slug,1,$userId,date('Y-m-d H:i:s')]);
    $cid = (int)$pdo->lastInsertId();
    if(isset($node['icon'])){ [$it,$iv] = parse_icon($node['icon']); try{ $pdo->prepare("UPDATE categories SET icon_type=:t, icon_value=:v WHERE id=:id")->execute([':t'=>$it, ':v'=>$iv, ':id'=>$cid]); }catch(Throwable $e){} }
    $log[]=['action'=>'seed.insert','category_id'=>$cid,'batch_id'=>$batchId,'payload'=>['slug'=>$slug]];
    return $cid;
  }
}

function insert_keywords(PDO $pdo, $cid, $node, &$log, $batchId){
  if(empty($node['keywords']) || !is_array($node['keywords'])) return 0;
  $count=0; $ins=$pdo->prepare("INSERT OR IGNORE INTO category_keywords(category_id,keyword,lang,created_at) VALUES(?,?,?,datetime('now'))");
  foreach($node['keywords'] as $kw){
    if(is_array($kw)){
      foreach($kw as $lang=>$word){ $lang = in_array($lang,['ar','en'])?$lang:'ar'; $ins->execute([$cid,trim($word),$lang]); $count++; }
    } else {
      $ins->execute([$cid,trim($kw),'ar']); $count++;
    }
  }
  if($count>0){ $log[]=['action'=>'seed.keywords','category_id'=>$cid,'batch_id'=>$batchId,'payload'=>['count'=>$count]]; }
  return $count;
}

function walk_and_apply(PDO $pdo, $node, $parentId, $userId, $batchId, &$stats, &$log, $batchLimit=500){
  $q=[[$node,$parentId]]; $processed=0;
  while($q){
    list($cur,$pid) = array_shift($q);
    $cid = upsert_category($pdo, $cur, $pid, $userId, $batchId, $log);
    $stats['inserted_or_updated']++;
    $stats['keywords'] += insert_keywords($pdo, $cid, $cur, $log, $batchId);
    $processed++;
    if($processed % $batchLimit === 0){ recalc_paths($pdo); }
    if(!empty($cur['children'])){ foreach($cur['children'] as $ch){ $q[] = [$ch,$cid]; } }
  }
}

$seed = load_seed_tree();
if($mode === 'dry'){
  $s = dry_run_calculate($pdo, $seed);
  echo json_encode(['dry_run'=>true,'insert'=>$s[0],'update'=>$s[1],'skip'=>$s[2],'keywords'=>$s[3]], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),"\n";
  exit(0);
}
if($mode === 'run'){
  $userId = 0; // system
  $batchId = 'seed-'.date('YmdHis').'-'.substr(sha1(random_bytes(8)),0,6);
  $stats=['inserted_or_updated'=>0,'keywords'=>0]; $log=[];
  try{
    // Insert without a single large transaction to avoid deferred FK edge-cases on some SQLite builds
    walk_and_apply($pdo, $seed, null, $userId, $batchId, $stats, $log, 500);
    recalc_paths($pdo);
    $insLog = $pdo->prepare("INSERT INTO category_activity_log(action, category_id, user_id, details, created_at) VALUES(?,?,?,?,datetime('now'))");
    foreach($log as $e){ $insLog->execute([$e['action'],$e['category_id'], $userId, json_encode(['batch_id'=>$batchId]+$e['payload'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]); }
    echo json_encode(['batch_id'=>$batchId] + $stats, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),"\n";
  }catch(Throwable $e){ try{$pdo->rollBack();}catch(Throwable $e2){} fwrite(STDERR, 'Failed: '.$e->getMessage()."\n"); exit(1);} 
  exit(0);
}

fwrite(STDERR, "Usage: php tools/seed_categories_cli.php dry|run\n");
exit(2);
