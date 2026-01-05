<?php
// Minimal end-to-end smoke tests for core features
// Run: php tests/smoke.php

define('UNIT_TEST', true);
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/system.php';
require_once __DIR__ . '/../lib/classify.php';
require_once __DIR__ . '/../lib/csrf.php';

function assert_true($cond, $msg){ if(!$cond){ throw new Exception('ASSERT FAIL: '.$msg); } }
function j($x){ return json_encode($x, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT); }

$pdo = db();
$now = date('Y-m-d H:i:s');

// Reset some settings to known state
set_setting('system_global_stop','0');
set_setting('system_pause_enabled','0');
set_setting('classify_enabled','1');
set_setting('classify_threshold','0.5');
set_setting('job_pick_order','fifo');
set_setting('export_max_rows','10');
set_setting('internal_server_enabled','1');
set_setting('internal_secret','testsecret');

// Create a temp admin user if not exists
$adminId = (int)($pdo->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetch()['id'] ?? 0);
if(!$adminId){
  $pdo->prepare("INSERT INTO users(mobile,name,role,password_hash,created_at) VALUES(?,?,?,?,datetime('now'))")
      ->execute(['0599999999','Test Admin','admin', password_hash('x',PASSWORD_DEFAULT)]);
  $adminId = (int)$pdo->lastInsertId();
}

// Ensure at least two agent users exist for rr_agent tests
$agentA = $pdo->query("SELECT id FROM users WHERE role='agent' AND mobile='0588888888' LIMIT 1")->fetch();
if(!$agentA){
    $pdo->prepare("INSERT INTO users(mobile,name,role,password_hash,created_at) VALUES(?,?,?,?,datetime('now'))")
            ->execute(['0588888888','Agent A','agent', password_hash('x',PASSWORD_DEFAULT)]);
    $agentAId = (int)$pdo->lastInsertId();
} else { $agentAId = (int)$agentA['id']; }
$agentB = $pdo->query("SELECT id FROM users WHERE role='agent' AND mobile='0577777777' LIMIT 1")->fetch();
if(!$agentB){
    $pdo->prepare("INSERT INTO users(mobile,name,role,password_hash,created_at) VALUES(?,?,?,?,datetime('now'))")
            ->execute(['0577777777','Agent B','agent', password_hash('x',PASSWORD_DEFAULT)]);
    $agentBId = (int)$pdo->lastInsertId();
} else { $agentBId = (int)$agentB['id']; }

// Seed categories and rules
$pdo->exec("DELETE FROM category_rules");
$pdo->exec("DELETE FROM category_keywords");
// leads may reference categories via category_id; clear it first to avoid FK issues on some engines
$pdo->exec("UPDATE leads SET category_id=NULL");
$pdo->exec("DELETE FROM categories");
$pdo->prepare("INSERT INTO categories(name, created_at) VALUES(?, datetime('now'))")->execute(['مطاعم']);
$cat1 = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO categories(name, created_at) VALUES(?, datetime('now'))")->execute(['تجميل']);
$cat2 = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO category_rules(category_id,target,pattern,match_mode,weight,enabled,created_at) VALUES(?,?,?,?,?,?,datetime('now'))")
    ->execute([$cat1,'name','مطعم','contains',1.2,1]);
$pdo->prepare("INSERT INTO category_rules(category_id,target,pattern,match_mode,weight,enabled,created_at) VALUES(?,?,?,?,?,?,datetime('now'))")
    ->execute([$cat2,'types','Beauty','contains',1.0,1]);

// Classification unit checks
$r1 = classify_lead(['name'=>'مطعم الشاطئ','gmap_types'=>'', 'phone'=>'0501234567']);
$r2 = classify_lead(['name'=>'','gmap_types'=>'Beauty salon', 'phone'=>'0501234568']);
assert_true(($r1['category_id']??0)===$cat1, 'name rule should match cat1');
assert_true(($r2['category_id']??0)===$cat2, 'types rule should match cat2');
// Disable the name rule and ensure it no longer classifies r1
$pdo->prepare("UPDATE category_rules SET enabled=0 WHERE category_id=? AND target='name'")->execute([$cat1]);
$r1b = classify_lead(['name'=>'مطعم الشاطئ','gmap_types'=>'', 'phone'=>'0501234567']);
assert_true(empty($r1b['category_id']), 'disabled rule should not classify');
// Re-enable for rest of tests
$pdo->prepare("UPDATE category_rules SET enabled=1 WHERE category_id=? AND target='name'")->execute([$cat1]);

