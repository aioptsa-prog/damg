<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/system.php';
require_once __DIR__ . '/../lib/security.php';
// Set content type header unless running in unit test after output started
if(!(defined('UNIT_TEST') && UNIT_TEST) || !headers_sent()){
    header('Content-Type: application/json; charset=utf-8');
}

// Safe header accessor for built-in PHP server compatibility
$hdr = function(string $name){
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return $_SERVER[$key] ?? null;
};

$enabled = get_setting('internal_server_enabled','0')==='1';
$secretHeader = $hdr('X-Internal-Secret') ?? '';
$workerId = $hdr('X-Worker-Id') ?? '';
$workerInfoHdr = $hdr('X-Worker-Info') ?? null; // optional JSON with runtime info
$internalSecret  = get_setting('internal_secret', '');
if (!$enabled) {
        http_response_code(403);
        echo json_encode(['error' => 'internal_disabled']);
    if(defined('UNIT_TEST') && UNIT_TEST){ return; } else { exit; }
}
// Auth: HMAC or legacy secret or per-worker secret
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/api/heartbeat.php', PHP_URL_PATH) ?: '/api/heartbeat.php';
if (!verify_worker_auth($workerId, $method, $path)) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    if(defined('UNIT_TEST') && UNIT_TEST){ return; } else { exit; }
}
// Replay guard
try{
    $raw = file_get_contents('php://input');
    $ts  = (int)($_SERVER['HTTP_X_AUTH_TS'] ?? 0);
    $sha = hmac_body_sha256($raw);
    if(!hmac_replay_check_ok((string)$workerId, $method, $path, $ts, $sha)){
        http_response_code(409);
        echo json_encode(['error'=>'replay_detected']);
        if(defined('UNIT_TEST') && UNIT_TEST){ return; } else { exit; }
    }
}catch(Throwable $e){}

if($workerId){
    $info = ['ua'=>($_SERVER['HTTP_USER_AGENT']??null)];
    if($workerInfoHdr){
        try{
            $decoded = json_decode($workerInfoHdr, true);
            if(is_array($decoded)){ $info = array_merge($info, $decoded); }
        }catch(Throwable $e){}
    }
    // Backfill friendly fields for dashboards
    if(!isset($info['status'])) $info['status'] = 'online';
    if(!isset($info['version']) && isset($info['ver'])) $info['version'] = $info['ver'];
    workers_upsert_seen($workerId, $info);
}
$stopped = system_is_globally_stopped() || system_is_in_pause_window();
echo json_encode([
    'ok'=>true,
    'time'=>gmdate('c'),
    'stopped'=>$stopped,
    'pause'=>[
        'enabled'=>get_setting('system_pause_enabled','0')==='1',
        'start'=>get_setting('system_pause_start','23:59'),
        'end'=>get_setting('system_pause_end','09:00')
    ]
]);
if(defined('UNIT_TEST') && UNIT_TEST){ return; } else { exit; }
