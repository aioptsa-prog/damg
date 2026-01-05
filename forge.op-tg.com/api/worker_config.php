<?php
declare(strict_types=1);
// Centralized worker configuration endpoint
// The worker can optionally point to this URL (WORKER_CONF_URL) to fetch runtime config.
// Access is guarded by a simple shared code set in settings: worker_config_code

require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$codeRequired = trim(get_setting('worker_config_code',''));
$codeGiven = trim((string)($_GET['code'] ?? ''));
if ($codeRequired !== '' && !hash_equals($codeRequired, $codeGiven)) {
  http_response_code(403);
  echo json_encode(['error'=>'forbidden']);
  exit;
}

// Build config from current settings (no secrets)
$derivedBase = '';
try{
  $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? '';
  $script = $_SERVER['SCRIPT_NAME'] ?? '/api/worker_config.php';
  // App root is parent of /api
  $rootPath = rtrim(str_replace('\\','/', dirname(dirname($script))), '/');
  $derivedBase = ($host ? ($scheme.'://'.$host) : '') . ($rootPath ? $rootPath : '');
}catch(Throwable $e){ $derivedBase = ''; }
$cfg = [
  'base_url' => rtrim((string)(get_setting('worker_base_url','') ?: $derivedBase), '/'),
  'internal_enabled' => get_setting('internal_server_enabled','0')==='1',
  'pull_interval_sec' => (int)get_setting('worker_pull_interval_sec','30'),
  'headless' => get_setting('worker_headless','0') === '1',
  'until_end' => get_setting('worker_until_end','1') === '1',
  'max_pages' => (int)get_setting('worker_max_pages','5'),
  'lease_sec' => (int)get_setting('worker_lease_sec','180'),
  'report_batch_size' => (int)get_setting('worker_report_batch_size','10'),
  'report_every_ms' => (int)get_setting('worker_report_every_ms','15000'),
  'report_first_ms' => (int)get_setting('worker_report_first_ms','2000'),
  'item_delay_ms' => (int)get_setting('worker_item_delay_ms','800'),
  'update_channel' => (string)get_setting('worker_update_channel','stable'),
];
$cmdRev = (int)get_setting('worker_command_rev','0');
$cmd = trim((string)get_setting('worker_command',''));
if ($cmd !== '') { $cfg['command'] = $cmd; }
if ($cmdRev > 0) { $cfg['command_rev'] = $cmdRev; }
$chromeExe = trim((string)get_setting('worker_chrome_exe',''));
if ($chromeExe !== '') { $cfg['chrome_exe'] = $chromeExe; }
$chromeArgs = trim((string)get_setting('worker_chrome_args',''));
if ($chromeArgs !== '') { $cfg['chrome_args'] = $chromeArgs; }

// Try to capture worker id early for per-worker overrides/commands
$wid = '';
try{
  if(isset($_SERVER['HTTP_X_WORKER_ID'])){ $wid = trim((string)$_SERVER['HTTP_X_WORKER_ID']); }
  if($wid===''){
    // Fallback for testing/admin tools: allow ?worker_id= when header is absent
    $wid = isset($_GET['worker_id']) ? trim((string)$_GET['worker_id']) : '';
  }
}catch(Throwable $e){ $wid=''; }

