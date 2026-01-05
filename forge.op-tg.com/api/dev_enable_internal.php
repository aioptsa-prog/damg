<?php
// Local-only helper to enable internal server quickly for development
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/system.php';
header('Content-Type: application/json; charset=utf-8');

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if(!in_array($ip, ['127.0.0.1','::1'])){ http_response_code(403); echo json_encode(['error'=>'local_only']); exit; }
if(($_GET['confirm'] ?? '') !== '1'){ http_response_code(400); echo json_encode(['error'=>'missing_confirm']); exit; }

set_setting('internal_server_enabled','1');
// Keep a predictable dev secret unless already set
$sec = get_setting('internal_secret','');
if($sec===''){ $sec = bin2hex(random_bytes(32)); set_setting('internal_secret', $sec); }
if(get_setting('worker_pull_interval_sec','')==='') set_setting('worker_pull_interval_sec','10');

echo json_encode(['ok'=>true,'internal_server_enabled'=>true,'internal_secret'=>$sec]);