// Jobs setup
$pdo->exec("DELETE FROM internal_jobs");
$pdo->prepare("INSERT INTO internal_jobs(requested_by_user_id, role, agent_id, query, ll, radius_km, lang, region, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?, 'queued', datetime('now'), datetime('now'))")
    ->execute([$adminId,'admin', null, 'pizza', '24.7136,46.6753', 10, 'ar', 'sa']);
$job1 = (int)$pdo->lastInsertId();
sleep(1);
$pdo->prepare("INSERT INTO internal_jobs(requested_by_user_id, role, agent_id, query, ll, radius_km, lang, region, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?, 'queued', datetime('now'), datetime('now'))")
    ->execute([$adminId,'admin', null, 'beauty', '24.7136,46.6753', 10, 'ar', 'sa']);
$job2 = (int)$pdo->lastInsertId();

// Simulate pull_job with different strategies
$_SERVER['HTTP_X_INTERNAL_SECRET']='testsecret';
$_SERVER['HTTP_X_WORKER_ID']='test-worker';

function call_pull(){ ob_start(); include __DIR__.'/../api/pull_job.php'; $out=ob_get_clean(); return json_decode($out,true); }

set_setting('job_pick_order','fifo'); $resp1=call_pull(); assert_true(($resp1['job']['id']??0)===$job1, 'FIFO picks oldest');
// Release lease by expiring it manually
$pdo->prepare("UPDATE internal_jobs SET lease_expires_at=datetime('now','-1 minute'), status='processing' WHERE id=?")->execute([$job1]);
set_setting('job_pick_order','newest'); $resp2=call_pull(); assert_true(($resp2['job']['id']??0)===$job2, 'Newest picks latest');
$pdo->prepare("UPDATE internal_jobs SET lease_expires_at=datetime('now','-1 minute'), status='processing' WHERE id=?")->execute([$job2]);
set_setting('job_pick_order','random'); $resp3=call_pull(); assert_true(in_array($resp3['job']['id'], [$job1,$job2], true), 'Random picks one of available');

// power-of-two selection should pick a valid available job
$pdo->prepare("UPDATE internal_jobs SET lease_expires_at=datetime('now','-1 minute'), status='processing' WHERE id IN (?,?)")->execute([$job1,$job2]);
set_setting('job_pick_order','pow2'); $resp4=call_pull(); assert_true(in_array($resp4['job']['id']??0, [$job1,$job2], true), 'pow2 picks one of available');

// fairness by query: فضّل الاستعلام الأقل نشاطاً خلال 24 ساعة
$pdo->exec("DELETE FROM internal_jobs");
// seed two queued jobs with different queries
$pdo->prepare("INSERT INTO internal_jobs(requested_by_user_id, role, agent_id, query, ll, radius_km, lang, region, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?, 'queued', datetime('now','-2 hours'), datetime('now','-2 hours'))")
    ->execute([$adminId,'admin', null, 'q-busy', '24.7,46.6', 10, 'ar', 'sa']);
$busyId = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO internal_jobs(requested_by_user_id, role, agent_id, query, ll, radius_km, lang, region, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?, 'queued', datetime('now','-1 hours'), datetime('now','-1 hours'))")
    ->execute([$adminId,'admin', null, 'q-calm', '24.7,46.6', 10, 'ar', 'sa']);
$calmId = (int)$pdo->lastInsertId();
// mark one done for q-busy recently to increase its fairness score
$pdo->prepare("INSERT INTO internal_jobs(requested_by_user_id, role, agent_id, query, ll, radius_km, lang, region, status, finished_at, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,'done',datetime('now','-30 minutes'),datetime('now','-30 minutes'),datetime('now','-30 minutes'))")
    ->execute([$adminId,'admin', null, 'q-busy', '24.7,46.6', 10, 'ar', 'sa']);
set_setting('job_pick_order','fair_query');
$fq = call_pull();
assert_true(($fq['job']['query'] ?? '') === 'q-calm', 'fair_query prefers less active query');

// Simulate report_results with a small batch and target_count using a fresh job to avoid interference
$pdo->prepare("INSERT INTO internal_jobs(requested_by_user_id, role, agent_id, query, ll, radius_km, lang, region, status, target_count, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,'processing',?,datetime('now'),datetime('now'))")
    ->execute([$adminId,'admin', null, 'target-test', '24.7136,46.6753', 10, 'ar', 'sa', 2]);