// Apply per-worker config overrides (if any) and attach display name
try{
  if($wid !== ''){
    // Optional friendly display name
    try{
      $rawNames = get_setting('worker_name_overrides_json','{}');
      $mapNames = json_decode($rawNames, true);
      if(is_array($mapNames) && isset($mapNames[$wid]) && is_string($mapNames[$wid]) && $mapNames[$wid] !== ''){
        $cfg['display_name'] = (string)$mapNames[$wid];
      }
    }catch(Throwable $e){ /* ignore name map errors */ }
    $rawOv = get_setting('worker_config_overrides_json','{}');
    $mapOv = json_decode($rawOv, true);
    if(is_array($mapOv) && isset($mapOv[$wid]) && is_array($mapOv[$wid])){
      $ov = $mapOv[$wid];
      // Whitelist of allowed keys and simple type guards
      $bool = function($v){ return is_bool($v) ? $v : (is_string($v)? ($v==='1'||strtolower($v)==='true') : !!$v); };
      $num = function($v){ return (int)$v; };
      if(isset($ov['base_url'])){ $cfg['base_url'] = rtrim((string)$ov['base_url'],'/'); }
      if(array_key_exists('pull_interval_sec',$ov)) { $cfg['pull_interval_sec'] = max(1,$num($ov['pull_interval_sec'])); }
      if(array_key_exists('headless',$ov)) { $cfg['headless'] = $bool($ov['headless']); }
      if(array_key_exists('until_end',$ov)) { $cfg['until_end'] = $bool($ov['until_end']); }
      if(array_key_exists('max_pages',$ov)) { $cfg['max_pages'] = max(1,$num($ov['max_pages'])); }
      if(array_key_exists('lease_sec',$ov)) { $cfg['lease_sec'] = max(30,$num($ov['lease_sec'])); }
      if(array_key_exists('report_batch_size',$ov)) { $cfg['report_batch_size'] = max(1,$num($ov['report_batch_size'])); }
      if(array_key_exists('report_every_ms',$ov)) { $cfg['report_every_ms'] = max(200,$num($ov['report_every_ms'])); }
      if(array_key_exists('report_first_ms',$ov)) { $cfg['report_first_ms'] = max(100,$num($ov['report_first_ms'])); }
      if(array_key_exists('item_delay_ms',$ov)) { $cfg['item_delay_ms'] = max(0,$num($ov['item_delay_ms'])); }
      if(isset($ov['chrome_exe'])) { $cfg['chrome_exe'] = (string)$ov['chrome_exe']; }
      if(isset($ov['chrome_args'])) { $cfg['chrome_args'] = (string)$ov['chrome_args']; }
      if(isset($ov['update_channel'])){ $cfg['update_channel'] = (string)$ov['update_channel']; }
    }
  }
}catch(Throwable $e){ /* ignore override errors */ }

// ETag for caching
$etag = 'W/"'.md5(json_encode($cfg)).'"';
if ((isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag)) {
  header('ETag: '.$etag);
  http_response_code(304);
  exit;
}
header('ETag: '.$etag);
// Compute channel via rollout percentage (hash(worker_id) mod 100) before per-worker overrides
try{
  if($wid !== ''){
    // Canary rollout percent (0-100)
    $roll = (int)max(0, min(100, (int)get_setting('rollout_canary_percent','0')));
    if($roll > 0){
      // Use stable by default; assign canary if hash bucket within percent
      $bucket = (int)(hexdec(substr(sha1($wid), 0, 8)) % 100);
      if($bucket < $roll){ $cfg['update_channel'] = 'canary'; }
    }
    $raw = get_setting('worker_channel_overrides_json','{}');
    $map = json_decode($raw, true);
    if(is_array($map) && isset($map[$wid])){
      $ch = (string)$map[$wid];
  if($ch==='stable'||$ch==='canary'||$ch==='beta'||$ch==='dev'){ $cfg['update_channel'] = $ch; }
    }
    // Per-worker commands
    try{
      $rawCmd = get_setting('worker_commands_json','{}');
      $mapCmd = json_decode($rawCmd, true);
      if(is_array($mapCmd) && isset($mapCmd[$wid]) && is_array($mapCmd[$wid])){
        $entry = $mapCmd[$wid];
        $cmdStr = isset($entry['command']) ? trim((string)$entry['command']) : '';
        $revNum = isset($entry['rev']) ? (int)$entry['rev'] : 0;
        if($cmdStr !== ''){ $cfg['command'] = $cmdStr; }
        if($revNum > 0){ $cfg['command_rev'] = $revNum; }
      } else {
        // Fallback to global command if per-worker not set
        $cmdRev = (int)get_setting('worker_command_rev','0');
        $cmd = trim((string)get_setting('worker_command',''));
        if ($cmd !== '') { $cfg['command'] = $cmd; }
        if ($cmdRev > 0) { $cfg['command_rev'] = $cmdRev; }
      }
    }catch(Throwable $e){ /* ignore commands errors */ }
  }
}catch(Throwable $e){}
echo json_encode($cfg, JSON_UNESCAPED_UNICODE);
