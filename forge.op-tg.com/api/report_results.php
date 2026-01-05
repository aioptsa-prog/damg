<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/system.php';
require_once __DIR__ . '/../lib/classify.php';
require_once __DIR__ . '/../lib/geo.php';
require_once __DIR__ . '/../lib/security.php';
header('Content-Type: application/json; charset=utf-8');

try{
  $enabled = get_setting('internal_server_enabled','0')==='1';
  if(!$enabled){ http_response_code(403); echo json_encode(['error'=>'internal_server_disabled']); exit; }
  // Auth: HMAC or legacy secret or per-worker secret
  $method = $_SERVER['REQUEST_METHOD'] ?? 'POST';
  $path = parse_url($_SERVER['REQUEST_URI'] ?? '/api/report_results.php', PHP_URL_PATH) ?: '/api/report_results.php';
  // Capture raw body early to avoid php://input double-read during HMAC verification
  try{ $GLOBALS['__RAW_BODY__'] = (string)file_get_contents('php://input'); }catch(Throwable $e){ $GLOBALS['__RAW_BODY__'] = ''; }
  $workerIdHdr = (function(){ $k='HTTP_X_WORKER_ID'; return $_SERVER[$k]??''; })();
  if (!verify_worker_auth($workerIdHdr, $method, $path)) { http_response_code(401); echo json_encode(['error'=>'unauthorized']); exit; }
// Replay guard (reject duplicate signed requests)
try{
  $rawPre = isset($GLOBALS['__RAW_BODY__']) ? (string)$GLOBALS['__RAW_BODY__'] : file_get_contents('php://input');
  $tsPre  = (int)($_SERVER['HTTP_X_AUTH_TS'] ?? 0);
  $shaPre = hmac_body_sha256($rawPre);
  if(!hmac_replay_check_ok((string)$workerIdHdr, $method, $path, $tsPre, $shaPre)){
    http_response_code(409);
    echo json_encode(['error'=>'replay_detected']);
    exit;
  }
  // Reuse captured raw body
  $GLOBALS['__REPORT_RESULTS_RAW__'] = $rawPre;
}catch(Throwable $e){}
// Stop/pause guard
if(system_is_globally_stopped() || system_is_in_pause_window()){
  http_response_code(503);
  echo json_encode(['error'=>'system_stopped']);
  exit;
}

$raw = isset($GLOBALS['__REPORT_RESULTS_RAW__']) ? (string)$GLOBALS['__REPORT_RESULTS_RAW__'] : (isset($GLOBALS['__RAW_BODY__']) ? (string)$GLOBALS['__RAW_BODY__'] : file_get_contents('php://input'));
$data = json_decode($raw,true) ?: [];
$job_id = (int)($data['job_id'] ?? 0);
$items = is_array($data['items'] ?? null) ? $data['items'] : [];
$cursor = (int)($data['cursor'] ?? 0);
$done = !empty($data['done']);
$attemptId = isset($data['attempt_id']) ? (string)$data['attempt_id'] : null;
$idempotencyKey = isset($data['idempotency_key']) ? substr((string)$data['idempotency_key'],0,100) : null;
$extend = max(60, (int)($data['extend_lease_sec'] ?? 120));
$error = isset($data['error']) ? trim((string)$data['error']) : '';
$log_excerpt = isset($data['log']) ? (string)$data['log'] : null;
$workerId = (function(){ $k='HTTP_X_WORKER_ID'; return $_SERVER[$k]??''; })();
// Presence refresh: mark worker as active while reporting
try{ if($workerId){ workers_upsert_seen($workerId, ['status'=>'reporting']); } }catch(Throwable $e){}
if(!$job_id){ http_response_code(400); echo json_encode(['error'=>'missing_job_id']); exit; }

$pdo = db();
$pdo->beginTransaction();
$job = $pdo->prepare("SELECT * FROM internal_jobs WHERE id=?");
$job->execute([$job_id]);
$job=$job->fetch();
if(!$job){ $pdo->rollBack(); http_response_code(404); echo json_encode(['error'=>'job_not_found']); exit; }
// If job already finished, accept idempotently but don't mutate
if($job['status']==='done' || $job['status']==='failed'){
  $pdo->commit();
  echo json_encode(['ok'=>true,'added'=>0,'lease_expires_at'=>$job['lease_expires_at'],'done'=>true]);
  exit;
}
// Early idempotency guard: if idempotency_key is provided and already recorded, treat as idempotent
if($idempotencyKey){
  try{
    $stIk = $pdo->prepare("INSERT OR IGNORE INTO idempotency_keys(job_id, ikey, created_at) VALUES(?,?,datetime('now'))");
    $stIk->execute([$job_id, $idempotencyKey]);
    // Detect whether insert happened (SQLite-specific)
    $ch = (int)$pdo->query("SELECT changes() AS c")->fetchColumn();
    if($ch===0){
      // Key existed -> idempotent accept, minimally extend lease
      $leaseUntilFast = date('Y-m-d H:i:s', time()+max(30,$extend));
      $pdo->prepare("UPDATE internal_jobs SET lease_expires_at=:lease, updated_at=:now WHERE id=:id")
          ->execute([':lease'=>$leaseUntilFast, ':now'=>date('Y-m-d H:i:s'), ':id'=>$job_id]);
      $pdo->commit();
      echo json_encode(['ok'=>true,'added'=>0,'lease_expires_at'=>$leaseUntilFast,'done'=>false,'idempotent'=>true]);
      exit;
    }
  }catch(Throwable $e){}
}
// Guard: ensure lease not expired while reporting (best-effort; still accept but suggest extend)
$now=date('Y-m-d H:i:s');
if($job['lease_expires_at'] && $job['lease_expires_at'] < $now){
  // Soft-fail: extend minimally to avoid race, but mark last_error
  $pdo->prepare("UPDATE internal_jobs SET last_error=COALESCE(last_error,'lease_expired_report'), updated_at=:now WHERE id=:id")->execute([':now'=>$now, ':id'=>$job_id]);
}

$role=$job['role']; $agent_id=$job['agent_id'];
// Derive effective category for this ingestion batch
$catIdEffective = null;
try{
  $catFromReq = isset($data['category_id']) ? (int)$data['category_id'] : 0;
  $catFromJob = isset($job['category_id']) ? (int)$job['category_id'] : 0;
  $catIdEffective = $catFromReq ?: ($catFromJob ?: null);
}catch(Throwable $e){ $catIdEffective = null; }
$added=0; $dups=0; $now=date('Y-m-d H:i:s');
$insLead = $pdo->prepare("INSERT OR IGNORE INTO leads(phone,phone_norm,name,city,country,created_at,source,created_by_user_id) VALUES(?,?,?, ?,?, datetime('now'), 'internal', ?)");
// Detect optional job_group_id column on leads once
$hasLeadCol = function($name) use ($pdo){
  try{ $cols = $pdo->query("PRAGMA table_info(leads)")->fetchAll(PDO::FETCH_ASSOC); foreach($cols as $c){ if(($c['name']??$c['Name']??'')===$name) return true; } }catch(Throwable $e){}
  return false;
};
$leadsHasGroup = $hasLeadCol('job_group_id');
// Capture group id from job if available
$jobGroupId = null; try{ if(isset($job['job_group_id']) && $job['job_group_id']){ $jobGroupId = (int)$job['job_group_id']; } }catch(Throwable $e){}
$insAssign = $pdo->prepare("INSERT OR IGNORE INTO assignments(lead_id,agent_id,status,assigned_at) VALUES(?,?,'new',datetime('now'))");

foreach($items as $it){
  // Extract core fields early (country used by phone normalization logic)
  $name=trim((string)($it['name'] ?? ''));
  $city=trim((string)($it['city'] ?? ''));
  $country=trim((string)($it['country'] ?? ''));
  $rawPhone = (string)($it['phone'] ?? '');
  $digits = preg_replace('/\D+/','', $rawPhone);
  // Normalize to E.164-like: if starts with 0 and likely SA, convert to +966 without plus for storage harmony
  if($digits !== '' && $digits[0] === '0'){ $digits = ltrim($digits, '0'); }
  // If 9 or 10 digits and country missing/SA, consider Saudi prefix
  if((strlen($digits)===9 || strlen($digits)===10) && (mb_strtolower($country)==='sa' || $country==='')){ if(strpos($digits, '966')!==0){ $digits = '966'.$digits; } }
  $phone = $digits; // keep original phone field as normalized digits only
  $phone_norm = $digits;
  if($phone==='') continue;
  $rating = isset($it['rating']) ? (float)$it['rating'] : null;
  $website = trim((string)($it['website'] ?? '')) ?: null;
  $email = trim((string)($it['email'] ?? '')) ?: null;
  $types = is_array($it['types'] ?? null) ? implode(',', $it['types']) : (trim((string)($it['gmap_types'] ?? '')) ?: null);
  $srcUrl = trim((string)($it['source_url'] ?? '')) ?: null;
  $social = null; if(!empty($it['social']) && is_array($it['social'])){ $social = json_encode($it['social'], JSON_UNESCAPED_UNICODE); }
  $lat = isset($it['lat']) ? (float)$it['lat'] : null;
  $lon = isset($it['lng']) ? (float)$it['lng'] : (isset($it['lon']) ? (float)$it['lon'] : null);
  // Soft fingerprint (provider optional): day bucket + city/rounded point + phone_norm
  $provider = isset($it['provider']) ? (string)$it['provider'] : 'unknown';
  $day = gmdate('Y-m-d');
  $latr = $lat!==null ? round($lat, 3) : null; $lonr = $lon!==null ? round($lon, 3) : null;
  $fpSrc = implode('|', [strtolower($phone_norm), strtolower($provider), strtolower($city), (string)$latr, (string)$lonr, $day]);
  $fingerprint = sha1($fpSrc);
  // Category classification (business type)
  $cls = classify_lead([
    'name'=>$name,
    'gmap_types'=>$types,
    'website'=>$website,
    'email'=>$email,
    'source_url'=>$srcUrl,
    'city'=>$city,
    'country'=>$country,
    'phone'=>$phone,
  ]);
  $catId = $catIdEffective ?: ($cls['category_id'] ?? null);
  // Geo classification (SA-first). Prefer point if coords exist, else fall back to text.
  $geo = null; $geoReason = null;
  if(mb_strtolower($country)==='sa' || $country===''){
    if($lat!==null && $lon!==null){
      $geo = geo_classify_point($lat, $lon, 'SA');
      if(($geo['confidence'] ?? 0) < 0.5){ $geoReason = 'low_conf_point'; }
    }
    if(!$geo){
      if($city!==''){
        $geo = geo_classify_text($city, null, 'SA');
        if(($geo['confidence'] ?? 0) < 0.5){ $geoReason = 'low_conf_text_city'; }
      } else {
        $geoReason = 'no_city_text';
      }
    }
  }
  try{
    $insLead->execute([
        $phone,$phone_norm,$name,$city,$country,$job['requested_by_user_id']
    ]);
    if($insLead->rowCount()>0){
      $added++;
      $leadId = (int)$pdo->lastInsertId();
      // Link to group when present (best-effort)
      if($leadsHasGroup && $jobGroupId){
        try{ $pdo->prepare("UPDATE leads SET job_group_id=COALESCE(job_group_id, :gid) WHERE id=:id")->execute([':gid'=>$jobGroupId, ':id'=>$leadId]); }catch(Throwable $e){}
      }
      // Fill optional fields for freshly inserted lead
      try{
        $updNew = $pdo->prepare("UPDATE leads SET rating=:rating, website=:website, email=:email, gmap_types=:types, source_url=:src, social=:social, category_id=:cid, lat=:lat, lon=:lon, geo_country=:gc, geo_region_code=:gr, geo_city_id=:gci, geo_district_id=:gdi, geo_confidence=:gconf WHERE id=:id");
        $updNew->execute([
          ':rating'=>$rating, ':website'=>$website, ':email'=>$email, ':types'=>$types, ':src'=>$srcUrl, ':social'=>$social, ':cid'=>$catId,
          ':lat'=>$lat, ':lon'=>$lon,
          ':gc'=>$geo['country']??null, ':gr'=>$geo['region_code']??null, ':gci'=>isset($geo['city_id'])?(int)$geo['city_id']:null, ':gdi'=>isset($geo['district_id'])?(int)$geo['district_id']:null, ':gconf'=>isset($geo['confidence'])?(float)$geo['confidence']:null,
          ':id'=>$leadId
        ]);
      }catch(Throwable $e){}
      // Try record fingerprint; ignore on conflict
      try{ $pdo->prepare("INSERT OR IGNORE INTO leads_fingerprints(lead_id,fingerprint,created_at) VALUES(?,?,datetime('now'))")->execute([$leadId,$fingerprint]); }catch(Throwable $e){}
      if($role==='agent' && $agent_id){ $insAssign->execute([$leadId,$agent_id]); }
    } else {
      // If fingerprint already exists globally, treat as duplicate silently (no added++)
      try{ $exists = $pdo->prepare("SELECT 1 FROM leads_fingerprints WHERE fingerprint=? LIMIT 1"); $exists->execute([$fingerprint]); $dup = (bool)$exists->fetch(); }catch(Throwable $e){ $dup=false; }
      if($dup){ $dups++; }
      // If lead exists and has no category, update it
      if($catId){
        $st = $pdo->prepare("UPDATE leads SET category_id=COALESCE(category_id, :cid) WHERE phone_norm=:phn OR phone=:ph");
        $st->execute([':cid'=>$catId, ':phn'=>$phone_norm, ':ph'=>$phone]);
      }
      // If lead exists and not linked to a group yet, link it
      if($leadsHasGroup && $jobGroupId){
        try{
          $st = $pdo->prepare("UPDATE leads SET job_group_id=COALESCE(job_group_id, :gid) WHERE phone_norm=:phn OR phone=:ph");
          $st->execute([':gid'=>$jobGroupId, ':phn'=>$phone_norm, ':ph'=>$phone]);
        }catch(Throwable $e){}
      }
      // Update geo fields on existing lead if we got a stronger classification
      if($geo && ($geo['confidence'] ?? 0) > 0){
        $st = $pdo->prepare("UPDATE leads SET lat=COALESCE(lat,:lat), lon=COALESCE(lon,:lon), geo_country=COALESCE(geo_country,:gc), geo_region_code=COALESCE(geo_region_code,:gr), geo_city_id=COALESCE(geo_city_id,:gci), geo_district_id=COALESCE(geo_district_id,:gdi), geo_confidence=MAX(geo_confidence, :gconf) WHERE phone_norm=:phn OR phone=:ph");
        $st->execute([':lat'=>$lat, ':lon'=>$lon, ':gc'=>$geo['country']??null, ':gr'=>$geo['region_code']??null, ':gci'=>isset($geo['city_id'])?(int)$geo['city_id']:null, ':gdi'=>isset($geo['district_id'])?(int)$geo['district_id']:null, ':gconf'=>isset($geo['confidence'])?(float)$geo['confidence']:null, ':phn'=>$phone_norm, ':ph'=>$phone]);
      }
    }
  }catch(Throwable $e){}
  if(!$geo || ($geo['confidence'] ?? 0) < 0.5){ geo_log_unknown(['phone'=>$phone,'name'=>$name,'city'=>$city,'country'=>$country,'reason'=>$geoReason?:'low_conf','ts'=>gmdate('c')]); }
}

// Idempotency: if idempotency_key provided and already recorded for this job, accept as idempotent success without re-inserting
if($idempotencyKey){
  try{
    $stmt = $pdo->prepare("SELECT 1 FROM idempotency_keys WHERE job_id=? AND ikey=? LIMIT 1");
    $stmt->execute([$job_id, $idempotencyKey]);
    if($stmt->fetch()){
      // Extend lease minimally and return ok without double counting
      $leaseUntilFast = date('Y-m-d H:i:s', time()+max(30,$extend));
      $pdo->prepare("UPDATE internal_jobs SET lease_expires_at=:lease, updated_at=:now WHERE id=:id")
          ->execute([':lease'=>$leaseUntilFast, ':now'=>date('Y-m-d H:i:s'), ':id'=>$job_id]);
      $pdo->commit();
      echo json_encode(['ok'=>true,'added'=>0,'lease_expires_at'=>$leaseUntilFast,'done'=>false,'idempotent'=>true]);
      exit;
    }
  }catch(Throwable $e){}
}

// Update progress and extend lease
$leaseUntil = date('Y-m-d H:i:s', time()+$extend);
// Update progress and extend lease (only if attempt matches when provided)
$sqlUpd = "UPDATE internal_jobs
   SET progress_count=COALESCE(progress_count,0)+:pc,
       result_count=COALESCE(result_count,0)+:added,
       last_cursor=:cursor,
       last_progress_at=:now,
       updated_at=:now,
       lease_expires_at=:lease
 WHERE id=:id" . ($attemptId? " AND attempt_id=:aid" : "");
$upd = $pdo->prepare($sqlUpd);
$paramsUpd = [
  ':pc'=>count($items),
  ':added'=>$added,
  ':cursor'=>$cursor,
  ':now'=>$now,
  ':lease'=>$leaseUntil,
  ':id'=>$job_id
];
if($attemptId){ $paramsUpd[':aid'] = $attemptId; }
$upd->execute($paramsUpd);

// Determine if target reached (server-side safety)
$target = isset($job['target_count']) && $job['target_count']!=='' ? (int)$job['target_count'] : null;
$reached = $target ? (((int)$job['result_count']) + $added) >= $target : false;
$isDone = false;

// Handle success/failure and backoff
if($done || $reached){
  $reason = $done ? 'worker_done' : 'target_reached';
  if($done && $target && !$reached){ $reason = 'no_more_results'; }
  $upd2 = $pdo->prepare("UPDATE internal_jobs SET status='done', finished_at=:now, updated_at=:now, lease_expires_at=NULL, last_error=NULL, done_reason=COALESCE(done_reason, :reason) WHERE id=:id");
  $upd2->execute([':now'=>$now, ':id'=>$job_id, ':reason'=>$reason]);
  $isDone = true;
  // Log attempt success
  try{ $pdo->prepare("INSERT INTO job_attempts(job_id,worker_id,started_at,finished_at,success,log_excerpt,attempt_id) VALUES(?,?,?,?,1,?,?)")
    ->execute([$job_id,$workerId,$job['claimed_at']?:$now,$now, $log_excerpt, $attemptId]); }catch(Throwable $e){}
  // Presence: finished job
  try{ if($workerId){ workers_upsert_seen($workerId, ['status'=>'idle', 'active_job_id'=>null]); } }catch(Throwable $e){}
  // Record idempotency key if provided
  if($idempotencyKey){ try{ $pdo->prepare("INSERT OR IGNORE INTO idempotency_keys(job_id, ikey, created_at) VALUES(?,?,datetime('now'))")->execute([$job_id, $idempotencyKey]); }catch(Throwable $e){} }
} elseif($error !== ''){
  // Failure path: compute backoff with jitter and reschedule or mark failed if max attempts reached
  $base = (int)settings_get('BACKOFF_BASE_SEC','30');
  $cap  = (int)settings_get('BACKOFF_MAX_SEC','3600');
  $attempts = (int)($job['attempts'] ?? 0);
  $maxA = (int)($job['max_attempts'] ?? (int)settings_get('MAX_ATTEMPTS_DEFAULT','5'));
  $exp = min($cap, $base * (1 << max(0, $attempts)));
  $jitter = random_int(0, (int)floor($exp * 0.2));
  $delay = $exp + $jitter;
  $retryAt = date('Y-m-d H:i:s', time() + $delay);
  $final = ($attempts+1) >= $maxA;
  $st = $pdo->prepare("UPDATE internal_jobs SET status=:st, next_retry_at=:nr, last_error=:err, updated_at=:now, lease_expires_at=NULL WHERE id=:id");
  $st->execute([':st'=> $final? 'failed':'queued', ':nr'=> $final? null : $retryAt, ':err'=>$error, ':now'=>$now, ':id'=>$job_id]);
  if($final){
    try{
      $pdo->prepare("INSERT INTO dead_letter_jobs(job_id,worker_id,reason,payload,created_at) VALUES(?,?,?,?,datetime('now'))")
          ->execute([$job_id, $workerId ?: null, substr($error,0,200), $log_excerpt ? substr($log_excerpt,0,2000) : null]);
    }catch(Throwable $e){}
  }
  try{ $pdo->prepare("INSERT INTO job_attempts(job_id,worker_id,started_at,finished_at,success,log_excerpt,attempt_id) VALUES(?,?,?,?,0,?,?)")
    ->execute([$job_id,$workerId,$job['claimed_at']?:$now,$now, $log_excerpt, $attemptId]); }catch(Throwable $e){}
}
$pdo->commit();

// Update usage_counters for simple ops telemetry (best-effort, outside transaction acceptable)
try{
  $day = date('Y-m-d');
  $inc = function($kind,$cnt) use ($pdo,$day){ if($cnt<=0) return; $pdo->prepare("INSERT INTO usage_counters(day,kind,count) VALUES(?,?,?) ON CONFLICT(day,kind) DO UPDATE SET count=count+excluded.count")->execute([$day,$kind,$cnt]); };
  $inc('ingest_added', $added);
  $inc('ingest_duplicates', $dups);
}catch(Throwable $e){}

echo json_encode(['ok'=>true,'added'=>$added,'duplicates'=>$dups,'lease_expires_at'=>$leaseUntil,'done'=>$isDone]);
}catch(Throwable $e){
  http_response_code(500);
  $debug = isset($_GET['debug']) && $_GET['debug']==='1';
  echo json_encode($debug ? ['error'=>'server_error','detail'=>$e->getMessage()] : ['error'=>'server_error']);
}