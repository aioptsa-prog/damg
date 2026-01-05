<?php
// Dev-only helper: set a central command for workers via worker_config.php
// Usage: /api/dev_worker_command.php?cmd=pause&rev=2
// In production, protect this or use admin UI to set settings directly.
require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

// Allow only localhost to use this endpoint for safety in dev
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if($ip !== '127.0.0.1' && $ip !== '::1'){
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'forbidden']);
  exit;
}

try{
  $cmd = isset($_GET['cmd']) ? trim((string)$_GET['cmd']) : '';
  if($cmd===''){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'missing cmd']); exit; }
  $rev = isset($_GET['rev']) ? (int)$_GET['rev'] : ((int)get_setting('worker_command_rev','0') + 1);
  set_setting('worker_command', $cmd);
  set_setting('worker_command_rev', (string)$rev);
  echo json_encode(['ok'=>true,'command'=>$cmd,'command_rev'=>$rev], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
