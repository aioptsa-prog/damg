<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/system.php';
header('Content-Type: application/json; charset=utf-8');

$u = current_user();
if(!$u || $u['role']!=='admin'){ http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }

$pdo = db();
$ids = [];
// Accept ids[] or comma-separated ids
if(isset($_GET['ids'])){
  if(is_array($_GET['ids'])){ foreach($_GET['ids'] as $v){ $v = trim((string)$v); if($v!=='') $ids[] = $v; } }
  else { $raw = trim((string)$_GET['ids']); foreach(explode(',', $raw) as $v){ $v = trim($v); if($v!=='') $ids[] = $v; } }
}
$ids = array_values(array_unique($ids));
if(!$ids){ echo json_encode(['ok'=>true,'list'=>[]]); exit; }
// Limit to avoid abuse
if(count($ids) > 300){ $ids = array_slice($ids, 0, 300); }

$cut = date('Y-m-d H:i:s', time() - workers_online_window_sec());
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("SELECT worker_id, last_seen, info FROM internal_workers WHERE worker_id IN ($placeholders)");
$stmt->execute($ids);
$rows = $stmt->fetchAll();

// Load pending commands map once
$cmdMap = [];
try{ $raw = get_setting('worker_commands_json','{}'); $map = json_decode($raw, true); if(is_array($map)) $cmdMap = $map; }catch(Throwable $e){ $cmdMap = []; }

$out = [];
foreach($rows as $r){
  $wid = (string)$r['worker_id'];
  $last_seen = (string)$r['last_seen'];
  $online = ($last_seen >= $cut);
  $ver = '';
  $paused=false; $active=false; $lastAppliedRev=null;
  try{
    $info = $r['info']? json_decode($r['info'], true) : null;
    if(is_array($info)){
      if(isset($info['ver'])) $ver = (string)$info['ver'];
      elseif(isset($info['version'])) $ver = (string)$info['version'];
      $m = isset($info['metrics']) && is_array($info['metrics']) ? $info['metrics'] : [];
      $paused = !empty($m['paused']);
      $active = !empty($m['active']);
      if(array_key_exists('lastAppliedCommandRev',$m)){
        $tmp = $m['lastAppliedCommandRev'];
        if($tmp!==null && $tmp!==''){ $lastAppliedRev = (int)$tmp; }
      }
    }
  }catch(Throwable $e){ }
  $pending = null;
  if(isset($cmdMap[$wid]) && is_array($cmdMap[$wid])){
    $pending = [ 'command' => (string)($cmdMap[$wid]['command'] ?? ''), 'rev' => (int)($cmdMap[$wid]['rev'] ?? 0) ];
  }
  $out[] = [
    'worker_id'=>$wid,
    'last_seen'=>$last_seen,
    'online'=>$online,
    'version'=>$ver,
    'paused'=>$paused,
    'active'=>$active,
    'pending'=>$pending,
    'last_applied_rev'=>$lastAppliedRev,
  ];
}

echo json_encode(['ok'=>true,'list'=>$out], JSON_UNESCAPED_UNICODE);
exit;
