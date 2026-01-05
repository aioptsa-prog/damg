<?php include __DIR__ . '/../layout_header.php'; require_once __DIR__ . '/../lib/system.php'; $u=require_role('admin'); $pdo=db();

// Utilities
function cslug($s){ $s=trim(mb_strtolower($s)); $s=preg_replace('/[\s_]+/u','-',$s); $s=preg_replace('/[^\p{L}\p{N}\-]+/u','',$s); $s=trim($s,'-'); if($s===''){ $s='cat-'.substr(sha1(uniqid('',true)),0,6); } return $s; }
function node_get_name(array $node){
  $n = $node['name'] ?? ($node['name_ar'] ?? ($node['name_en'] ?? ''));
  $n = is_string($n) ? trim($n) : '';
  if($n==='') $n = 'الفئة';
  return $n;
}
function now(){ return date('Y-m-d H:i:s'); }
function load_seed_tree(){
  $file = __DIR__.'/../docs/taxonomy_seed.json';
  if(is_file($file)){
    $j = json_decode(file_get_contents($file), true);
    if(is_array($j)) return $j;
  }
  // Fallback minimal sample
  return [
    'name_ar'=>'جذر','name_en'=>'Root','slug'=>'root','icon'=>['type'=>'fa','value'=>'fa-folder-tree'], 'keywords'=>[], 'children'=>[]
  ];
}
function parse_icon($icon){
  // Accept either {type,value} or string class
  if(is_array($icon)){
    $t = $icon['type'] ?? 'fa'; $v = $icon['value'] ?? '';
    if($t!=='fa' && $t!=='img') $t='fa';
    if(!$v) $v='fa-folder-tree';
    return [$t,$v];
  }
  if(is_string($icon) && $icon!=='') return ['fa',$icon];
  return ['fa','fa-folder-tree'];
}

