<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/system.php';
require_once __DIR__ . '/../lib/security.php';
header('Content-Type: application/json; charset=utf-8');

// Safe header accessor for built-in PHP server compatibility
$hdr = function(string $name){
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return $_SERVER[$key] ?? null;
};

try {
    // 1) التحقق من الترويسات وتمكين السيرفر الداخلي
    $secretHeader = $hdr('X-Internal-Secret') ?? '';
    $workerId     = $hdr('X-Worker-Id') ?? 'unknown';
    $capsRaw      = $hdr('X-Worker-Caps') ?? null; // optional JSON array of capability tags

    $internalEnabled = get_setting('internal_server_enabled', '0') === '1';
    $internalSecret  = get_setting('internal_secret', '');

    if (!$internalEnabled) {
        http_response_code(403);
        echo json_encode(['error' => 'internal_disabled']);
        if(defined('UNIT_TEST') && UNIT_TEST){ return; } else { exit; }
    }
    // Auth: HMAC or legacy secret or per-worker secret
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/api/pull_job.php', PHP_URL_PATH) ?: '/api/pull_job.php';
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
    // Circuit breaker: block workers listed in cb_open_workers_json
    try{
        $raw = get_setting('cb_open_workers_json','[]');
        $arr = json_decode($raw, true); if(is_array($arr) && in_array($workerId, $arr, true)){
            http_response_code(429);
            echo json_encode(['error'=>'cb_open','message'=>'Circuit breaker is open for this worker','retry_after_sec'=>120]);
            if(defined('UNIT_TEST') && UNIT_TEST){ return; } else { exit; }
        }
    }catch(Throwable $e){}

    // 2) تحدّيث ظهور العامل (presence) مبكرًا لتقليل حالات الظهور "غير متصل" أثناء النشاط
    try { workers_upsert_seen($workerId, ['status'=>'pulling']); } catch(Throwable $e){}

    // 3) تحقق من إيقاف النظام
    if(system_is_globally_stopped() || system_is_in_pause_window()){
        echo json_encode(['job'=>null,'stopped'=>true]);
        if(defined('UNIT_TEST') && UNIT_TEST){ return; } else { exit; }
    }

    // 4) قاعدة البيانات
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 4) سحب مهمة جاهزة مع تأجير (lease) واسترجاع العالقة — نمط ذري قدر الإمكان
    $leaseReq = (int)($_GET['lease_sec'] ?? get_setting('worker_pull_interval_sec','120'));
    $leaseSec = max(30, min(600, $leaseReq));
    $now = date('Y-m-d H:i:s');
    $leaseUntil = date('Y-m-d H:i:s', time()+$leaseSec);

    $pdo->beginTransaction();

        // Proactive cleanup: requeue any jobs stuck in processing with expired lease
                        try{
                                $cut = date('Y-m-d H:i:s', time()-workers_online_window_sec());
                                $selRJ = $pdo->prepare("SELECT j.id, j.worker_id, j.claimed_at, j.attempt_id, j.next_retry_at, w.last_seen
                                    FROM internal_jobs j
                                    LEFT JOIN internal_workers w ON w.worker_id = j.worker_id
                                    WHERE j.status='processing'
                                      AND (j.lease_expires_at IS NULL OR j.lease_expires_at < :now)
                                      AND (j.next_retry_at IS NULL OR j.next_retry_at <= :now)");
                                $selRJ->execute([':now'=>$now]);
                                $requeued = $selRJ->fetchAll(PDO::FETCH_ASSOC) ?: [];
                                if($requeued){
                                    $st = $pdo->prepare(
                                        "UPDATE internal_jobs
                                            SET status='queued', worker_id=NULL, lease_expires_at=NULL, attempt_id=NULL, updated_at=datetime('now')
                                         WHERE status='processing'
                                           AND (lease_expires_at IS NULL OR lease_expires_at < :now)
                                           AND (next_retry_at IS NULL OR next_retry_at <= :now)"
                                    );
                                    $st->execute([':now'=>$now]);
                                    $ins = $pdo->prepare("INSERT INTO job_attempts(job_id,worker_id,started_at,finished_at,success,log_excerpt,attempt_id) VALUES(?,?,?,?,0,?,?)");
                                    $now2 = date('Y-m-d H:i:s');
                                    foreach($requeued as $rj){
                                        $reason = 'requeue_expired';
                                        $lastSeen = isset($rj['last_seen']) ? (string)$rj['last_seen'] : null;
                                        if($lastSeen && $lastSeen >= $cut){ $reason = 'requeue_expired_online'; }
                                        elseif(!$lastSeen || $lastSeen < $cut){ $reason = 'requeue_expired_offline'; }
                                        $ins->execute([
                                            (int)$rj['id'],
                                            (string)($rj['worker_id'] ?? ''),
                                            $rj['claimed_at'] ?: $now2,
                                            $now2,
                                            $reason,
                                            (string)($rj['attempt_id'] ?? '')
                                        ]);
                                    }
                                }
                        }catch(Throwable $e){ /* ignore */ }

    // إعداد: قاعدة الاستحقاق وترتيب افتراضي
    $pick = get_setting('job_pick_order','fifo');
    $orderSql = "COALESCE(priority,0) DESC, COALESCE(queued_at, created_at) ASC, id ASC";
    if ($pick === 'newest') { $orderSql = "COALESCE(priority,0) DESC, COALESCE(queued_at, created_at) DESC, id DESC"; }
    if ($pick === 'random') { $orderSql = "RANDOM()"; }
    $baseWhere = "(status='queued' OR (status='processing' AND (lease_expires_at IS NULL OR lease_expires_at < :now))) AND (next_retry_at IS NULL OR next_retry_at <= :now) AND (max_attempts IS NULL OR COALESCE(attempts,0) < max_attempts)";

    // دالة مساعدة: اختيار مرشح واحد عبر SELECT ثم UPDATE حذر
    $chooseAndClaim = function() use ($pdo,$baseWhere,$orderSql,$workerId,$now,$leaseUntil){
        // Pick candidate id
        $cand = $pdo->prepare("SELECT id FROM internal_jobs WHERE $baseWhere ORDER BY $orderSql LIMIT 1");
        $cand->execute([':now'=>$now]);
        $row = $cand->fetch(PDO::FETCH_ASSOC);
        if(!$row){ return null; }
        $id = (int)$row['id'];
        $attemptId = bin2hex(random_bytes(8));
        // Claim atomically
        $upd = $pdo->prepare("UPDATE internal_jobs SET status='processing', worker_id=:w, attempt_id=:aid, claimed_at=COALESCE(claimed_at,:now), updated_at=:now, attempts=COALESCE(attempts,0)+1, lease_expires_at=:lease WHERE id=:id AND $baseWhere");
        $upd->execute([':w'=>$workerId, ':aid'=>$attemptId, ':now'=>$now, ':lease'=>$leaseUntil, ':id'=>$id]);
        if($upd->rowCount()===0){ return null; }
        return [$id, $attemptId];
    };

    $candidateId = null; $attemptId = null;
    if(in_array($pick, ['fifo','newest','random'], true)){
    [$candidateId, $attemptId] = $chooseAndClaim() ?: [null,null];
    } else if($pick === 'pow2'){
        // اختر مرشحين عشوائيين ثم فضّل الأقل محاولات
        $sel = $pdo->prepare("SELECT id, COALESCE(attempts,0) AS a FROM internal_jobs WHERE $baseWhere ORDER BY RANDOM() LIMIT 2");
        $sel->execute([':now'=>$now]);
        $cands = $sel->fetchAll(PDO::FETCH_ASSOC);
        if($cands){ usort($cands, function($x,$y){ return ($x['a']<=>$y['a']); }); $pickId = (int)$cands[0]['id'];
          $upd = $pdo->prepare("UPDATE internal_jobs SET status='processing', worker_id=:w, claimed_at=COALESCE(claimed_at,:now), updated_at=:now, attempts=COALESCE(attempts,0)+1, lease_expires_at=:lease WHERE id=:id AND $baseWhere");
          $upd->execute([':w'=>$workerId, ':now'=>$now, ':lease'=>$leaseUntil, ':id'=>$pickId]);
          if($upd->rowCount()>0){ $candidateId = $pickId; $attemptId = bin2hex(random_bytes(8)); $pdo->prepare("UPDATE internal_jobs SET attempt_id=:a WHERE id=:id")->execute([':a'=>$attemptId, ':id'=>$pickId]); }
        }
    } else if($pick === 'fair_query'){
        // احسب نشاط الاستعلامات خلال 24ساعة الماضية وفضّل الأقل نشاطًا
        $activity = [];
        try{
          $q = $pdo->prepare("SELECT query, COUNT(*) c FROM internal_jobs WHERE status='done' AND finished_at > datetime('now','-24 hours') GROUP BY query");
          $q->execute(); foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r){ $activity[$r['query']] = (int)$r['c']; }
        }catch(Throwable $e){ /* ignore */ }
        $sel = $pdo->prepare("SELECT id, query, COALESCE(priority,0) p, COALESCE(queued_at, created_at) t FROM internal_jobs WHERE $baseWhere");
        $sel->execute([':now'=>$now]); $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
        if($rows){
          // find minimal activity count among eligible
          $min = null; $best = null;
          foreach($rows as $r){ $q = $r['query']; $c = $activity[$q] ?? 0; if($min===null || $c < $min || ($c===$min && $r['t'] < $best['t'])){ $min=$c; $best=$r; } }
          if($best){
            $pickId = (int)$best['id'];
            $upd = $pdo->prepare("UPDATE internal_jobs SET status='processing', worker_id=:w, claimed_at=COALESCE(claimed_at,:now), updated_at=:now, attempts=COALESCE(attempts,0)+1, lease_expires_at=:lease WHERE id=:id AND $baseWhere");
            $upd->execute([':w'=>$workerId, ':now'=>$now, ':lease'=>$leaseUntil, ':id'=>$pickId]);
            if($upd->rowCount()>0){ $candidateId = $pickId; $attemptId = bin2hex(random_bytes(8)); $pdo->prepare("UPDATE internal_jobs SET attempt_id=:a WHERE id=:id")->execute([':a'=>$attemptId, ':id'=>$pickId]); }
          }
        }
    } else if($pick === 'rr_agent'){
        // تناوب حسب agent_id إذا توفر
        $lastId = (int)get_setting('rr_last_agent_id','0');
        $sel = $pdo->prepare("SELECT id, agent_id, COALESCE(queued_at, created_at) t FROM internal_jobs WHERE $baseWhere ORDER BY t ASC");
        $sel->execute([':now'=>$now]); $rows = $sel->fetchAll(PDO::FETCH_ASSOC);
        if($rows){
          $candidate = null; $fallback = (int)$rows[0]['id'];
          foreach($rows as $r){ $aid = (int)($r['agent_id'] ?? 0); if($aid && $aid !== $lastId){ $candidate = (int)$r['id']; break; } }
          $pickId = $candidate ?: $fallback;
          $upd = $pdo->prepare("UPDATE internal_jobs SET status='processing', worker_id=:w, claimed_at=COALESCE(claimed_at,:now), updated_at=:now, attempts=COALESCE(attempts,0)+1, lease_expires_at=:lease WHERE id=:id AND $baseWhere");
          $upd->execute([':w'=>$workerId, ':now'=>$now, ':lease'=>$leaseUntil, ':id'=>$pickId]);
                    if($upd->rowCount()>0){
                        $candidateId = $pickId; $attemptId = bin2hex(random_bytes(8)); $pdo->prepare("UPDATE internal_jobs SET attempt_id=:a WHERE id=:id")->execute([':a'=>$attemptId, ':id'=>$pickId]);
            // Persist last agent id (best-effort)
            try{ $pdo->prepare("INSERT INTO settings(key,value) VALUES('rr_last_agent_id',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value")->execute([(string)($rows[array_search($pickId, array_column($rows,'id'))]['agent_id'] ?? '')]); }catch(Throwable $e){}
          }
        }
    }

    if(!$candidateId){
        $pdo->commit();
        echo json_encode(['job' => null]);
        if(defined('UNIT_TEST') && UNIT_TEST){ return; } else { exit; }
    }

    // جلب المهمة المُستلمة للتو
    $sel = $pdo->prepare("SELECT id, query, ll, role, agent_id, last_cursor, radius_km, lang, region, target_count, progress_count, result_count, attempt_id, category_id FROM internal_jobs WHERE id=:id AND status='processing' AND worker_id=:w LIMIT 1");
    $sel->execute([':id'=>$candidateId, ':w'=>$workerId]);
    $job = $sel->fetch(PDO::FETCH_ASSOC) ?: null;
    $pdo->commit();

    if (!$job) {
        // لا توجد مهمة — حافظ على الظهور
        try { workers_upsert_seen($workerId, ['status'=>'idle']); } catch(Throwable $e){}
        echo json_encode(['job' => null]);
        if(defined('UNIT_TEST') && UNIT_TEST){ return; } else { exit; }
    }

    // تحديث الظهور مع معرف المهمة النشطة
    try { workers_upsert_seen($workerId, ['status'=>'processing', 'active_job_id'=>(int)$job['id']]); } catch(Throwable $e){}

    echo json_encode([
        'job' => [
            'id'       => (int)$job['id'],
            'query'    => $job['query'],
            'll'       => $job['ll'],
            'role'     => $job['role'] ?? null,
            'agent_id' => isset($job['agent_id']) ? (int)$job['agent_id'] : null,
            'last_cursor' => isset($job['last_cursor']) ? (int)$job['last_cursor'] : 0,
            'radius_km' => isset($job['radius_km']) ? (int)$job['radius_km'] : 0,
            'lang' => $job['lang'] ?? 'ar',
            'region' => $job['region'] ?? 'sa',
            'target_count' => isset($job['target_count']) ? (int)$job['target_count'] : null,
            'progress_count' => isset($job['progress_count']) ? (int)$job['progress_count'] : 0,
            'result_count' => isset($job['result_count']) ? (int)$job['result_count'] : 0,
            'attempt_id' => $job['attempt_id'] ?? $attemptId
            , 'category_id' => isset($job['category_id']) ? (int)$job['category_id'] : null
        ],
        'lease_expires_at' => $leaseUntil,
        'lease_sec' => $leaseSec
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    $debug = isset($_GET['debug']) && $_GET['debug'] === '1';
    $resp = $debug ? ['error'=>'server_error','detail'=>$e->getMessage()] : ['error'=>'server_error'];
    echo json_encode($resp);
}
