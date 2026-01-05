<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/system.php';

// Admin-only SSE stream of workers snapshot for a given set of ids[]
$u = current_user();
if(!$u || $u['role']!=='admin'){
  http_response_code(403);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['error'=>'forbidden']);
  exit;
}

// SSE headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // for proxies/nginx if any
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
@apache_setenv('no-gzip', '1');

ignore_user_abort(true);
set_time_limit(0);

// we don't need session write lock during stream
if(function_exists('session_write_close')){ @session_write_close(); }

$pdo = db();

// Parse ids
$ids = [];
if(isset($_GET['ids'])){
  if(is_array($_GET['ids'])){ foreach($_GET['ids'] as $v){ $v = trim((string)$v); if($v!=='') $ids[] = $v; } }
  else { $raw = trim((string)$_GET['ids']); foreach(explode(',', $raw) as $v){ $v = trim($v); if($v!=='') $ids[] = $v; } }
}
$ids = array_values(array_unique($ids));
// Limit
if(count($ids) > 300){ $ids = array_slice($ids, 0, 300); }

// Polling interval (seconds)
$interval = max(5, min(30, intval($_GET['interval'] ?? 12)));
// Max stream duration ~ 2 minutes by default
$maxSeconds = max($interval * 4, min(600, intval($_GET['max'] ?? 120)));
$deadline = time() + $maxSeconds;

function sse_send($event, $data){
  echo 'event: ' . $event . "\n";
  $payload = is_string($data) ? $data : json_encode($data, JSON_UNESCAPED_UNICODE);
  echo 'data: ' . $payload . "\n\n";
  @ob_flush(); @flush();
}

function load_snapshot(PDO $pdo, array $ids){
  if(!$ids){ return ['ok'=>true,'list'=>[]]; }
  $cut = date('Y-m-d H:i:s', time() - workers_online_window_sec());
  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $stmt = $pdo->prepare("SELECT worker_id, last_seen, info FROM internal_workers WHERE worker_id IN ($placeholders)");
  $stmt->execute($ids);
  $rows = $stmt->fetchAll();
  // pending commands map
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
  return ['ok'=>true,'list'=>$out, 't'=>time()];
}

// Initial snapshot immediately so UI updates fast
sse_send('snapshot', load_snapshot($pdo, $ids));

while(time() < $deadline && !connection_aborted()){
  sleep($interval);
  sse_send('snapshot', load_snapshot($pdo, $ids));
}

// graceful end so client can reconnect
sse_send('eof', ['ok'=>true]);
exit;
