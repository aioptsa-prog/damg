<?php
// Post Multi-Location Operational Check — Safe E2E with tiny test data and read-only schema checks
// Outputs a compact JSON lines report that can be used to author an executive Markdown.

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors','1');

require_once __DIR__ . '/../../bootstrap.php';
require_once __DIR__ . '/../../lib/security.php';

function out($label, $data){
  echo json_encode(['k'=>$label,'v'=>$data], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), "\n";
}

$BASE = 'http://127.0.0.1:8080';
$pdo = db();
$now = date('Y-m-d H:i:s');

// Ensure admin exists and get login cookie
function upsertUser($mobile,$name,$role){
  $pdo = db();
  $st=$pdo->prepare("SELECT id FROM users WHERE mobile=? LIMIT 1");
  $st->execute([$mobile]); $row=$st->fetch();
  if($row){ return (int)$row['id']; }
  $pdo->prepare("INSERT INTO users(mobile,name,role,password_hash,active,created_at) VALUES(?,?,?,?,1,datetime('now'))")
      ->execute([$mobile,$name,$role,password_hash($mobile,PASSWORD_BCRYPT)]);
  return (int)$pdo->lastInsertId();
}

$adminId = upsertUser('500000101','Ops Admin','admin');
$agentId = upsertUser('500000102','Ops Agent','agent');

