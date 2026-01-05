<?php
// Security headers (additive, no behavior change)
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
// CSP intentionally omitted to avoid breaking inline scripts on existing pages.

include __DIR__ . '/../../layout_header.php'; $u=require_role('admin'); require_once __DIR__ . '/../../lib/csrf.php'; $pdo=db();

// Helpers
function diag_count_last24($pdo, $status){
  // Try MySQL-compatible first, fallback to SQLite syntax
  $params = [':st'=>$status];
  try{
    $qMy = "SELECT COUNT(*) c FROM internal_jobs WHERE status=:st AND ((status='queued' AND created_at >= NOW() - INTERVAL 24 HOUR) OR (status!='queued' AND updated_at >= NOW() - INTERVAL 24 HOUR))";
    $st = $pdo->prepare($qMy); $st->execute($params); $r=$st->fetch(); if($r!==false) return (int)($r['c']??0);
  }catch(Throwable $e){ /* fallback below */ }
  try{
    $qSq = "SELECT COUNT(*) c FROM internal_jobs WHERE status=:st AND ((status='queued' AND created_at >= datetime('now','-24 hours')) OR (status!='queued' AND updated_at >= datetime('now','-24 hours')))";
    $st = $pdo->prepare($qSq); $st->execute($params); $r=$st->fetch(); return (int)($r['c']??0);
  }catch(Throwable $e){ return 0; }
}
// Attempts view removed for production cut (kept read-only focus)
function diag_env_checks(){
  $keys = [
    'INTERNAL_SECRET'      => (get_setting('internal_secret','')!=='') ,
    'LEASE_SEC_DEFAULT'    => (get_setting('LEASE_SEC_DEFAULT',null)!==null),
    'BACKOFF_BASE_SEC'     => (get_setting('BACKOFF_BASE_SEC',null)!==null),
    'BACKOFF_MAX_SEC'      => (get_setting('BACKOFF_MAX_SEC',null)!==null),
    'MAX_ATTEMPTS_DEFAULT' => (get_setting('MAX_ATTEMPTS_DEFAULT',null)!==null),
    'GOOGLE_API_KEY'       => (get_setting('google_api_key','')!=='')
  ];
  return $keys;
}

// Workers Health helpers (read-only)
function diag_workers_index_hints($pdo){
  try{ $pdo->exec("CREATE INDEX IF NOT EXISTS idx_internal_workers_last_seen ON internal_workers(last_seen)"); }catch(Throwable $e){}
}
function diag_workers_fetch($pdo, $limit){
  $lim = max(1,(int)$limit);
  try{
    $st = $pdo->query("SELECT worker_id, last_seen, info FROM internal_workers ORDER BY last_seen DESC LIMIT ".$lim);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  }catch(Throwable $e){ $rows=[]; }
  $out = [];
  $nowTs = time();
  foreach($rows as $r){
    $info = [];
    if(isset($r['info']) && $r['info']){
      $decoded = json_decode($r['info'], true);
      if(is_array($decoded)) $info = $decoded;
    }
    $host = $info['host'] ?? '';
    $ver  = ($info['ver'] ?? ($info['version'] ?? ''));
    $stt  = $info['status'] ?? '';
    $active = $info['active_job_id'] ?? null;
    $lastSeen = $r['last_seen'] ?? '';
    $isOnline = false; // consider online if seen in last 2 minutes
    if($lastSeen){ $isOnline = (strtotime($lastSeen) >= ($nowTs - 120)); }
    $out[] = [
      'worker_id' => $r['worker_id'],
      'host' => $host,
      'version' => $ver,
      'status' => $stt,
      'active_job_id' => $active,
      'last_seen' => $lastSeen,
      'online' => $isOnline
    ];
  }
  return $out;
}

