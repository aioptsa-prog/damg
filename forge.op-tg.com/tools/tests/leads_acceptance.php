<?php
// Leads pipeline acceptance test: enqueue job -> pull_job -> report_results -> replay/idempotency/dedup proofs
// Prints concise PASS/FAIL with evidence lines and DB/state snapshots.

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

$BASE = 'http://127.0.0.1:8080';

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../lib/security.php';

function out($msg){ echo $msg, "\n"; }
function hr(){ echo str_repeat('-', 72), "\n"; }

$pdo = db();
$now = date('Y-m-d H:i:s');

// 1) Baseline settings and seed users/worker
$internalSecret = get_setting('internal_secret','');
if($internalSecret===''){
  $internalSecret = 'acc-test-secret-'.bin2hex(random_bytes(4));
  $pdo->prepare("INSERT INTO settings(key,value) VALUES('internal_secret',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value")
      ->execute([$internalSecret]);
}
$pdo->prepare("INSERT INTO settings(key,value) VALUES('internal_server_enabled','1') ON CONFLICT(key) DO UPDATE SET value='1'")->execute();
$pdo->prepare("INSERT INTO settings(key,value) VALUES('per_worker_secret_required','0') ON CONFLICT(key) DO UPDATE SET value='0'")->execute();
// Prefer newest pick order to reduce interference from old stuck jobs during test
$pdo->prepare("INSERT INTO settings(key,value) VALUES('job_pick_order','newest') ON CONFLICT(key) DO UPDATE SET value='newest'")->execute();

// Seed admin and agent users if missing
function upsertUser($mobile,$name,$role){
  $pdo = db();
  $st = $pdo->prepare("SELECT id FROM users WHERE mobile=? LIMIT 1");
  $st->execute([$mobile]); $row = $st->fetch();
  if($row){ return (int)$row['id']; }
  $pdo->prepare("INSERT INTO users(mobile,name,role,password_hash,active,created_at) VALUES(?,?,?,?,1,datetime('now'))")
      ->execute([$mobile,$name,$role,password_hash($mobile,PASSWORD_BCRYPT)]);
  return (int)$pdo->lastInsertId();
}
$adminId = upsertUser('500000001','Acceptance Admin','admin');
$agentId = upsertUser('500000002','Acceptance Agent','agent');

$workerId = 'acc-test-worker-1';
try{
  $pdo->prepare("INSERT INTO internal_workers(worker_id,last_seen,status,host,version) VALUES(?,?,?,?,?) ON CONFLICT(worker_id) DO UPDATE SET last_seen=excluded.last_seen")
      ->execute([$workerId, $now, 'idle', php_uname('n'), 'acc-test']);
}catch(Throwable $e){}

