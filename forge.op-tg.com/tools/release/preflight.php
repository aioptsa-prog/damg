<?php
require_once __DIR__ . '/../../bootstrap.php';

// CLI only
if (php_sapi_name() !== 'cli') { header('Content-Type: text/plain; charset=utf-8'); }

$ok = true; $errors = []; $notes = [];

// DB connectivity
try{ $pdo = db(); }catch(Throwable $e){ $ok=false; $errors[]='DB_CONNECT: '.$e->getMessage(); }

// Required tables
$needTables = ['hmac_replay','rate_limit','internal_jobs','internal_workers','leads','settings'];
if(isset($pdo)){
  try{
    $have=[]; $rs=$pdo->query("SELECT name FROM sqlite_master WHERE type='table'");
    while($r=$rs->fetch(PDO::FETCH_ASSOC)){ $have[]=$r['name']; }
    foreach($needTables as $t){ if(!in_array($t,$have,true)){ $ok=false; $errors[]='TABLE_MISSING: '.$t; } }
  }catch(Throwable $e){ $ok=false; $errors[]='DB_SCHEMA: '.$e->getMessage(); }
}

// Settings flags
$mustBeOne = ['force_https','security_csrf_auto','rate_limit_basic','per_worker_secret_required'];
foreach($mustBeOne as $k){ $v=get_setting($k,'0'); if($v!=='1'){ $ok=false; $errors[]='SETTING_REQUIRED: '.$k.'=1 (current='.$v.')'; } }

// Internal secrets
if(get_setting('internal_server_enabled','0')!=='1'){ $ok=false; $errors[]='SETTING_REQUIRED: internal_server_enabled=1'; }
if(get_setting('internal_secret','')===''){ $ok=false; $errors[]='SETTING_REQUIRED: internal_secret (HMAC) must be set'; }

// Verify latest.json integrity and file
function find_latest_json(): ?string {
  $base = dirname(__DIR__,2);
  $cands = [
    $base.'/releases/latest.json',
    $base.'/storage/releases/latest.json',
  ];
  foreach($cands as $p){ if(is_file($p)) return $p; }
  return null;
}
$lj = find_latest_json();
$strictInstaller = (getenv('PREFLIGHT_STRICT_INSTALLER')==='1') || (get_setting('enable_self_update','0')==='1');
if(!$lj){ $notes[]='LATEST_JSON: not found'; }
else{
  $raw = @file_get_contents($lj); $j = json_decode((string)$raw,true);
  if(!is_array($j)){
    if($strictInstaller){ $ok=false; $errors[]='LATEST_JSON: invalid JSON'; } else { $notes[]='LATEST_JSON: invalid JSON (non-strict)'; }
  }
  if(is_array($j)){
    $sha=$j['sha256']??''; $size=(int)($j['size']??0);
    if((!$sha||!$size)){
      if($strictInstaller){ $ok=false; $errors[]='LATEST_JSON: missing sha256/size'; } else { $notes[]='LATEST_JSON: missing sha256/size (non-strict)'; }
    }
    // Try locate installer by meta
    $exeCands = glob(dirname(__DIR__,2).'/worker/build/*Worker_Setup*.exe') ?: [];
    $exe = null; $bestM=-1; foreach($exeCands as $p){ if(is_file($p)){ $m=@filemtime($p)?:0; if($m>$bestM){ $bestM=$m; $exe=$p; }}}
    if($exe){
      $fs = filesize($exe); $fsha = hash_file('sha256',$exe);
      if($size && $fs && $size!==$fs){ if($strictInstaller){ $ok=false; $errors[]='INSTALLER_SIZE_MISMATCH: latest.json='.$size.' file='.$fs; } else { $notes[]='INSTALLER_SIZE_MISMATCH (non-strict)'; } }
      if($sha && $fsha && strtolower($sha)!==strtolower($fsha)){ if($strictInstaller){ $ok=false; $errors[]='INSTALLER_SHA_MISMATCH'; } else { $notes[]='INSTALLER_SHA_MISMATCH (non-strict)'; } }
    } else { $notes[]='INSTALLER_FILE: not found to cross-check'; }
  }
}

// Provider keys light check (presence only)
$providers = ['google_api_key','foursquare_api_key','mapbox_api_key','radar_api_key'];
foreach($providers as $p){ $v=get_setting($p,''); if($v===''){ $notes[]='PROVIDER_KEY_MISSING: '.$p; } }

// CSP phase-0 advisory: if CSP contains 'unsafe-inline' and no nonce observed during runtime headers
try{
  // Best-effort: detect from system.php configuration since we can't read response headers here
  $nonceFn = function_exists('csp_nonce');
  if($nonceFn){
    // phase-0 expected: unsafe-inline present with nonce available
    $notes[] = 'CSP: nonce support detected (phase-0); unsafe-inline still present — plan removal after rollout';
  } else {
    $notes[] = 'CSP: nonce not detected and unsafe-inline likely present — consider enabling nonce (phase-0)';
  }
}catch(Throwable $e){ /* ignore */ }

// Output
if($ok){ echo "PRE-FLIGHT: PASS\n"; } else { echo "PRE-FLIGHT: FAIL\n"; }
foreach($errors as $e){ echo "- $e\n"; }
foreach($notes as $n){ echo "~ $n\n"; }
exit($ok?0:1);
require_once __DIR__ . '/../../bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');

$ok = true; $errors = [];

try{
  $pdo = db();
}catch(Throwable $e){
  $ok = false; $errors[] = 'DB_CONNECT: '.$e->getMessage();
}

$needTables = [
  'users','settings','leads','internal_jobs','internal_workers',
  'idempotency_keys','hmac_replay','rate_limit'
];
if(isset($pdo)){
  try{
    $have = [];
    $rs = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'");
    while($r=$rs->fetch(PDO::FETCH_ASSOC)){ $have[] = $r['name']; }
    foreach($needTables as $t){ if(!in_array($t, $have, true)){ $ok=false; $errors[]='TABLE_MISSING: '.$t; } }
  }catch(Throwable $e){ $ok=false; $errors[]='DB_SCHEMA: '.$e->getMessage(); }
}

// Settings checks
$flags = [
  'force_https','security_csrf_auto','rate_limit_basic','per_worker_secret_required'
];
foreach($flags as $k){
  $v = get_setting($k,'0');
  if($v !== '1') $errors[] = 'SETTING_RECOMMENDED: '.$k.'=1 (current='.$v.')';
}

$internalEnabled = get_setting('internal_server_enabled','0');
if($internalEnabled !== '1'){ $errors[] = 'SETTING_REQUIRED: internal_server_enabled=1'; }
$internalSecret = get_setting('internal_secret','');
if($internalSecret === ''){ $errors[] = 'SETTING_REQUIRED: internal_secret not set'; }

// Result
if($ok && empty(array_filter($errors, fn($e)=> str_starts_with($e,'TABLE_MISSING') || str_starts_with($e,'DB_') || str_starts_with($e,'DB_CONNECT') ))){
  echo "PRE-FLIGHT: PASS\n";
} else {
  echo "PRE-FLIGHT: FAIL\n";
}
if($errors){ foreach($errors as $e){ echo "- $e\n"; } }
exit($ok ? 0 : 1);