// Recent Jobs helpers (read-only)
function diag_jobs_index_hints($pdo){
  try{ $pdo->exec("CREATE INDEX IF NOT EXISTS idx_internal_jobs_status_updated ON internal_jobs(status, updated_at)"); }catch(Throwable $e){}
  try{ $pdo->exec("CREATE INDEX IF NOT EXISTS idx_internal_jobs_worker_updated ON internal_jobs(worker_id, updated_at)"); }catch(Throwable $e){}
}
function diag_recent_jobs($pdo, $limit, $statusFilter){
  $lim = max(1,(int)$limit);
  $allowed = ['','queued','processing','done','failed'];
  $status = in_array($statusFilter,$allowed,true)? $statusFilter : '';
  $where = $status? "WHERE status=".$pdo->quote($status) : '';
  try{
    $rows = $pdo->query("SELECT * FROM internal_jobs $where ORDER BY updated_at DESC LIMIT ".$lim)->fetchAll(PDO::FETCH_ASSOC);
  }catch(Throwable $e){ $rows = []; }
  // Normalize a few fields for display
  foreach($rows as &$r){
    $r['err'] = $r['last_error'] ?? ($r['error'] ?? '');
    // prefer job_type if exists, else role
    $r['type'] = isset($r['job_type']) && $r['job_type']? $r['job_type'] : ($r['role'] ?? '');
  }
  return $rows;
}

// Summary counters (24h)
$counts = [ 'queued' => diag_count_last24($pdo,'queued'), 'processing' => diag_count_last24($pdo,'processing'), 'failed' => diag_count_last24($pdo,'failed') ];
// Places batches helpers (read-only)
function diag_places_batches($pdo, $limit){
  try{
    $lim = max(1,(int)$limit);
    $st = $pdo->query("SELECT batch_id, MIN(collected_at) AS first_seen, MAX(last_seen_at) AS last_seen, COUNT(*) AS total_rows FROM places WHERE batch_id IS NOT NULL AND batch_id!='' GROUP BY batch_id ORDER BY last_seen DESC LIMIT ".$lim);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    // attach top types (best-effort from types_json)
    foreach($rows as &$r){
      $bid = $r['batch_id']; $top = [];
      $st2 = $pdo->prepare("SELECT types_json FROM places WHERE batch_id=? LIMIT 500"); // sample 500 rows for speed
      $st2->execute([$bid]);
      $counts = [];
      while($x = $st2->fetch(PDO::FETCH_ASSOC)){
        $tj = json_decode($x['types_json'] ?? '[]', true);
        if(is_array($tj)){
          foreach($tj as $t){ if(!is_string($t)) continue; $t = trim($t); if($t==='') continue; $counts[$t] = ($counts[$t] ?? 0) + 1; }
        } elseif (is_string($x['types_json']) && $x['types_json']!==''){
          // fallback: treat as comma-separated
          $parts = array_map('trim', explode(',', $x['types_json']));
          foreach($parts as $t){ if($t==='') continue; $counts[$t] = ($counts[$t] ?? 0) + 1; }
        }
      }
  arsort($counts); $top = array_slice(array_keys($counts), 0, 3);
  $r['top_types'] = implode(', ', array_map(function($s){ return substr($s,0,24); }, $top));
    }
    return $rows;
  }catch(Throwable $e){ return []; }
}
function diag_places_index_hint($pdo){
  try{ $pdo->exec("CREATE INDEX IF NOT EXISTS idx_places_batch_id ON places(batch_id);"); }catch(Throwable $e){}
}
diag_places_index_hint($pdo);
$showMore = isset($_GET['more']) && $_GET['more']==='1';
$batches = diag_places_batches($pdo, $showMore? 20 : 5);