// HTTP helpers
function http_req(string $url, string $method='GET', array $headers=[], ?string $body=null): array{
  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
  if($body!==null){ curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
  curl_setopt($ch, CURLOPT_HEADER, true);
  curl_setopt($ch, CURLOPT_TIMEOUT, 30);
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
  $resp = curl_exec($ch);
  if($resp===false){ $err=curl_error($ch); curl_close($ch); return ['code'=>0,'headers'=>[],'body'=>'','error'=>$err]; }
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $hsz = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
  $hraw = substr($resp, 0, $hsz);
  $bodyOut = substr($resp, $hsz);
  curl_close($ch);
  $headersOut=[]; foreach(explode("\r\n", trim($hraw)) as $line){ if(strpos($line, ':')!==false){ [$k,$v] = array_map('trim', explode(':', $line, 2)); $headersOut[strtolower($k)]=$v; } }
  return ['code'=>$code, 'headers'=>$headersOut, 'body'=>$bodyOut, 'hraw'=>$hraw];
}

// 1) Login to get cookies
$ch = curl_init($BASE.'/auth/login.php');
curl_setopt_array($ch,[
  CURLOPT_RETURNTRANSFER=>true,
  CURLOPT_POST=>true,
  CURLOPT_POSTFIELDS=>http_build_query(['mobile'=>'500000101','password'=>'500000101','remember'=>'1']),
  CURLOPT_HEADER=>true,
]);
$loginResp = curl_exec($ch);
$hsz = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$loginHdr = substr($loginResp,0,$hsz);
curl_close($ch);
$cookies=[]; foreach(explode("\r\n", trim($loginHdr)) as $line){ if(stripos($line,'Set-Cookie:')===0){ $parts = explode(':', $line, 2); if(isset($parts[1])) $cookies[] = trim($parts[1]); } }
$cookieHeader = '';
if($cookies){ $pairs=[]; foreach($cookies as $c){ $kv = explode(';', $c, 2)[0]; $pairs[] = $kv; } $cookieHeader = 'Cookie: '.implode('; ',$pairs); }
out('login', ['ok'=>($cookieHeader!==''), 'cookies_count'=>count($cookies)]);

// 2) CSRF token from admin/fetch.php
$page = http_req($BASE.'/admin/fetch.php', 'GET', $cookieHeader?[$cookieHeader]:[]);
preg_match('/name=\"csrf\"\s+value=\"([^\"]+)\"/u', $page['body'], $m);
$csrf = $m[1] ?? '';
out('csrf', ['present'=>($csrf!=='')]);

// 3) Schema checks (read-only)
$schema = [
  'tables'=>[], 'columns'=>[], 'indexes'=>[], 'settings'=>[]
];
// tables
$t = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
$schema['tables'] = $t;
// columns
function colExists($table,$col){ $pdo=db(); $cols=$pdo->query("PRAGMA table_info(".$table.")")->fetchAll(PDO::FETCH_ASSOC); foreach($cols as $c){ if(($c['name']??$c['Name']??'')===$col) return true; } return false; }
$schema['columns'] = [
  'job_groups.id'=>colExists('job_groups','id'),
  'job_groups.created_by_user_id'=>colExists('job_groups','created_by_user_id'),
  'internal_jobs.job_group_id'=>colExists('internal_jobs','job_group_id'),
  'internal_jobs.city_hint'=>colExists('internal_jobs','city_hint'),
  'leads.job_group_id'=>colExists('leads','job_group_id'),
];
// indexes
$ix = $pdo->query("SELECT name, tbl_name, sql FROM sqlite_master WHERE type='index'")->fetchAll(PDO::FETCH_ASSOC);
$schema['indexes'] = [
  'idx_job_groups_created'=> (bool)array_filter($ix, fn($r)=>($r['name']??'')==='idx_job_groups_created'),
  'idx_internal_jobs_group'=> (bool)array_filter($ix, fn($r)=>($r['name']??'')==='idx_internal_jobs_group' || ($r['name']??'')==='idx_internal_jobs_group'),
  'idx_leads_job_group'=> (bool)array_filter($ix, fn($r)=>($r['name']??'')==='idx_leads_job_group'),
];
// settings
function get_set($k,$def=''){ $st=db()->prepare('SELECT value FROM settings WHERE key=?'); $st->execute([$k]); return (string)($st->fetchColumn() ?: $def); }
$schema['settings'] = [
  'MAX_MULTI_LOCATIONS'=>get_set('MAX_MULTI_LOCATIONS',''),
  'MAX_EXPANDED_TASKS'=>get_set('MAX_EXPANDED_TASKS',''),
  'rate_limit_category_search_per_min'=>get_set('rate_limit_category_search_per_min',''),
  'rate_limit_admin_multiplier'=>get_set('rate_limit_admin_multiplier',''),
];
out('schema', $schema);

// 4) Security headers quick probe over HTTP (HSTS should be absent) and CSP present
$hp = http_req($BASE.'/', 'GET', $cookieHeader?[$cookieHeader]:[]);
$hasHsts = false; foreach($hp['headers'] as $k=>$v){ if(strtolower($k)==='strict-transport-security') $hasHsts=true; }
$hasCsp = false; foreach($hp['headers'] as $k=>$v){ if(strtolower($k)==='content-security-policy') $hasCsp=true; }
out('security_headers_http', ['hsts'=>$hasHsts,'csp'=>$hasCsp,'code'=>$hp['code']]);

// 5) Typeahead Arabic/English
$taAr = http_req($BASE.'/api/category_search.php?q='.rawurlencode('عيادات')."&limit=5&active_only=1&csrf=".rawurlencode($csrf), 'GET', [$cookieHeader]);
$taEn = http_req($BASE.'/api/category_search.php?q='.rawurlencode('dental')."&limit=5&active_only=1&csrf=".rawurlencode($csrf), 'GET', [$cookieHeader]);
$ja = json_decode($taAr['body'], true); $je = json_decode($taEn['body'], true);
out('typeahead', [
  'ar_ok'=>is_array($ja) && count($ja)>0 && isset($ja[0]['path']) && isset($ja[0]['icon']),
  'en_ok'=>is_array($je) && count($je)>0 && isset($je[0]['path']) && isset($je[0]['icon']),
  'ar_sample'=>is_array($ja)&&$ja? $ja[0]: null,
  'en_sample'=>is_array($je)&&$je? $je[0]: null,
]);

// 6) Multi-Location group create (2 locations)
// Find a clear category: try slug 'dental-clinics', else first active
$cid = null; $nm = null;
try{ $st=$pdo->query("SELECT id,name FROM categories WHERE slug='dental-clinics' LIMIT 1"); $row=$st->fetch(); if($row){ $cid=(int)$row['id']; $nm=$row['name']; } }catch(Throwable $e){}
if(!$cid){ $st=$pdo->query("SELECT id,name FROM categories WHERE COALESCE(is_active,1)=1 ORDER BY depth ASC, id ASC LIMIT 1"); $row=$st->fetch(); $cid=(int)($row['id']??1); $nm=$row['name']??'cat'; }
$mlBody = json_encode([
  'csrf'=>$csrf,
  'category_id'=>$cid,
  'base_query'=>'عيادة أسنان',
  'multi_search'=>true,
  'note'=>'E2E-ML-Run',
  'locations'=>[
    ['city'=>'الرياض', 'll'=>'24.7136,46.6753', 'radius_km'=>3],
    ['city'=>'جدة',   'll'=>'21.4858,39.1925', 'radius_km'=>3],
  ],
  'target_count'=>50,
], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$ml = http_req($BASE.'/api/jobs_multi_create.php', 'POST', [$cookieHeader,'Content-Type: application/json'], $mlBody);
$mlJ = json_decode($ml['body'], true);
out('ml_create', ['code'=>$ml['code'], 'ok'=>$mlJ['ok']??false, 'resp'=>$mlJ]);
$gid = (int)($mlJ['job_group_id'] ?? 0);

// Verify internal_jobs populated
$jobs = [];
if($gid){
  $st=$pdo->prepare("SELECT id,query,ll,radius_km,city_hint,category_id,job_group_id FROM internal_jobs WHERE job_group_id=? ORDER BY id ASC LIMIT 10");
  $st->execute([$gid]); $jobs=$st->fetchAll(PDO::FETCH_ASSOC);
}
out('ml_jobs_snapshot', ['count'=>count($jobs), 'sample'=>$jobs]);

// 7) Ingest sample results to one job under the group
// Prepare internal server & worker
$pdo->prepare("INSERT INTO settings(key,value) VALUES('internal_server_enabled','1') ON CONFLICT(key) DO UPDATE SET value='1'")->execute();
$secret = get_setting('internal_secret',''); if($secret===''){ $secret='ops-secret-'.bin2hex(random_bytes(4)); $pdo->prepare("INSERT INTO settings(key,value) VALUES('internal_secret',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value")->execute([$secret]); }
$workerId = 'ops-worker-ml-1';
try{ $pdo->prepare("INSERT INTO internal_workers(worker_id,last_seen,status,host,version) VALUES(?,?,?,?,?) ON CONFLICT(worker_id) DO UPDATE SET last_seen=excluded.last_seen")
        ->execute([$workerId,$now,'idle',php_uname('n'),'ops']); }catch(Throwable $e){}

function hmac_headers_local(string $method, string $path, string $body=''): array{
  $ts=(string)time(); $sha=hmac_body_sha256($body); $sig=hmac_sign($method,$path,$sha,$ts);
  return ['X-Auth-Ts: '.$ts,'X-Auth-Sign: '.$sig];
}

$jobIdUse = isset($jobs[0]['id']) ? (int)$jobs[0]['id'] : 0;
// Craft 3 items
$p1 = '05'.(string)random_int(500000000, 599999999);
$p2 = '+9665'.(string)random_int(500000000, 599999999);
$p3 = '9665'.(string)random_int(500000000, 599999999);
$items = [
  ['name'=>'Clinic 1','city'=>'الرياض','country'=>'sa','phone'=>$p1,'provider'=>'osm','lat'=>24.7136,'lng'=>46.6753],
  ['name'=>'Clinic 2','city'=>'Riyadh','country'=>'sa','phone'=>$p2,'provider'=>'osm','lat'=>24.7137,'lng'=>46.6754],
  ['name'=>'Clinic 3','city'=>'جدة','country'=>'sa','phone'=>$p3,'provider'=>'osm','lat'=>21.4860,'lng'=>39.1927],
];
$attemptId = 'ml-attempt-'.bin2hex(random_bytes(3));
$bodyRep = json_encode(['job_id'=>$jobIdUse,'attempt_id'=>$attemptId,'items'=>$items,'cursor'=>3,'done'=>false,'extend_lease_sec'=>120], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$hdrs = array_merge(['Content-Type: application/json','X-Worker-Id: '.$workerId], hmac_headers_local('POST','/api/report_results.php',$bodyRep));
$rep = http_req($BASE.'/api/report_results.php?debug=1','POST',$hdrs,$bodyRep);
$rj = $rep['code']===200? json_decode($rep['body'], true): null;
// Idempotency
$bodyRep2 = json_encode(['job_id'=>$jobIdUse,'attempt_id'=>$attemptId,'items'=>$items,'cursor'=>3,'done'=>false,'idempotency_key'=>'ik-ops','extend_lease_sec'=>120], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$hdrs2 = array_merge(['Content-Type: application/json','X-Worker-Id: '.$workerId], hmac_headers_local('POST','/api/report_results.php',$bodyRep2));
$rep2 = http_req($BASE.'/api/report_results.php?debug=1','POST',$hdrs2,$bodyRep2);
$rj2 = $rep2['code']===200? json_decode($rep2['body'], true): null;
// Duplicate one item
$dupItem = [$items[0]];
$bodyRep3 = json_encode(['job_id'=>$jobIdUse,'attempt_id'=>$attemptId,'items'=>$dupItem,'cursor'=>4,'done'=>false,'extend_lease_sec'=>120], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$hdrs3 = array_merge(['Content-Type: application/json','X-Worker-Id: '.$workerId], hmac_headers_local('POST','/api/report_results.php',$bodyRep3));
$rep3 = http_req($BASE.'/api/report_results.php?debug=1','POST',$hdrs3,$bodyRep3);
$rj3 = $rep3['code']===200? json_decode($rep3['body'], true): null;
// Counters today
$day = date('Y-m-d');
$uc = $pdo->prepare("SELECT kind,count FROM usage_counters WHERE day=? AND kind IN ('ingest_added','ingest_duplicates')");
$uc->execute([$day]); $ucK = $uc->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
out('ingest', [
  'job_id_used'=>$jobIdUse,
  'report_1'=>$rj,
  'report_2_idempotent'=>$rj2,
  'report_3_dup'=>$rj3,
  'counters_today'=>$ucK,
]);

// 8) Vault filter and export columns
$vault = http_req($BASE.'/admin/leads.php?job_group_id='.$gid, 'GET', [$cookieHeader]);
$csv = http_req($BASE.'/api/export_leads.php?'.http_build_query(['job_group_id'=>$gid,'csrf'=>$csrf]), 'GET', [$cookieHeader]);
$csvHead = '';
if($csv['code']===200){ $lines = explode("\n", $csv['body']); foreach($lines as $L){ if(strpos($L,'sep=,')===false && trim($L)!==''){ $csvHead = trim($L); break; } } }
out('vault_export', [
  'vault_code'=>$vault['code'],
  'csv_code'=>$csv['code'],
  'csv_header'=>$csvHead,
  'csv_has_job_group'=> (strpos($csvHead, 'job_group_id')!==false),
  'csv_has_category_cols'=> (strpos($csvHead, 'category_name')!==false && strpos($csvHead, 'category_slug')!==false && strpos($csvHead, 'category_path')!==false),
]);

// 9) 429 test: temporarily set admin multiplier to 1 to see 429 after base 30/min
$prevMult = get_set('rate_limit_admin_multiplier','2');
try{ $pdo->prepare("INSERT INTO settings(key,value) VALUES('rate_limit_admin_multiplier','1') ON CONFLICT(key) DO UPDATE SET value='1'")->execute(); }catch(Throwable $e){}
// Burst 35
$count200=0; $count429=0; $sample429='';
for($i=0;$i<35;$i++){
  $res = http_req($BASE.'/api/category_search.php?q='.rawurlencode('عيادة').'&limit=1&active_only=1&csrf='.rawurlencode($csrf), 'GET', [$cookieHeader]);
  if($res['code']===429){ $count429++; if($sample429===''){ $sample429 = $res['body']; } }
  elseif($res['code']>=200 && $res['code']<300){ $count200++; }
  usleep(50000); // 50ms spacing
}
// Restore multiplier
try{ $pdo->prepare("INSERT INTO settings(key,value) VALUES('rate_limit_admin_multiplier',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value")->execute([$prevMult]); }catch(Throwable $e){}
out('rl_429', ['count_2xx'=>$count200,'count_429'=>$count429,'sample'=>$sample429]);

// 10) HTTP security header probe already done above
// Done
?>
