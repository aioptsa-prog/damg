<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/system.php';

$u = current_user();
if(!$u || $u['role']!=='admin'){ http_response_code(403); echo 'forbidden'; exit; }

$wid = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
if($wid===''){ http_response_code(400); echo 'missing id'; exit; }

if(session_status() === PHP_SESSION_ACTIVE){ @session_write_close(); }
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');
// Try to disable server-side buffering/compression for SSE
@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', '0');
if(function_exists('apache_setenv')){ @apache_setenv('no-gzip', '1'); }
@set_time_limit(0);
@ignore_user_abort(true);

$pdo = db();
$logPath = __DIR__ . '/../storage/logs/workers/'.preg_replace('/[^A-Za-z0-9_\-\.]/','_', $wid).'.log';

function fetchRow($pdo, $wid){
  $st = $pdo->prepare("SELECT worker_id, last_seen, info FROM internal_workers WHERE worker_id=? LIMIT 1");
  $st->execute([$wid]);
  $r = $st->fetch(PDO::FETCH_ASSOC) ?: null;
  if(!$r) return null;
  $info = null; try{ $info = $r['info']? json_decode($r['info'], true) : null; }catch(Throwable $e){ $info = null; }
  // Attach active job meta if available
  $active = null;
  try{
    $jid = is_array($info) && isset($info['active_job_id']) ? (int)$info['active_job_id'] : 0;
    if($jid){
      $sj = $pdo->prepare("SELECT id, attempt_id, lease_expires_at, last_progress_at FROM internal_jobs WHERE id=?");
      $sj->execute([$jid]); $active = $sj->fetch(PDO::FETCH_ASSOC) ?: null;
    }
  }catch(Throwable $e){ $active = null; }
  return [ 'worker_id'=>$r['worker_id'], 'last_seen'=>$r['last_seen'], 'info'=>$info, 'active_job'=>$active ];
}

function tail_log($path, $maxBytes=65536, $maxLines=300){
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

$lastJson = '';
echo "retry: 3000\n\n"; @ob_flush(); @flush();
// Prime client with a small padding to kick proxies
echo str_repeat(" ", 2048) . "\n"; @ob_flush(); @flush();

// Stream for up to 10 minutes per connection; client will auto-retry
$start = time();
while(!connection_aborted()){
  if((time()-$start) > 600){ break; }
  $row = fetchRow($pdo, $wid);
  $log = tail_log($logPath, 131072, 300);
  $payload = [ 'ok'=> (bool)$row, 'worker'=>$row, 'log_tail'=>$log, 'now'=>date('Y-m-d H:i:s') ];
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
  if($json !== $lastJson){ echo 'data: '.$json."\n\n"; $lastJson=$json; }
  // Heartbeat to keep connection alive even if unchanged
  echo ": ping\n\n";
  @ob_flush(); @flush();
  usleep(2000000);
}
exit;
?>