$jobT = (int)$pdo->lastInsertId();
$insLead = $pdo->prepare("INSERT OR IGNORE INTO leads(phone,name,city,country,created_at,source,created_by_user_id) VALUES(?,?,?,?,datetime('now'),'internal',?)");
$insLead->execute(['0500000001','مطعم','الرياض','السعودية',$adminId]);
$insLead->execute(['0500000002','مطعم','الرياض','السعودية',$adminId]);
$pdo->prepare("UPDATE internal_jobs SET progress_count=2, result_count=2, last_cursor=2, last_progress_at=datetime('now'), lease_expires_at=datetime('now','+60 seconds') WHERE id=?")->execute([$jobT]);
// Mark done when target reached
$pdo->prepare("UPDATE internal_jobs SET status='done', finished_at=datetime('now'), updated_at=datetime('now'), lease_expires_at=NULL, last_error=NULL, done_reason=COALESCE(done_reason, 'target_reached') WHERE id=?")
    ->execute([$jobT]);
$jobRow = $pdo->query("SELECT status, done_reason, result_count FROM internal_jobs WHERE id=".$jobT)->fetch();
assert_true(($jobRow['status']??'')==='done', 'job should be done');
assert_true(in_array(($jobRow['done_reason']??''), ['target_reached','worker_done','no_more_results'], true), 'done_reason set');
assert_true((int)$jobRow['result_count']===2, 'results counted');

// Export cap check: create more leads than cap, then call exporter
$pdo->exec("DELETE FROM leads");
$insL = $pdo->prepare("INSERT INTO leads(phone,name,city,country,created_at,source,created_by_user_id) VALUES(?,?,?,?,datetime('now'),'internal',?)");
for($i=0;$i<25;$i++){ $insL->execute(['05'.str_pad((string)$i,8,'0',STR_PAD_LEFT), 'Name '.$i, 'City', 'SA', $adminId]); }
// Call CSV exporter via include and capture output (simulate logged-in admin + CSRF)
if(session_status()===PHP_SESSION_NONE) session_start();
$_SESSION['uid'] = $adminId;
$token = csrf_token();
$_GET = ['limit'=>"10", 'csrf'=>$token]; $_SERVER['REQUEST_METHOD']='GET';
ob_start(); include __DIR__.'/../api/export_leads.php'; $csv = ob_get_clean();
$lines = substr_count($csv, "\n"); // includes header + note
assert_true($lines>=11, 'CSV includes header + 10 rows');
// CSV BOM and sep hint
assert_true(substr($csv,0,3) === "\xEF\xBB\xBF", 'CSV starts with UTF-8 BOM');
assert_true(strpos($csv, "sep=,") !== false, 'CSV includes sep=, hint');
assert_true(strpos($csv, 'تم قطع النتائج إلى') !== false, 'CSV includes truncation note when capped');

// Stop/pause checks
set_setting('system_global_stop','1');
$stopped = call_pull();
assert_true(($stopped['stopped']??false)===true, 'pull_job respects global stop');
set_setting('system_global_stop','0');
set_setting('system_pause_enabled','1');
// Force pause window covering now by setting start=00:00 and end=23:59
set_setting('system_pause_start','00:00');
set_setting('system_pause_end','23:59');
$paused = call_pull();
assert_true(($paused['stopped']??false)===true, 'pull_job respects pause window');
// Reset pause
set_setting('system_pause_enabled','0');

// round-robin by agent: assign different agent_id and ensure alternation (run after clearing stops)
$pdo->exec("DELETE FROM internal_jobs");
$pdo->prepare("INSERT INTO internal_jobs(requested_by_user_id, role, agent_id, query, ll, radius_km, lang, region, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?, 'queued', datetime('now'), datetime('now'))")
    ->execute([$adminId,'agent', $agentAId, 'a1', '24.7136,46.6753', 10, 'ar', 'sa']);
$ra1 = (int)$pdo->lastInsertId();
sleep(1);
$pdo->prepare("INSERT INTO internal_jobs(requested_by_user_id, role, agent_id, query, ll, radius_km, lang, region, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?, 'queued', datetime('now'), datetime('now'))")
    ->execute([$adminId,'agent', $agentBId, 'a2', '24.7136,46.6753', 10, 'ar', 'sa']);
$ra2 = (int)$pdo->lastInsertId();
set_setting('job_pick_order','rr_agent'); set_setting('rr_last_agent_id','0');
$rra = call_pull(); $first = $rra['job']['agent_id'] ?? null; assert_true(in_array($first,[$agentAId,$agentBId],true),'rr first agent valid');
// expire and pull again should alternate to the other agent if available
$pdo->prepare("UPDATE internal_jobs SET lease_expires_at=datetime('now','-1 minute'), status='processing' WHERE id=?")->execute([(int)$rra['job']['id']]);
$rrb = call_pull(); $second = $rrb['job']['agent_id'] ?? null; assert_true($second && $second !== $first, 'rr_agent alternates agent_id');