// Initialize workers/jobs diagnostics
diag_workers_index_hints($pdo);
diag_jobs_index_hints($pdo);
$workersMore = isset($_GET['workers_more']) && $_GET['workers_more']==='1';
$jobsMore = isset($_GET['jobs_more']) && $_GET['jobs_more']==='1';
$jobStatus = isset($_GET['job_status']) ? preg_replace('/[^a-z_]/','', $_GET['job_status']) : '';
// Accept 'succeeded' as alias for 'done' without changing existing labels/logic
if ($jobStatus === 'succeeded') { $jobStatus = 'done'; }
$workers = diag_workers_fetch($pdo, $workersMore? 50 : 10);
$recentJobs = diag_recent_jobs($pdo, $jobsMore? 100 : 20, $jobStatus);
?>
<div class="card">
  <h2>التشخيصات</h2>
  <!-- ملخص الإعدادات -->
  <div class="card" style="margin-top:8px;padding:12px">
    <div class="muted">ملخص الإعدادات</div>
    <?php
      // Settings snapshot (read-only; no secrets)
      $snap = [
        'PLACES_PROVIDER' => get_setting('PLACES_PROVIDER','web') ?: '—',
        'RATE_LIMIT_GLOBAL_PER_MIN' => get_setting('RATE_LIMIT_GLOBAL_PER_MIN','—') ?: '—',
        'RATE_LIMIT_PER_WORKER_PER_MIN' => get_setting('RATE_LIMIT_PER_WORKER_PER_MIN','—') ?: '—',
        'QUEUE_BACKEND' => get_setting('QUEUE_BACKEND','internal') ?: 'internal',
        'INTERNAL_SECRET_SET' => get_setting('internal_secret','')!=='' ? 'مضبوط' : 'غير مضبوط',
      ];
    ?>
    <table style="width:100%;margin-top:8px"><tbody>
      <tr><td class="kbd">PLACES_PROVIDER</td><td class="muted"><?php echo htmlspecialchars($snap['PLACES_PROVIDER']); ?></td></tr>
      <tr><td class="kbd">معدل عام/دقيقة</td><td class="muted"><?php echo htmlspecialchars($snap['RATE_LIMIT_GLOBAL_PER_MIN']); ?></td></tr>
      <tr><td class="kbd">معدل لكل عامل/دقيقة</td><td class="muted"><?php echo htmlspecialchars($snap['RATE_LIMIT_PER_WORKER_PER_MIN']); ?></td></tr>
      <tr><td class="kbd">نظام الطابور</td><td class="muted"><?php echo htmlspecialchars($snap['QUEUE_BACKEND']); ?></td></tr>
      <tr><td class="kbd">سر داخلي</td><td class="muted"><?php echo htmlspecialchars($snap['INTERNAL_SECRET_SET']); ?></td></tr>
    </tbody></table>
  </div>

  <!-- صحة الوحدات الطرفية -->
  <div class="card" style="margin-top:12px;padding:12px">
    <div style="display:flex;align-items:center;justify-content:space-between">
      <div class="muted">صحة الوحدات الطرفية</div>
      <div>
        <?php if(!$workersMore): ?>
          <a class="btn" href="<?php echo linkTo('admin/diagnostics/index.php?workers_more=1'); ?>">عرض المزيد</a>
        <?php else: ?>
          <a class="btn" href="<?php echo linkTo('admin/diagnostics/index.php'); ?>">عرض أقل</a>
        <?php endif; ?>
      </div>
    </div>
  <table data-dt="1" data-table-key="admin:diag:workers" data-sticky-first="1" style="width:100%">
      <thead>
        <tr>
          <th class="muted">المعرّف</th>
          <th class="muted">الحالة</th>
          <th class="muted">آخر ظهور</th>
          <th class="muted">المضيف</th>
          <th class="muted">الإصدار</th>
          <th class="muted">المهمة الحالية</th>
        </tr>
      </thead>
      <tbody>
  <?php foreach($workers as $w): $online=$w['online']; $wid = (string)($w['worker_id']??''); ?>
          <tr>
            <td class="kbd" title="<?php echo htmlspecialchars($wid); ?>"><?php echo $wid!==''? htmlspecialchars(mb_substr($wid,0,24)) : '—'; ?></td>
            <td><?php if($online): ?><span class="badge">متصل</span><?php else: ?><span class="badge danger">غير متصل</span><?php endif; ?></td>
            <td class="muted"><?php $ls=$w['last_seen']??''; echo $ls!==''? htmlspecialchars($ls) : '—'; ?></td>
            <td class="muted"><?php $host=$w['host']??''; echo $host!==''? htmlspecialchars($host) : '—'; ?></td>
            <td class="muted"><?php echo ($w['version'] ?? '')!==''? htmlspecialchars($w['version']) : '—'; ?></td>
            <td class="muted"><?php echo $w['active_job_id']? ('#'.(int)$w['active_job_id']) : '—'; ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if(!$workers): ?>
          <tr>
            <td class="muted" title="no-data">لا توجد وحدات طرفية مُسجّلة حتى الآن.</td>
            <td class="muted">—</td>
            <td class="muted">—</td>
            <td class="muted">—</td>
            <td class="muted">—</td>
            <td class="muted">—</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- الوظائف الحديثة -->
  <div class="card" style="margin-top:12px;padding:12px">
    <div style="display:flex;align-items:center;justify-content:space-between">
      <div class="muted">الوظائف الحديثة</div>
      <div style="display:flex;gap:8px;align-items:center">
        <form method="get" action="<?php echo linkTo('admin/diagnostics/index.php'); ?>" style="display:inline-flex;gap:6px;align-items:center" data-persist>
          <label class="muted">تصفية الحالة:</label>
          <select name="job_status" onchange="this.form.submit()">
            <?php $opts=[''=>'الكل','queued'=>'قيد الانتظار','processing'=>'قيد المعالجة','done'=>'منتهية','failed'=>'فاشلة']; foreach($opts as $val=>$label): ?>
              <option value="<?php echo htmlspecialchars($val); ?>" <?php echo ($jobStatus===$val?'selected':''); ?>><?php echo htmlspecialchars($label); ?></option>
            <?php endforeach; ?>
          </select>
          <?php if($jobsMore): ?><input type="hidden" name="jobs_more" value="1"><?php endif; ?>
          <button class="btn outline" type="button" data-persist-reset title="مسح التفضيلات">مسح</button>
        </form>
        <?php
          $qs = http_build_query(array_filter([
            'job_status' => $jobStatus ?: null,
            'jobs_more' => $jobsMore? '1' : null,
          ]));
          $base = 'admin/diagnostics/index.php';
        ?>
        <?php if(!$jobsMore): ?>
          <a class="btn" href="<?php echo linkTo($base . ($qs? ('?'.$qs.'&'):'?') . 'jobs_more=1'); ?>">عرض المزيد</a>
        <?php else: ?>
          <a class="btn" href="<?php echo linkTo($base . ($jobStatus? ('?job_status='.urlencode($jobStatus)) : '')); ?>">عرض أقل</a>
        <?php endif; ?>
      </div>
    </div>
    <div class="muted" style="margin:8px 0;display:flex;gap:12px">
      <span>قيد الانتظار: <b class="kbd"><?php echo (int)$counts['queued']; ?></b></span>
      <span>قيد المعالجة: <b class="kbd"><?php echo (int)$counts['processing']; ?></b></span>
      <span>فاشلة: <b class="kbd"><?php echo (int)$counts['failed']; ?></b></span>
    </div>
  <table data-dt="1" data-table-key="admin:diag:recent-jobs" data-sticky-first="1" style="width:100%">
      <thead>
        <tr>
          <th class="muted">#</th>
          <th class="muted">النوع</th>
          <th class="muted">الحالة</th>
          <th class="muted">العامل</th>
          <th class="muted">الإنشاء</th>
          <th class="muted">آخر تحديث</th>
          <th class="muted">الانتهاء</th>
          <th class="muted">خطأ (مختصر)</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($recentJobs as $j): ?>
          <?php
            $chipBg = ($j['status']==='done')? '#0b3a1a' : (($j['status']==='processing')? '#2a2a57' : (($j['status']==='failed')? '#7f1d1d' : '#5b3a0b'));
            $errShort = mb_substr((string)($j['err']??''), 0, 120);
          ?>
          <tr>
            <td class="kbd">#<?php echo (int)$j['id']; ?></td>
            <td class="muted" title="<?php $t=($j['type'] ?? '') ?: ($j['role'] ?? ''); echo htmlspecialchars($t); ?>"><?php $t=($j['type'] ?? '') ?: ($j['role'] ?? ''); echo $t!==''? htmlspecialchars(mb_substr($t,0,24)) : '—'; ?></td>
            <td><span class="badge" style="background:<?php echo $chipBg; ?>"><?php echo htmlspecialchars($j['status'] ?? '—'); ?></span></td>
            <td class="muted"><?php $wk=$j['worker_id']??''; echo $wk!==''? htmlspecialchars($wk) : '—'; ?></td>
            <td class="muted"><?php $cr=$j['created_at']??''; echo $cr!==''? htmlspecialchars($cr) : '—'; ?></td>
            <td class="muted"><?php $up=$j['updated_at']??''; echo $up!==''? htmlspecialchars($up) : '—'; ?></td>
            <td class="muted"><?php $fi=$j['finished_at']??''; echo $fi!==''? htmlspecialchars($fi) : '—'; ?></td>
            <?php $fullErr = (string)($j['err'] ?? ''); $cell = ($errShort!=='' ? htmlspecialchars($errShort) : '—'); ?>
            <td class="muted" style="max-width:360px">
              <?php if($fullErr!==''): ?>
                <details class="collapsible">
                  <summary><?php echo $cell; ?></summary>
                  <div class="collapsible-body">
                    <div class="row" style="justify-content:flex-end"><button type="button" class="btn xs outline" data-copy data-copy-text="<?php echo htmlspecialchars($fullErr, ENT_QUOTES); ?>">نسخ</button></div>
                    <pre class="code-block" style="max-height:220px"><?php echo htmlspecialchars($fullErr); ?></pre>
                  </div>
                </details>
              <?php else: ?>
                —
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if(!$recentJobs): ?>
          <tr>
            <td class="muted" title="no-data">لا توجد وظائف حديثة.</td>
            <td class="muted">—</td>
            <td class="muted">—</td>
            <td class="muted">—</td>
            <td class="muted">—</td>
            <td class="muted">—</td>
            <td class="muted">—</td>
            <td class="muted">—</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- دفعات الأماكن -->
  <div class="card" style="margin-top:12px;padding:12px">
    <div style="display:flex;align-items:center;justify-content:space-between">
      <div class="muted">دفعات الأماكن</div>
      <div>
        <?php if(!$showMore): ?>
          <a class="btn" href="<?php echo linkTo('admin/diagnostics/index.php?more=1'); ?>">عرض المزيد</a>
        <?php else: ?>
          <a class="btn" href="<?php echo linkTo('admin/diagnostics/index.php'); ?>">عرض أقل</a>
        <?php endif; ?>
      </div>
    </div>
  <table data-dt="1" data-table-key="admin:diag:batches" data-sticky-first="1" style="width:100%">
      <thead>
        <tr>
          <th class="muted">المعرّف</th>
          <th class="muted">إجمالي السجلات</th>
          <th class="muted">أول ظهور</th>
          <th class="muted">آخر ظهور</th>
          <th class="muted">أبرز الأنواع</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($batches as $b): $bid=$b['batch_id']; $short=substr($bid,0,12).(strlen($bid)>12?'…':''); ?>
          <tr>
            <td class="kbd" title="<?php echo htmlspecialchars($bid); ?>">
              <span><?php echo htmlspecialchars($short); ?></span>
              <button class="btn" type="button" onclick="navigator.clipboard.writeText('<?php echo htmlspecialchars($bid,ENT_QUOTES); ?>');">نسخ</button>
            </td>
            <td class="kbd"><?php echo (int)$b['total_rows']; ?></td>
            <td class="muted"><?php $fs=$b['first_seen']??''; echo $fs!==''? htmlspecialchars($fs) : '—'; ?></td>
            <td class="muted"><?php $ls=$b['last_seen']??''; echo $ls!==''? htmlspecialchars($ls) : '—'; ?></td>
            <td class="muted" style="max-width:300px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?php $tt=$b['top_types']??''; echo $tt!==''? htmlspecialchars($tt) : '—'; ?></td>
            <td style="text-align:left">
              <form method="post" action="<?php echo linkTo('admin/diagnostics/export_batch.php'); ?>" style="display:inline">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                <input type="hidden" name="batch_id" value="<?php echo htmlspecialchars($bid); ?>">
                <button class="btn" title="تصدير CSV">تصدير CSV</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if(!$batches): ?>
          <tr>
            <td class="muted" title="no-data">لا توجد دفعات بعد.</td>
            <td class="muted">—</td>
            <td class="muted">—</td>
            <td class="muted">—</td>
            <td class="muted">—</td>
            <td class="muted">—</td>
          </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/../../layout_footer.php'; ?>
