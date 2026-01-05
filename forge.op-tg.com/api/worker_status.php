<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/system.php';

$u = current_user();
if(!$u || $u['role']!=='admin'){ http_response_code(403); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error'=>'forbidden']); exit; }

$wid = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
if($wid===''){ http_response_code(400); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error'=>'missing id']); exit; }

header('Content-Type: application/json; charset=utf-8');
$pdo = db();
$logPath = __DIR__ . '/../storage/logs/workers/'.preg_replace('/[^A-Za-z0-9_\-\.]/','_', $wid).'.log';

function ws_fetchRow($pdo, $wid){
  $st = $pdo->prepare("SELECT worker_id, last_seen, info FROM internal_workers WHERE worker_id=? LIMIT 1");
  $st->execute([$wid]);
  $r = $st->fetch(PDO::FETCH_ASSOC) ?: null;
  if(!$r) return null;
  $info = null; try{ $info = $r['info']? json_decode($r['info'], true) : null; }catch(Throwable $e){ $info = null; }
  $active = null;
  try{
    $jid = (is_array($info) && isset($info['active_job_id'])) ? (int)$info['active_job_id'] : 0;
    if($jid){
      $sj = $pdo->prepare("SELECT id, attempt_id, lease_expires_at, last_progress_at FROM internal_jobs WHERE id=?");
      $sj->execute([$jid]); $active = $sj->fetch(PDO::FETCH_ASSOC) ?: null;
    }
  }catch(Throwable $e){ $active = null; }
  return [ 'worker_id'=>$r['worker_id'], 'last_seen'=>$r['last_seen'], 'info'=>$info, 'active_job'=>$active ];
}
function ws_tail_log($path, $maxBytes=65536, $maxLines=300){
  if(!@file_exists($path)) return '';
  $size = @filesize($path);
  if($size === false) return '';
  $start = max(0, $size - $maxBytes);
  $fh = @fopen($path, 'rb'); if(!$fh) return '';
  if($start > 0){ @fseek($fh, $start); }
  $data = @stream_get_contents($fh) ?: '';
  @fclose($fh);
  $lines = preg_split('/\r?\n/', $data);
  $tail = array_slice($lines, -$maxLines);
  return implode("\n", $tail);
}

$row = ws_fetchRow($pdo, $wid);
$log = ws_tail_log($logPath, 131072, 300);
echo json_encode([ 'ok'=> (bool)$row, 'worker'=>$row, 'log_tail'=>$log, 'now'=>date('Y-m-d H:i:s') ], JSON_UNESCAPED_UNICODE);
exit;
?>