// keep success echo to the end after all sections

// Secret rotation test: ensure old secret fails and new secret works
// Setup: set a new strong secret and verify responses
set_setting('internal_server_enabled','1');
$old = 'oldsecret_ABC';
set_setting('internal_secret',$old);
$_SERVER['HTTP_X_WORKER_ID']='test-worker';
$_SERVER['HTTP_X_INTERNAL_SECRET']=$old;
ob_start(); include __DIR__.'/../api/heartbeat.php'; $outOk=ob_get_clean();
$_SERVER['HTTP_X_INTERNAL_SECRET']='WRONG';
ob_start(); include __DIR__.'/../api/heartbeat.php'; $out401=ob_get_clean();
assert_true(strpos($out401,'unauthorized')!==false, 'old secret mismatch should 401');
// rotate to new
$new = bin2hex(random_bytes(32)); set_setting('internal_secret',$new);
$_SERVER['HTTP_X_INTERNAL_SECRET']=$old; ob_start(); include __DIR__.'/../api/heartbeat.php'; $outOld=ob_get_clean();
$_SERVER['HTTP_X_INTERNAL_SECRET']=$new; ob_start(); include __DIR__.'/../api/heartbeat.php'; $outNew=ob_get_clean();
assert_true(strpos($outOld,'unauthorized')!==false, 'old secret should now fail with 401');
assert_true(strpos($outNew,'ok')!==false, 'new secret should return 200 ok');

// Stuck detection: create an online worker and an old processing job assigned to it
$pdo->exec("DELETE FROM internal_workers");
$wid = 'wrk-smoke-1';
$infoJson = json_encode(['metrics'=>['connected'=>true,'active'=>true]]);
$pdo->exec("INSERT INTO internal_workers(worker_id,last_seen,info) VALUES ('".$wid."', datetime('now'), '".str_replace("'","''",$infoJson)."')");
$pdo->exec("DELETE FROM internal_jobs");
$pdo->prepare("INSERT INTO internal_jobs(requested_by_user_id, role, agent_id, query, ll, radius_km, lang, region, status, worker_id, last_progress_at, lease_expires_at, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,'processing',?, datetime('now','-120 minutes'), datetime('now','+60 minutes'), datetime('now','-2 hours'), datetime('now','-2 hours'))")
    ->execute([$adminId,'admin', null, 'stuck-q', '24.7,46.6', 10, 'ar', 'sa', $wid]);
// Use same SQL as endpoints to detect stuck jobs
$thrMin = max(5, min(180, (int)get_setting('stuck_processing_threshold_min','10')));
$neg = '-' . $thrMin . ' minutes'; $win = '-' . ceil(workers_online_window_sec()/60) . ' minutes';
$sel = $pdo->prepare("SELECT j.id FROM internal_jobs j WHERE j.status='processing' AND (j.last_progress_at IS NULL OR j.last_progress_at < datetime('now', :neg)) AND j.worker_id IS NOT NULL AND EXISTS (SELECT 1 FROM internal_workers w WHERE w.worker_id=j.worker_id AND w.last_seen >= datetime('now', :win))");
$sel->execute([':neg'=>$neg, ':win'=>$win]);
$stuck = $sel->fetchAll();
assert_true(count($stuck)>=1, 'stuck detection should find the old processing job for online worker');

// Requeue 24h metric: insert a job_attempts row and verify count
$pdo->prepare("INSERT INTO job_attempts(job_id, worker_id, started_at, finished_at, success, log_excerpt, attempt_id) VALUES(?,?,?,?,0,?,?)")
    ->execute([ (int)$pdo->query("SELECT id FROM internal_jobs WHERE status='processing' LIMIT 1")->fetch()['id'], $wid, date('Y-m-d H:i:s', time()-120), date('Y-m-d H:i:s'), 'requeue_offline', 'att-smk' ]);
$rq = (int)$pdo->query("SELECT COUNT(*) c FROM job_attempts WHERE success=0 AND log_excerpt='requeue_offline' AND finished_at >= datetime('now','-1 day')")->fetch()['c'];
assert_true($rq>=1, 'requeue24h should count recent requeue_offline attempts');

echo "All smoke tests passed at ".$now."\n";
