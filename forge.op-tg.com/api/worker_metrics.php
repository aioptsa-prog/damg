<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/system.php';
require_once __DIR__ . '/../lib/security.php';

// Auth: internal only (secret or HMAC)
$hdr = function(string $name){ $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name)); return $_SERVER[$key] ?? null; };
$enabled = get_setting('internal_server_enabled','0')==='1';
$secretHeader = $hdr('X-Internal-Secret') ?? '';
$workerId = $hdr('X-Worker-Id') ?? '';
if(!$enabled){ http_response_code(403); echo json_encode(['error'=>'internal_disabled']); exit; }
$method = $_SERVER['REQUEST_METHOD'] ?? 'POST';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/api/worker_metrics.php', PHP_URL_PATH) ?: '/api/worker_metrics.php';
$internalSecret = get_setting('internal_secret','');
if (!verify_worker_auth($workerId, $method, $path)) { http_response_code(401); echo json_encode(['error'=>'unauthorized']); exit; }

// Read payload
header('Content-Type: application/json; charset=utf-8');
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true) ?: [];
if(!$workerId){ $workerId = (string)($payload['worker_id'] ?? ''); }
if($workerId===''){ http_response_code(400); echo json_encode(['error'=>'missing_worker_id']); exit; }

// Upsert worker with metrics in info
try{
  $info = [
    'ver' => (string)($payload['version'] ?? ''),
    'host'=> (string)($payload['host'] ?? ''),
    'status'=>'online',
    'metrics'=> [
      'connected'=> !!($payload['connected'] ?? false),
      'active'=> !!($payload['active'] ?? false),
      'paused'=> !!($payload['paused'] ?? false),
      'armed'=> !!($payload['armed'] ?? true),
      'uptimeSec'=> (int)($payload['uptimeSec'] ?? 0),
      'todayAdded'=> (int)($payload['todayAdded'] ?? 0),
      'totalAdded'=> (int)($payload['totalAdded'] ?? 0),
      'jobsDoneToday'=> (int)($payload['jobsDoneToday'] ?? 0),
      'jobsDoneTotal'=> (int)($payload['jobsDoneTotal'] ?? 0),
      'lastReport'=> $payload['lastReport'] ?? null,
      'lastAppliedCommandRev'=> isset($payload['last_applied_command_rev']) ? (int)$payload['last_applied_command_rev'] : null,
    ]
  ];
  if(isset($payload['lastJob']) && is_array($payload['lastJob'])){ $info['metrics']['lastJob'] = $payload['lastJob']; }
  workers_upsert_seen($workerId, $info);

  // Command acknowledgment loop: clear per-worker pending command when acked
  try{
    $ack = isset($payload['last_applied_command_rev']) ? (int)$payload['last_applied_command_rev'] : 0;
    if($ack > 0){
      $raw = get_setting('worker_commands_json','{}');
      $map = json_decode($raw, true); if(!is_array($map)) $map = [];
      if(isset($map[$workerId]) && is_array($map[$workerId])){
        $cur = isset($map[$workerId]['rev']) ? (int)$map[$workerId]['rev'] : 0;
        if($cur > 0 && $ack >= $cur){
          unset($map[$workerId]);
          set_setting('worker_commands_json', json_encode($map, JSON_UNESCAPED_UNICODE));
          // Optional: audit log for ack
          try{ $pdo = db(); $pdo->prepare("INSERT INTO audit_logs(user_id,action,target,payload,created_at) VALUES(?,?,?,?,datetime('now'))")
              ->execute([0,'worker_command_ack','worker:'.$workerId,json_encode(['ack'=>$ack], JSON_UNESCAPED_UNICODE)]); }catch(\Throwable $e){}
        }
      }
    }
  }catch(\Throwable $e){ /* ignore ack errors to not impact main metrics path */ }

  // Optional: store log tail
  if(!empty($payload['log_tail']) && is_string($payload['log_tail'])){
    $dir = __DIR__ . '/../storage/logs/workers';
    if(!is_dir($dir)) @mkdir($dir, 0777, true);
    $file = $dir.'/'.preg_replace('/[^A-Za-z0-9_\-\.]/','_', $workerId).'.log';
    // Keep last chunk only: overwrite file with the last ~20 lines to avoid growth
    $lines = preg_split('/\r?\n/', $payload['log_tail']);
    $tail = implode("\n", array_slice($lines, -200));
    @file_put_contents($file, $tail."\n");
  }

  // Renew lease for the worker's active job (best-effort)
  try{
    $extend = max(60, (int)get_setting('worker_pull_interval_sec','120'));
    $leaseUntil = date('Y-m-d H:i:s', time()+$extend);
    $pdo = db();
    // If attempt_id provided, restrict renewal to current attempt only
    $attemptId = isset($payload['attempt_id']) ? (string)$payload['attempt_id'] : null;
    if($attemptId){
      $st = $pdo->prepare("UPDATE internal_jobs SET lease_expires_at=:t, updated_at=datetime('now') WHERE status='processing' AND worker_id=:w AND attempt_id=:a");
      $st->execute([':t'=>$leaseUntil, ':w'=>$workerId, ':a'=>$attemptId]);
    } else {
      $pdo->prepare("UPDATE internal_jobs SET lease_expires_at=:t, updated_at=datetime('now') WHERE status='processing' AND worker_id=:w")
          ->execute([':t'=>$leaseUntil, ':w'=>$workerId]);
    }
  }catch(Throwable $e){ /* ignore */ }

  echo json_encode(['ok'=>true]);
}catch(Throwable $e){ http_response_code(500); echo json_encode(['error'=>'server_error']); }
exit;
?>