function dry_run_calculate(&$node, $parentSlugPath=''){ $ins=0;$upd=0;$skip=0;$kws=0; $q = function($slug){ $st=db()->prepare("SELECT id,name,slug FROM categories WHERE slug=?"); $st->execute([$slug]); return $st->fetch(PDO::FETCH_ASSOC); };
  $slug = $node['slug'] ?? cslug(node_get_name($node)); $exists = $q($slug);
  if($exists){ $skip++; } else { $ins++; }
  if(!empty($node['keywords']) && is_array($node['keywords'])){ $kws += count($node['keywords']); }
  if(!empty($node['children']) && is_array($node['children'])){
    foreach($node['children'] as &$ch){ $r = dry_run_calculate($ch, $parentSlugPath?($parentSlugPath.'/'.$slug):$slug); $ins+=$r[0]; $upd+=$r[1]; $skip+=$r[2]; $kws+=$r[3]; }
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
    // Update parent if changed (avoid cycles by deferring to path recalc at end)
    $upd = $pdo->prepare("UPDATE categories SET name=:n, parent_id=:p, is_active=1, updated_at=datetime('now') WHERE id=:id");
    $upd->execute([':n'=>$name, ':p'=>$parentId, ':id'=>$ex['id']]);
    $log[]=['action'=>'seed.update','category_id'=>(int)$ex['id'],'batch_id'=>$batchId,'payload'=>['slug'=>$slug]];
    return (int)$ex['id'];
  } else {
    $ins = $pdo->prepare("INSERT INTO categories(parent_id,name,slug,is_active,created_by_user_id,created_at,updated_at) VALUES(?,?,?,?,?,?,datetime('now'))");
    $ins->execute([$parentId,$name,$slug,1,$userId,date('Y-m-d H:i:s')]);
    $cid = (int)$pdo->lastInsertId();
    // Icon
    if(isset($node['icon'])){
      [$it,$iv] = parse_icon($node['icon']);
      try{ $pdo->prepare("UPDATE categories SET icon_type=:t, icon_value=:v WHERE id=:id")->execute([':t'=>$it, ':v'=>$iv, ':id'=>$cid]); }catch(Throwable $e){}
    }
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
  // BFS using queue for batching
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

$tab = $_GET['tab'] ?? 'dry'; $act = $_POST['act'] ?? '';
$msg=null; $warn=null; $summary=null;

if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!csrf_verify($_POST['csrf'] ?? '')){ $warn='CSRF فشل'; }
  else {
    if($act==='dry'){
      $tree = load_seed_tree(); $s = dry_run_calculate($tree);
      $summary = ['dry_run'=>true,'insert'=>$s[0],'update'=>$s[1],'skip'=>$s[2],'keywords'=>$s[3]];
    } else if($act==='run'){
      $tree = load_seed_tree(); $batchId = 'seed-'.date('YmdHis').'-'.substr(sha1(random_bytes(8)),0,6);
      $stats=['inserted_or_updated'=>0,'keywords'=>0]; $log=[];
      $pdo->beginTransaction();
      try{
        walk_and_apply($pdo, $tree, null, $u['id'], $batchId, $stats, $log, 500);
        $pdo->commit();
        recalc_paths($pdo);
        // Log actions
        $insLog = $pdo->prepare("INSERT INTO category_activity_log(action, category_id, user_id, details, created_at) VALUES(?,?,?,?,datetime('now'))");
        foreach($log as $e){ $insLog->execute([$e['action'],$e['category_id'], $u['id'], json_encode(['batch_id'=>$batchId]+$e['payload'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]); }
        $summary = ['batch_id'=>$batchId] + $stats; $msg = 'تم التنفيذ: إدراج/تحديث='.$stats['inserted_or_updated'].'، كلمات='.$stats['keywords'];
      }catch(Throwable $e){ try{$pdo->rollBack();}catch(Throwable $e2){} $warn='فشل التنفيذ: '.$e->getMessage(); }
    } else if($act==='rollback'){
      // Find last batch for this user
      $row = $pdo->query("SELECT substr(details, instr(details, 'seed-')) AS d, id, details FROM category_activity_log WHERE user_id=".(int)$u['id']." AND action='seed.insert' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
      if(!$row){ $warn='لا توجد دفعة حديثة'; }
      else {
        $det = json_decode($row['details']??'{}', true); $batchId = $det['batch_id'] ?? null;
        if(!$batchId){ $warn='تعذر تحديد BatchId'; }
        else {
          $pdo->beginTransaction();
          try{
            // Collect inserted category IDs for this batch
            $st = $pdo->prepare("SELECT category_id FROM category_activity_log WHERE user_id=? AND action='seed.insert' AND json_extract(details,'$.batch_id')=?");
            $st->execute([$u['id'],$batchId]); $ids = array_map('intval', array_column($st->fetchAll(PDO::FETCH_ASSOC),'category_id'));
            // Delete keywords first
            if($ids){ $pdo->exec("DELETE FROM category_keywords WHERE category_id IN (".implode(',', $ids).")"); }
            // Delete categories (children first by depth)
            if($ids){ $pdo->exec("DELETE FROM categories WHERE id IN (".implode(',', $ids).")"); }
            $pdo->commit();
            $pdo->prepare("INSERT INTO category_activity_log(action,user_id,details,created_at) VALUES('seed.rollback',?, ?, datetime('now'))")
                ->execute([$u['id'], json_encode(['batch_id'=>$batchId,'deleted_ids'=>$ids], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
            $msg='تمت الإزالة للدفعة '.$batchId;
          }catch(Throwable $e){ try{$pdo->rollBack();}catch(Throwable $e2){} $warn='فشل التراجع: '.$e->getMessage(); }
        }
      }
    }
  }
}
?>
<div class="card">
  <h2>Seeder — تصنيفات احترافية</h2>
  <?php if($warn): ?><p class="badge danger"><?php echo htmlspecialchars($warn); ?></p><?php endif; ?>
  <?php if($msg): ?><p class="badge"><?php echo htmlspecialchars($msg); ?></p><?php endif; ?>
  <div class="tabs">
    <a class="btn <?php echo ($tab==='dry')?'primary':''; ?>" href="?tab=dry">Dry-Run</a>
    <a class="btn <?php echo ($tab==='run')?'primary':''; ?>" href="?tab=run">Run</a>
    <a class="btn <?php echo ($tab==='rollback')?'primary':''; ?>" href="?tab=rollback">Rollback</a>
  </div>
  <div class="card">
    <?php if($tab==='dry'): ?>
      <form method="post">
        <?php echo csrf_input(); ?>
        <input type="hidden" name="act" value="dry">
        <button class="btn">تشغيل Dry-Run</button>
      </form>
      <?php if($summary): ?>
        <div class="muted">Insert: <?php echo (int)$summary['insert']; ?> · Update: <?php echo (int)$summary['update']; ?> · Skip: <?php echo (int)$summary['skip']; ?> · Keywords: <?php echo (int)$summary['keywords']; ?></div>
      <?php endif; ?>
    <?php elseif($tab==='run'): ?>
      <form method="post" onsubmit="return confirm('تشغيل عملية Seed بالدفعات؟');">
        <?php echo csrf_input(); ?>
        <input type="hidden" name="act" value="run">
        <button class="btn primary">تشغيل Run</button>
      </form>
      <?php if($summary): ?>
        <pre class="code-block"><?php echo htmlspecialchars(json_encode($summary, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)); ?></pre>
      <?php endif; ?>
    <?php else: ?>
      <form method="post" onsubmit="return confirm('حذف آخر دفعة؟ لا رجعة.');">
        <?php echo csrf_input(); ?>
        <input type="hidden" name="act" value="rollback">
        <button class="btn danger">Rollback آخر Batch</button>
      </form>
    <?php endif; ?>
  </div>
  <div class="muted">المصدر: docs/taxonomy_seed.json (إن وجد) أو نموذج داخلي صغير.</div>
</div>
<?php include __DIR__ . '/../layout_footer.php'; ?>