// 2) Enqueue a job for the agent
$q = 'صالون نسائي اختبار';
$ll = '24.713600,46.675300';
$radius = 5;
$pdo->prepare("INSERT INTO internal_jobs(requested_by_user_id,role,agent_id,query,ll,radius_km,lang,region,status,priority,queued_at,created_at,updated_at)
  VALUES(?,?,?,?,?,?, 'ar','sa','queued', 999, datetime('now'), datetime('now'), datetime('now'))")
  ->execute([$adminId,'agent',$agentId,$q,$ll,$radius]);
$jobId = (int)$pdo->lastInsertId();
out("Enqueued job A id=$jobId for agent_id=$agentId");

// Helper: HMAC headers
function hmac_headers(string $method, string $path, string $body = ''): array{
  $ts = (string)time();
  $sha = hmac_body_sha256($body);
  $sig = hmac_sign($method, $path, $sha, $ts);
  return [
    'X-Auth-Ts: '.$ts,
    'X-Auth-Sign: '.$sig,
  ];
}

// Helper: HTTP client with headers
function http_req(string $url, string $method = 'GET', array $headers = [], ?string $body = null): array{
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
  if($body !== null){ curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  curl_setopt($ch, CURLOPT_HEADER, true);
  $resp = curl_exec($ch);
  if($resp === false){ $err = curl_error($ch); curl_close($ch); return ['code'=>0, 'headers'=>[], 'body'=>'', 'error'=>$err]; }
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
  $hraw = substr($resp, 0, $headerSize);
  $bodyOut = substr($resp, $headerSize);
  curl_close($ch);
  $headersOut = [];
  foreach(explode("\r\n", trim($hraw)) as $line){ if(strpos($line, ':')!==false){ [$k,$v] = array_map('trim', explode(':', $line, 2)); $headersOut[strtolower($k)] = $v; } }
  return ['code'=>$code, 'headers'=>$headersOut, 'body'=>$bodyOut];
}

// 3) pull_job (Job A)
$path = '/api/pull_job.php';
$hdrs = array_merge([
  'X-Worker-Id: '.$workerId,
], hmac_headers('GET', $path, ''));
$pull = http_req($BASE.$path, 'GET', $hdrs);
out("pull_job code={$pull['code']} body=".substr($pull['body'],0,200));
if($pull['code']!==200){ out('FAIL pull_job'); exit(1); }
$j = json_decode($pull['body'], true);
if(!$j || empty($j['job']['id'])){ out('FAIL pull_job parse'); exit(1); }
$jid = (int)$j['job']['id']; $attemptId = (string)($j['job']['attempt_id'] ?? '');
out("Pulled job id=$jid attempt_id=$attemptId");
// 4) report_results — Job A happy path with 2 unique items (no idempotency key)
$p1 = '05'.(string)random_int(500000000, 599999999);
$p2 = '+9665'.(string)random_int(500000000, 599999999);
// Expected normalized form for UI check
$norm1 = '966'.ltrim(preg_replace('/\D+/','',$p1), '0');
$norm2 = preg_replace('/\D+/','',$p2);
$itemsA = [
  [ 'name'=>'متجر اختبار ١', 'city'=>'الرياض', 'country'=>'sa', 'phone'=>$p1, 'provider'=>'osm', 'lat'=>24.7136, 'lng'=>46.6753 ],
  [ 'name'=>'Test Shop 2', 'city'=>'Riyadh', 'country'=>'sa', 'phone'=>$p2, 'provider'=>'osm', 'lat'=>24.7137, 'lng'=>46.6754 ],
];
$body1 = json_encode(['job_id'=>$jid, 'attempt_id'=>$attemptId, 'items'=>$itemsA, 'cursor'=>2, 'done'=>true, 'extend_lease_sec'=>120], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$path2 = '/api/report_results.php';
$hdrs2 = array_merge([
  'Content-Type: application/json',
  'X-Worker-Id: '.$workerId,
], hmac_headers('POST', $path2, $body1));
$rep1 = http_req($BASE.$path2.'?debug=1', 'POST', $hdrs2, $body1);
out("report_results#1 code={$rep1['code']} body={$rep1['body']}");
// Tolerate HTML error body by guarding JSON decode
$r1 = null; if($rep1['code']===200){ $r1 = json_decode($rep1['body'], true); }
$ok1 = $rep1['code']===200 && is_array($r1) && !empty($r1['ok']) && (int)$r1['added']===2 && !empty($r1['done']);

// 5) HMAC replay: re-send exact same signed request (same headers including X-Auth-Ts and X-Auth-Sign) => expect 409
$repReplay = http_req($BASE.$path2.'?debug=1', 'POST', $hdrs2, $body1);
out("report_results replay code={$repReplay['code']} body={$repReplay['body']}");
$replayOk = $repReplay['code']===409;

// 6) Idempotency: create a new job (Job B) and send same body twice with idempotency_key
// Enqueue Job B
$pdo->prepare("INSERT INTO internal_jobs(requested_by_user_id,role,agent_id,query,ll,radius_km,lang,region,status,priority,queued_at,created_at,updated_at)
  VALUES(?,?,?,?,?,?, 'ar','sa','queued', 999, datetime('now'), datetime('now'), datetime('now'))")
  ->execute([$adminId,'agent',$agentId,$q,$ll,$radius]);
$jobIdB = (int)$pdo->lastInsertId();
out("Enqueued job B id=$jobIdB for agent_id=$agentId");
// Pull Job B with fresh HMAC headers
$hdrsPullB = array_merge([
  'X-Worker-Id: '.$workerId,
], hmac_headers('GET', $path, ''));
$pullB = http_req($BASE.$path, 'GET', $hdrsPullB);
if($pullB['code']!==200){
  // brief retry
  sleep(1);
  $hdrsPullB = array_merge([
    'X-Worker-Id: '.$workerId,
  ], hmac_headers('GET', $path, ''));
  $pullB = http_req($BASE.$path, 'GET', $hdrsPullB);
  if($pullB['code']!==200){ out('FAIL pull_job B'); exit(1); }
}
$jB = json_decode($pullB['body'], true);
if(!$jB || empty($jB['job']['id'])){
  sleep(1);
  $hdrsPullB = array_merge([
    'X-Worker-Id: '.$workerId,
  ], hmac_headers('GET', $path, ''));
  $pullB = http_req($BASE.$path, 'GET', $hdrsPullB);
  $jB = json_decode($pullB['body'], true);
  if(!$jB || empty($jB['job']['id'])){ out('FAIL pull_job B parse'); exit(1); }
}
$jidB = (int)$jB['job']['id']; $attemptIdB = (string)($jB['job']['attempt_id'] ?? '');
// Prepare items for Job B
$pB = '05'.(string)random_int(500000000, 599999999);
$itemsB = [ [ 'name'=>'JobB One', 'city'=>'الرياض', 'country'=>'sa', 'phone'=>$pB, 'provider'=>'osm', 'lat'=>24.7136, 'lng'=>46.6753 ] ];
$bodyB = json_encode(['job_id'=>$jidB, 'attempt_id'=>$attemptIdB, 'items'=>$itemsB, 'cursor'=>1, 'done'=>false, 'idempotency_key'=>'ik-abc', 'extend_lease_sec'=>120], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$hdrsB = array_merge([
  'Content-Type: application/json',
  'X-Worker-Id: '.$workerId,
], hmac_headers('POST', $path2, $bodyB));
$repB1 = http_req($BASE.$path2.'?debug=1', 'POST', $hdrsB, $bodyB);
out("report_results B#1 code={$repB1['code']} body={$repB1['body']}");
$rB1 = null; if($repB1['code']===200){ $rB1 = json_decode($repB1['body'], true); }
$idemPrimOk = $repB1['code']===200 && is_array($rB1) && isset($rB1['ok']);
// Re-send same payload with fresh HMAC to trigger idempotency
sleep(1);
$hdrsB2 = array_merge([
  'Content-Type: application/json',
  'X-Worker-Id: '.$workerId,
], hmac_headers('POST', $path2, $bodyB));
$repB2 = http_req($BASE.$path2.'?debug=1', 'POST', $hdrsB2, $bodyB);
out("report_results B idempotent code={$repB2['code']} body={$repB2['body']}");
$rB2 = null; if($repB2['code']===200){ $rB2 = json_decode($repB2['body'], true); }
$idemOk = $repB2['code']===200 && is_array($rB2) && !empty($rB2['idempotent']);

// 7) Dedup: send a duplicate item only to Job B (without idempotency key) => expect duplicates>=1, added=0
$dupItem = [ 'name'=>'JobB One Duplicate', 'city'=>'الرياض', 'country'=>'sa', 'phone'=>$pB, 'provider'=>'osm', 'lat'=>24.7136, 'lng'=>46.6753 ];
$bodyDup = json_encode(['job_id'=>$jidB, 'attempt_id'=>$attemptIdB, 'items'=>[$dupItem], 'cursor'=>2, 'done'=>false, 'extend_lease_sec'=>120], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$hdrs3 = array_merge([
  'Content-Type: application/json',
  'X-Worker-Id: '.$workerId,
], hmac_headers('POST', $path2, $bodyDup));
$rep3 = http_req($BASE.$path2.'?debug=1', 'POST', $hdrs3, $bodyDup);
out("report_results duplicate code={$rep3['code']} body={$rep3['body']}");
$r3 = null; if($rep3['code']===200){ $r3 = json_decode($rep3['body'], true); }
$dupOk = $rep3['code']===200 && is_array($r3) && isset($r3['duplicates']) && (int)$r3['added']===0 && (int)$r3['duplicates']>=1;

hr();
// 8) DB proofs
$cntLeads = (int)$pdo->query("SELECT COUNT(*) FROM leads")->fetchColumn();
$cntAssign = (int)$pdo->query("SELECT COUNT(*) FROM assignments")->fetchColumn();
$rows = $pdo->query("SELECT id,name,phone,phone_norm,city,country,category_id,lat,lon FROM leads ORDER BY id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
$today = date('Y-m-d');
$usage = $pdo->prepare("SELECT kind,count FROM usage_counters WHERE day=? AND (kind='ingest_added' OR kind='ingest_duplicates')");
$usage->execute([$today]); $usageRows = $usage->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

out("DB leads count=$cntLeads assignments count=$cntAssign");
out("Recent leads snapshot:");
foreach($rows as $r){ out(' - id='.$r['id'].' phone='.$r['phone'].' norm='.$r['phone_norm'].' city='.$r['city'].' country='.$r['country']); }
$outAdded = $usageRows['ingest_added'] ?? 0; $outDup = $usageRows['ingest_duplicates'] ?? 0;
out("usage_counters today: ingest_added=$outAdded ingest_duplicates=$outDup");

// 9) UI check: login as admin and fetch Admin Leads page; follow session cookie; look for Job A numbers
$uiOk = false; $uiCode = 0;
try{
  // Step A: POST login to obtain remember/session cookie
  $ch = curl_init($BASE.'/auth/login.php');
  curl_setopt_array($ch,[
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_POST=>true,
    CURLOPT_POSTFIELDS=>http_build_query(['mobile'=>'500000001','password'=>'500000001','remember'=>'1']),
    CURLOPT_HEADER=>true,
  ]);
  $loginResp = curl_exec($ch);
  $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
  $loginHeaders = substr($loginResp, 0, $headerSize);
  curl_close($ch);
  // Extract any set-cookie header
  $cookies = [];
  foreach(explode("\r\n", trim($loginHeaders)) as $line){
    if(stripos($line, 'Set-Cookie:')===0){ $parts = explode(':', $line, 2); if(isset($parts[1])){ $cookies[] = trim($parts[1]); } }
  }
  $cookieHeader = '';
  if($cookies){
    $pairs = [];
    foreach($cookies as $c){ $kv = explode(';', $c, 2)[0]; $pairs[] = $kv; }
    $cookieHeader = 'Cookie: '.implode('; ', $pairs);
  }
  $ui = http_req($BASE.'/admin/leads.php', 'GET', $cookieHeader?[$cookieHeader]:[]);
  $uiCode = $ui['code'];
  $uiOk = ($uiCode===200) && (strpos($ui['body'], $norm1)!==false || strpos($ui['body'], $norm2)!==false);
}catch(Throwable $e){}

hr();
out('ACCEPTANCE RESULTS:');
out(' - Happy path ingest: '.($ok1?'PASS':'FAIL'));
out(' - HMAC replay blocked: '.($replayOk?'PASS':'FAIL'));
out(' - Idempotency key honored: '.($idemOk?'PASS':'FAIL'));
out(' - Duplicate detection: '.($dupOk?'PASS':'FAIL'));
out(' - Admin UI shows lead: '.($uiOk?'PASS (code='.$uiCode.')':'FAIL (code='.$uiCode.')'));

$allOk = $ok1 && $replayOk && $idemOk && $dupOk && $uiOk;
exit($allOk ? 0 : 2);
