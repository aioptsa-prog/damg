<?php include __DIR__ . '/../layout_header.php'; $u=require_role('admin'); require_once __DIR__.'/../lib/csrf.php'; $pdo=db();
// Handle global commands broadcast to workers via worker_config
$msg=''; $err='';
if($_SERVER['REQUEST_METHOD']==='POST' && csrf_verify($_POST['csrf'] ?? '')){
  $act = isset($_POST['global_cmd']) ? trim((string)$_POST['global_cmd']) : '';
  if($act!=='' && in_array($act, ['pause','resume','restart','update-now','heartbeat-now','sync-config'], true)){
    try{
      set_setting('worker_command', $act);
      set_setting('worker_command_rev', (string)time());
      try{ $pdo->prepare("INSERT INTO audit_logs(user_id,action,target,payload,created_at) VALUES(?,?,?,?,datetime('now'))")
        ->execute([$u['id'],'workers_global_command','workers:all',json_encode(['cmd'=>$act], JSON_UNESCAPED_UNICODE)]); }catch(Throwable $e){}
      $msg = 'تم إرسال الأمر العام: ' . htmlspecialchars($act);
    }catch(Throwable $e){ $err = 'فشل إرسال الأمر العام: ' . $e->getMessage(); }
  }
  // Handle per-row quick command for a specific worker
  $rowCmd = isset($_POST['row_cmd']) ? trim((string)$_POST['row_cmd']) : '';
  $wid = isset($_POST['row_wid']) ? trim((string)$_POST['row_wid']) : '';
  if($rowCmd!=='' && $wid!==''){
    if(in_array($rowCmd, ['pause','resume','restart','update-now','arm','disarm','heartbeat-now','sync-config'], true)){
      try{
        $raw = get_setting('worker_commands_json','{}'); $map = json_decode($raw, true); if(!is_array($map)) $map=[];
        $map[$wid] = [ 'command'=>$rowCmd, 'rev'=> time() ];
        set_setting('worker_commands_json', json_encode($map, JSON_UNESCAPED_UNICODE));
        try{ $pdo->prepare("INSERT INTO audit_logs(user_id,action,target,payload,created_at) VALUES(?,?,?,?,datetime('now'))")
          ->execute([$u['id'],'worker_command','worker:'.$wid,json_encode(['cmd'=>$rowCmd], JSON_UNESCAPED_UNICODE)]); }catch(Throwable $e){}
        $msg = 'تم إرسال الأمر للعامل ' . htmlspecialchars($wid) . ': ' . htmlspecialchars($rowCmd);
      }catch(Throwable $e){ $err = 'فشل إرسال الأمر: '.$e->getMessage(); }
    }
  }
  // Clear pending per-worker command
  if(isset($_POST['row_clear_cmd']) && $_POST['row_clear_cmd']==='1'){
    $wid2 = isset($_POST['row_wid']) ? trim((string)$_POST['row_wid']) : '';
    if($wid2!==''){
      try{
        $raw = get_setting('worker_commands_json','{}'); $map = json_decode($raw, true); if(!is_array($map)) $map=[];
        unset($map[$wid2]);
        set_setting('worker_commands_json', json_encode($map, JSON_UNESCAPED_UNICODE));
        $msg = 'تم مسح الأمر المعلّق للعامل ' . htmlspecialchars($wid2);
      }catch(Throwable $e){ $err = 'فشل مسح الأمر: '.$e->getMessage(); }
    }
  }
}
$cmdMapRaw = get_setting('worker_commands_json','{}');
$cmdMap = json_decode($cmdMapRaw, true); if(!is_array($cmdMap)) $cmdMap = [];
// Optional filters and pagination
$onlineOnly = isset($_GET['online']) && $_GET['online'] === '1';
$q = trim((string)($_GET['q'] ?? ''));
$limit = intval($_GET['limit'] ?? get_setting('workers_admin_page_limit','300')); if($limit<=0) $limit = 300; if($limit>2000) $limit=2000;
$page = max(1, intval($_GET['page'] ?? '1'));
$offset = ($page - 1) * $limit;
$cut = date('Y-m-d H:i:s', time() - workers_online_window_sec());
// Build WHERE conditions
$conds = [];
$params = [];
if($onlineOnly){ $conds[] = 'last_seen >= ?'; $params[] = $cut; }
if($q !== ''){ $conds[] = 'worker_id LIKE ?'; $params[] = '%'.$q.'%'; }
$where = $conds ? ('WHERE '.implode(' AND ', $conds)) : '';
// Fetch rows (paged)
$sql = "SELECT * FROM internal_workers $where ORDER BY last_seen DESC LIMIT ? OFFSET ?";
$st = $pdo->prepare($sql);
$execParams = $params; $execParams[] = $limit; $execParams[] = $offset;
$st->execute($execParams);
$rows = $st->fetchAll();
// Counters
$onlineCount = workers_online_count(false);
$totalCount = (int)$pdo->query("SELECT COUNT(*) c FROM internal_workers")->fetch()['c'];
// Matching total for current filters
$cntSql = "SELECT COUNT(*) c FROM internal_workers $where";
$stc = $pdo->prepare($cntSql); $stc->execute($params);
$matchCount = (int)($stc->fetch()['c'] ?? 0);
$totalPages = max(1, (int)ceil($matchCount / $limit));
if($page > $totalPages){ $page = $totalPages; }
// Collect versions for filter options
$versions = [];
foreach($rows as $r){
  try{ $info = $r['info']? json_decode($r['info'], true) : null; }catch(Throwable $e){ $info = null; }
  if(is_array($info)){
    $ver = isset($info['ver']) ? (string)$info['ver'] : (isset($info['version']) ? (string)$info['version'] : '');
    if($ver!==''){ $versions[$ver] = true; }
  }
}
ksort($versions);
// Quick health stats for header badges
$offlineCut = $cut;
$dlqCount = 0; $stuckCount = 0; $offlineCountQuick = 0;
try{ $offlineCountQuick = (int)$pdo->query("SELECT COUNT(*) c FROM internal_workers WHERE last_seen < datetime('now', '-".intval(workers_online_window_sec())." seconds')")->fetch()['c']; }catch(Throwable $e){}
try{ $dlqCount = (int)$pdo->query("SELECT COUNT(*) c FROM dead_letter_jobs")->fetch()['c']; }catch(Throwable $e){}
try{ $stuckCount = (int)$pdo->query("SELECT COUNT(*) c FROM internal_jobs WHERE status='processing' AND lease_expires_at IS NOT NULL AND lease_expires_at < datetime('now')")->fetch()['c']; }catch(Throwable $e){}
?>
<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
    <h2 style="margin:0">الوحدات الطرفية <span class="badge" style="margin-inline-start:8px">المتصلون: <?php echo intval($onlineCount); ?> / المجموع: <?php echo intval($totalCount); ?></span></h2>
    <div class="muted" style="display:flex;gap:6px;flex-wrap:wrap">
      <span class="badge <?php echo $offlineCountQuick>0?'warn':''; ?>" title="غير متصلين ضمن النافذة">Offline: <?php echo intval($offlineCountQuick); ?></span>
      <span class="badge <?php echo $dlqCount>0?'warn':''; ?>" title="عناصر في DLQ">DLQ: <?php echo intval($dlqCount); ?></span>
      <span class="badge <?php echo $stuckCount>0?'warn':''; ?>" title="وظائف عالقة">Stuck: <?php echo intval($stuckCount); ?></span>
    </div>
  <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
      <form method="get" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <label><input id="flt-online" type="checkbox" name="online" value="1" <?php echo $onlineOnly?'checked':''; ?> onchange="this.form.submit()"> المتصلون الآن فقط</label>
        <label>الحد
          <select name="limit" onchange="this.form.submit()">
            <?php foreach([100,200,300,500,1000,2000] as $opt): ?>
              <option value="<?php echo $opt; ?>" <?php echo $limit===$opt?'selected':''; ?>><?php echo $opt; ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>النشاط
          <select id="flt-state">
            <option value="">الكل</option>
            <option value="active">ينفّذ</option>
            <option value="paused">موقوف</option>
            <option value="idle">خامل</option>
            <option value="offline">غير متصل</option>
          </select>
        </label>
        <label>الإصدار
          <select id="flt-ver">
            <option value="">الكل</option>
            <?php foreach(array_keys($versions) as $v): ?>
              <option value="<?php echo htmlspecialchars($v); ?>"><?php echo htmlspecialchars($v); ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>بحث
          <input id="flt-q" name="q" type="text" value="<?php echo htmlspecialchars($q); ?>" placeholder="بحث بالـ Worker ID" style="min-width:220px">
        </label>
        <noscript><button class="btn xs" type="submit">تصفية</button></noscript>
      </form>
  <a class="btn sm" href="<?php echo linkTo('admin/monitor_workers.php'); ?>">لوحة حيّة متعددة</a>
  <a class="btn sm outline" href="<?php echo linkTo('docs/workers.html'); ?>" target="_blank" rel="noopener">دليل التشغيل</a>
  <label style="display:flex;align-items:center;gap:6px"><input id="auto-refresh" type="checkbox"> تحديث تلقائي</label>
  <span id="live-ind" class="badge" title="وضع التحديث" role="status" aria-live="polite" aria-atomic="true">POLL</span>
  <span id="last-updated" class="badge live-off" title="آخر تحديث" role="status" aria-live="polite" aria-atomic="true">—</span>
      <form method="post" style="display:flex;align-items:center;gap:6px;margin-inline-start:8px">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <select name="global_cmd">
          <option value="">— أمر عام —</option>
          <option value="pause">إيقاف مؤقت للجميع</option>
          <option value="resume">استئناف للجميع</option>
          <option value="sync-config">مزامنة الإعدادات</option>
          <option value="heartbeat-now">نبض فوري</option>
          <option value="update-now">تحديث الآن</option>
          <option value="restart">إعادة تشغيل</option>
        </select>
        <button class="btn xs" type="submit" onclick="return confirm('إرسال أمر عام إلى جميع العمال؟');">إرسال</button>
      </form>
      <form method="post" style="display:flex;align-items:center;gap:6px">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="global_cmd" value="pause">
        <button class="btn xs warn" type="submit" title="إيقاف مؤقت للجميع" onclick="return confirm('إيقاف مؤقت لجميع العمال؟');"><i class="fa-solid fa-pause"></i> إيقاف للجميع</button>
      </form>
      <form method="post" style="display:flex;align-items:center;gap:6px">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="global_cmd" value="resume">
        <button class="btn xs ok" type="submit" title="استئناف للجميع" onclick="return confirm('استئناف جميع العمال؟');"><i class="fa-solid fa-play"></i> استئناف للجميع</button>
      </form>
      <form method="post" style="display:flex;align-items:center;gap:6px">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="global_cmd" value="sync-config">
        <button class="btn xs" type="submit" title="مزامنة الإعدادات للجميع" onclick="return confirm('مزامنة الإعدادات لجميع العمال؟');"><i class="fa-solid fa-cloud-arrow-down"></i> مزامنة للجميع</button>
      </form>
      <form method="post" style="display:flex;align-items:center;gap:6px">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="global_cmd" value="heartbeat-now">
        <button class="btn xs" type="submit" title="نبض فوري للجميع"><i class="fa-solid fa-heart-pulse"></i> نبض للجميع</button>
      </form>
      <form method="post" style="display:flex;align-items:center;gap:6px">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="global_cmd" value="update-now">
        <button class="btn xs" type="submit" title="تحديث الآن للجميع" onclick="return confirm('تشغيل التحديث الآن لجميع العمال؟');"><i class="fa-solid fa-download"></i> تحديث للجميع</button>
      </form>
    </div>
  </div>
  <?php if($msg): ?><div class="alert success"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
  <?php if($err): ?><div class="alert"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
  <?php
    // Show last global command + rev for operator context
    try{
      $gcmd = trim((string)get_setting('worker_command',''));
      $grev = trim((string)get_setting('worker_command_rev',''));
      if($gcmd!=='' && $grev!==''){
        $revIso = @date('Y-m-d H:i:s', intval($grev));
        $sinceCount = 0;
        try{
          $stc = $pdo->prepare("SELECT COUNT(*) c FROM internal_workers WHERE last_seen >= ?");
          $stc->execute([$revIso]);
          $sinceCount = (int)($stc->fetch()['c'] ?? 0);
        }catch(Throwable $e){}
        echo '<div class="muted" style="margin:6px 0">'
          .'آخر أمر عام: <span class="kbd">'.htmlspecialchars($gcmd).'</span>'
          .' • rev=<span class="kbd">'.htmlspecialchars($grev).'</span>'
          .' • منذ: <span class="rel-time" data-iso="'.htmlspecialchars($revIso).'" title="'.htmlspecialchars($revIso).'">'.htmlspecialchars($revIso).'</span>'
          .' • شوهد منذ ذاك: <span class="kbd">'.intval($sinceCount).'</span>/<span class="kbd">'.intval($totalCount).'</span>'
          .'</div>';
      }
    }catch(Throwable $e){}
  ?>
  <div class="muted" style="margin:8px 0">عرض الصفحة <span class="kbd"><?php echo intval($page); ?></span> / <span class="kbd"><?php echo intval($totalPages); ?></span> — يُعرض <span class="kbd"><?php echo intval($limit); ?></span> صفًا من <span class="kbd"><?php echo intval($matchCount); ?></span> مطابق (إجمالي <span class="kbd"><?php echo intval($totalCount); ?></span>).</div>
  <?php if($totalPages>1): ?>
    <div class="muted" style="display:flex;align-items:center;gap:8px;margin:6px 0;flex-wrap:wrap">
      <?php
        $baseUrl = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
        $qs = $_GET; $qs['page'] = max(1, $page-1); $prev = $baseUrl.'?'.http_build_query($qs);
        $qs['page'] = min($totalPages, $page+1); $next = $baseUrl.'?'.http_build_query($qs);
      ?>
      <a class="btn xs" href="<?php echo htmlspecialchars($prev); ?>" <?php echo $page<=1?'aria-disabled="true" style="pointer-events:none;opacity:.6"':''; ?>>السابق</a>
      <a class="btn xs" href="<?php echo htmlspecialchars($next); ?>" <?php echo $page>=$totalPages?'aria-disabled="true" style="pointer-events:none;opacity:.6"':''; ?>>التالي</a>
    </div>
  <?php endif; ?>
  <?php if(!$rows): ?>
    <div class="empty mt-3">
      <i class="fa-solid fa-robot"></i>
      <h3>لا توجد وحدات طرفية</h3>
      <p>ابدأ بإعداد عامل ويندوز من صفحة الإعدادات لتظهر الوحدات المتصلة هنا.</p>
    </div>
  <?php else: ?>
  <table data-dt="1" data-table-key="admin:workers" data-sticky-first="1">
    <thead><tr>
      <th data-required="1">#</th>
      <th data-default="1">الحالة</th>
    <th data-default="1">Worker ID</th>
      <th>آخر ظهور</th>
      <th>الإصدار</th>
  <th>معلومات</th>
  <th>أوامر</th>
      <th data-required="1">بث مباشر</th>
    </tr></thead>
    <tbody>
      <?php foreach($rows as $r): ?>
        <tr
          <?php
            $isOnline = ($r['last_seen'] >= $cut);
            $info = null; try{ $info = $r['info']? json_decode($r['info'], true) : null; }catch(Throwable $e){ $info=null; }
            $m = is_array($info) && isset($info['metrics']) && is_array($info['metrics']) ? $info['metrics'] : [];
            $paused = !empty($m['paused']);
            $active = !empty($m['active']);
            $state = $isOnline ? ($paused ? 'paused' : ($active ? 'active' : 'idle')) : 'offline';
            $ver = '';
            if(is_array($info)){
              $ver = isset($info['ver']) ? (string)$info['ver'] : (isset($info['version']) ? (string)$info['version'] : '');
            }
            $cls = $isOnline ? ($paused ? 'warn' : ($active ? 'ok' : 'idle')) : 'bad';
            $label = $isOnline ? ($paused ? 'مؤقت' : ($active ? 'ينفّذ' : 'متصل')) : 'غير متصل';
            echo ' data-state="'.htmlspecialchars($state).'" data-version="'.htmlspecialchars($ver).'" data-wid="'.htmlspecialchars($r['worker_id']).'"';
          ?>
        >
          <td class="kbd"><?php echo intval($r['id']); ?></td>
          <td><span class="dot dot-<?php echo $cls; ?>" title="<?php echo htmlspecialchars($label); ?>"></span> <span class="muted"><?php echo htmlspecialchars($label); ?></span></td>
          <td>
            <?php
              // CB toggle UI (edit settings JSON list)
              $listRaw = get_setting('cb_open_workers_json','[]');
              $list = json_decode($listRaw, true); if(!is_array($list)) $list=[];
              $isOpen = in_array($r['worker_id'], $list, true);
              // Friendly name
              $namesRaw = get_setting('worker_name_overrides_json','{}');
              $namesMap = json_decode($namesRaw, true); if(!is_array($namesMap)) $namesMap=[];
              $friendly = isset($namesMap[$r['worker_id']]) ? (string)$namesMap[$r['worker_id']] : '';
              // Pending command chip
              $pcmd = isset($cmdMap[$r['worker_id']]) && is_array($cmdMap[$r['worker_id']]) ? $cmdMap[$r['worker_id']] : null;
            ?>
            <?php echo htmlspecialchars($r['worker_id']); ?>
            <button class="btn xs outline" type="button" title="نسخ المعرف" onclick="navigator.clipboard && navigator.clipboard.writeText('<?php echo htmlspecialchars($r['worker_id']); ?>').then(()=>{try{showToast('تم النسخ','success');}catch(e){}}).catch(()=>{})">نسخ</button>
            <?php if($friendly!==''): ?><div class="muted" style="font-size:12px"><?php echo htmlspecialchars($friendly); ?></div><?php endif; ?>
            <?php if($isOpen): ?><span class="badge danger" title="القاطع مفتوح — هذا العامل لن يسحب مهامًا جديدة" style="margin-inline-start:6px">قاطع</span><?php endif; ?>
            <?php if($pcmd): ?>
              <div class="muted" style="font-size:12px;margin-top:4px">
                <span class="badge warn" title="أمر معلّق سيتم التقاطه عبر worker_config">cmd: <?php echo htmlspecialchars((string)($pcmd['command']??'')); ?></span>
                <span class="badge" style="margin-inline-start:4px">rev: <?php echo htmlspecialchars((string)($pcmd['rev']??'')); ?></span>
                <form method="post" style="display:inline;margin-inline-start:6px">
                  <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                  <input type="hidden" name="row_wid" value="<?php echo htmlspecialchars($r['worker_id']); ?>">
                  <input type="hidden" name="row_clear_cmd" value="1">
                  <button class="btn xs danger" type="submit">مسح</button>
                </form>
              </div>
            <?php else: ?>
              <?php if(isset($m['lastAppliedCommandRev']) && $m['lastAppliedCommandRev']): ?>
                <div class="muted" style="font-size:12px;margin-top:4px">
                  <span class="badge ok" title="آخر أمر تم تطبيقه">تم التطبيق • rev: <?php echo htmlspecialchars((string)$m['lastAppliedCommandRev']); ?></span>
                </div>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td class="muted"><span class="rel-time" data-iso="<?php echo htmlspecialchars($r['last_seen']); ?>" title="<?php echo htmlspecialchars($r['last_seen']); ?>"><?php echo htmlspecialchars($r['last_seen']); ?></span></td>
          <td><?php echo htmlspecialchars($ver ?: '—'); ?></td>
          <td>
            <?php $infoLen = isset($r['info']) ? strlen((string)$r['info']) : 0; $widEnc = urlencode($r['worker_id']); ?>
            <details class="collapsible">
              <summary>عرض التفاصيل</summary>
              <div class="collapsible-body">
                <div class="row" style="justify-content:flex-start;gap:8px">
                  <a class="btn xs" href="<?php echo linkTo('admin/worker_info.php'); ?>?id=<?php echo intval($r['id']); ?>" target="_blank" rel="noopener">عرض كامل</a>
                  <a class="btn xs outline" href="<?php echo linkTo('admin/worker_info.php'); ?>?id=<?php echo intval($r['id']); ?>&download=1">تنزيل JSON</a>
                  <span class="muted">الحجم: <?php echo number_format($infoLen); ?> بايت</span>
                </div>
              </div>
            </details>
          </td>
          <td>
            <a class="btn sm outline" href="<?php echo linkTo('admin/worker_secret.php'); ?>?id=<?php echo urlencode($r['worker_id']); ?>">سر</a>
            <a class="btn xs" href="<?php echo linkTo('admin/worker_channel.php'); ?>?id=<?php echo urlencode($r['worker_id']); ?>">قناة</a>
            <a class="btn xs" href="<?php echo linkTo('admin/worker_manage.php'); ?>?id=<?php echo urlencode($r['worker_id']); ?>">إدارة</a>
            <?php $toggleUrl = linkTo('admin/workers_cb_toggle.php'); ?>
            <form method="post" action="<?php echo htmlspecialchars($toggleUrl); ?>" style="display:inline">
              <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
              <input type="hidden" name="id" value="<?php echo htmlspecialchars($r['worker_id']); ?>">
              <button class="btn xs <?php echo $isOpen? 'warn':'ok'; ?>" name="action" value="<?php echo $isOpen? 'close':'open'; ?>" type="submit"><?php echo $isOpen? 'إغلاق القاطع':'فتح القاطع'; ?></button>
            </form>
            <!-- Per-row quick commands -->
            <form method="post" style="display:inline;margin-inline-start:6px">
              <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
              <input type="hidden" name="row_wid" value="<?php echo htmlspecialchars($r['worker_id']); ?>">
              <select name="row_cmd">
                <option value="">—</option>
                <option value="pause">إيقاف</option>
                <option value="resume">استئناف</option>
                <option value="sync-config">مزامنة</option>
                <option value="heartbeat-now">نبض</option>
                <option value="update-now">تحديث</option>
                <option value="restart">إعادة تشغيل</option>
              </select>
              <button class="btn xs" type="submit">إرسال</button>
            </form>
          </td>
          <td>
            <a class="btn sm" href="<?php echo linkTo('admin/worker_live.php'); ?>?id=<?php echo urlencode($r['worker_id']); ?>" target="_blank" rel="noopener">بث</a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<style>
  .dot{display:inline-block;width:10px;height:10px;border-radius:50%;vertical-align:middle;margin-inline-end:6px}
  .dot-ok{background:#10b981}
  .dot-idle{background:#3b82f6}
  .dot-warn{background:#f59e0b}
  .dot-bad{background:#ef4444}
</style>
<style>
  .badge.danger{background:#991b1b;color:#fff}
  .badge.warn{background:#b45309;color:#fff}
  .badge.ok{background:#065f46;color:#d1fae5}
  .badge.live-ok{background:#0b3a2e;color:#b9f5db;border:1px solid #0f5132}
  .badge.live-off{background:#12213a;color:#9fb3d1;border:1px solid #1e3357}
</style>
<script nonce="<?php echo htmlspecialchars(csp_nonce()); ?>">
// Client-side filters for state/version and search
(function(){
  const stateSel = document.getElementById('flt-state');
  const verSel = document.getElementById('flt-ver');
  const qInput = document.getElementById('flt-q');
  const tbody = document.querySelector('table tbody');
  if(!tbody) return;
  function apply(){
    const st = (stateSel && stateSel.value)||'';
    const ver = (verSel && verSel.value)||'';
    const q = (qInput && qInput.value||'').trim().toLowerCase();
    tbody.querySelectorAll('tr').forEach(tr=>{
      const a = tr.getAttribute('data-state')||'';
      const v = tr.getAttribute('data-version')||'';
      const ok1 = !st || a===st;
      const ok2 = !ver || v===ver;
      let ok3 = true;
      if(q){
        const idCell = tr.querySelector('td:nth-child(3)');
        const txt = idCell ? (idCell.textContent||'') : '';
        ok3 = txt.toLowerCase().indexOf(q) !== -1;
      }
      tr.style.display = (ok1 && ok2 && ok3) ? '' : 'none';
    });
  }
  if(stateSel) stateSel.addEventListener('change', apply);
  if(verSel) verSel.addEventListener('change', apply);
  if(qInput) qInput.addEventListener('input', apply);
  setTimeout(apply, 0);
})();
// Persist UI filters (client-side) for state/version/online across visits
(function(){
  try{
    var k = 'persist:admin:workers';
    var stateSel = document.getElementById('flt-state');
    var verSel = document.getElementById('flt-ver');
    var online = document.getElementById('flt-online');
    var qInput = document.getElementById('flt-q');
    var autoRefresh = document.getElementById('auto-refresh');
    // Load
    var raw = localStorage.getItem(k);
    if(raw){ try{ var obj=JSON.parse(raw)||{}; if(stateSel && obj.state){ stateSel.value = obj.state; }
      if(verSel && obj.ver){ verSel.value = obj.ver; }
      if(online && typeof obj.online==='boolean'){ online.checked = obj.online; }
      if(qInput && typeof obj.q==='string'){ qInput.value = obj.q; }
      if(autoRefresh && typeof obj.auto==='boolean'){ autoRefresh.checked = obj.auto; }
    }catch(e){} }
    // Save
    function save(){ var obj={}; if(stateSel){ obj.state = stateSel.value||''; }
      if(verSel){ obj.ver = verSel.value||''; }
      if(online){ obj.online = !!online.checked; }
      if(qInput){ obj.q = qInput.value||''; }
      if(autoRefresh){ obj.auto = !!autoRefresh.checked; }
      try{ localStorage.setItem(k, JSON.stringify(obj)); }catch(e){}
    }
    stateSel && stateSel.addEventListener('change', save);
    verSel && verSel.addEventListener('change', save);
    online && online.addEventListener('change', save);
    qInput && qInput.addEventListener('input', save);
    autoRefresh && autoRefresh.addEventListener('change', function(){
      save();
      try{ window.__auto && clearInterval(window.__auto); }catch(e){}
      if(this.checked){ window.__auto = setInterval(function(){ location.reload(); }, 15000); }
    });
    if(autoRefresh && autoRefresh.checked){ window.__auto = setInterval(function(){ location.reload(); }, 15000); }
  }catch(e){}
})();
// Relative time updates for last_seen
(function(){
  function fmtRel(diffMs){
    var s = Math.floor(diffMs/1000); if(s<0) s=0;
    var m = Math.floor(s/60), h=Math.floor(m/60), d=Math.floor(h/24);
    if(s<45) return 'الآن';
    if(s<90) return 'قبل دقيقة';
    if(m<45) return 'قبل '+m+' دقيقة';
    if(m<90) return 'قبل ساعة';
    if(h<24) return 'قبل '+h+' ساعة';
    if(h<42) return 'قبل يوم';
    if(d<30) return 'قبل '+d+' يوم';
    return '';
  }
  function apply(){
    var now = Date.now();
    document.querySelectorAll('.rel-time').forEach(function(el){
      var iso = el.getAttribute('data-iso');
      if(!iso) return;
      var t = Date.parse(iso.replace(' ','T')+'Z');
      if(!isNaN(t)){
        var rel = fmtRel(now - t);
        if(rel) el.textContent = rel;
      }
    });
  }
  apply();
  setInterval(apply, 15000);
  // Refresh immediately when SSE/polling updates dispatch a custom event
  try{ window.addEventListener('refresh-rel-time', apply); }catch(_){ }
})();
</script>
<script nonce="<?php echo htmlspecialchars(csp_nonce()); ?>">
// Lightweight live refresh: polls a snapshot for visible rows and updates state without full reload
(function(){
  function setLastUpdated(d){
    try{
      var el = document.getElementById('last-updated');
      if(!el) return;
      if(!d) { el.textContent = '—'; return; }
      var opts = { hour:'2-digit', minute:'2-digit', second:'2-digit' };
      var txt = 'آخر تحديث: ' + new Date(d).toLocaleTimeString('ar', opts);
      el.textContent = txt; el.title = new Date(d).toLocaleString('ar');
    }catch(_){ }
  }
  function collectVisibleIds(){
    var ids = [];
    try{
      var rows = document.querySelectorAll('table tbody tr');
      rows.forEach(function(tr){ if(tr.style.display==='none') return; var wid = tr.getAttribute('data-wid'); if(wid) ids.push(wid); });
    }catch(_){ }
    return ids;
  }
  function updateUI(map){
    try{
      var rows = document.querySelectorAll('table tbody tr');
      rows.forEach(function(tr){
        var wid = tr.getAttribute('data-wid'); if(!wid) return; var it = map[wid]; if(!it) return;
        // Status dot and label
        var cls = it.online ? (it.paused ? 'warn' : (it.active ? 'ok' : 'idle')) : 'bad';
        var label = it.online ? (it.paused ? 'مؤقت' : (it.active ? 'ينفّذ' : 'متصل')) : 'غير متصل';
        var stateCell = tr.querySelector('td:nth-child(2)');
        if(stateCell){ var dot = stateCell.querySelector('.dot'); if(dot){ dot.className = 'dot dot-'+cls; } var t = stateCell.querySelector('.muted'); if(t){ t.textContent = label; } }
        // Last seen relative time
        var lastCell = tr.querySelector('td:nth-child(4) .rel-time');
        if(lastCell){ lastCell.setAttribute('data-iso', it.last_seen||''); lastCell.title = it.last_seen||''; }
        // Version
        var verCell = tr.querySelector('td:nth-child(5)'); if(verCell){ verCell.textContent = (it.version||'—'); }
        // Pending/applied chips area lives under the Worker ID cell (3rd td)
        var idCell = tr.querySelector('td:nth-child(3)');
        if(idCell){
          // Remove any existing trailing chip containers we created previously
          var chips = idCell.querySelectorAll('.__chipwrap'); chips.forEach(function(n){ n.remove(); });
          var wrap = document.createElement('div'); wrap.className='__chipwrap'; wrap.style.cssText='font-size:12px;margin-top:4px';
          if(it.pending && it.pending.command){
            wrap.innerHTML = '<span class="badge warn">cmd: '+ (it.pending.command||'') +'</span>'+
                             '<span class="badge" style="margin-inline-start:4px">rev: '+ (it.pending.rev||'') +'</span>';
          } else if(it.last_applied_rev){
            wrap.innerHTML = '<span class="badge ok" title="آخر أمر تم تطبيقه">تم التطبيق • rev: '+ it.last_applied_rev +'</span>';
          }
          if(wrap.innerHTML){ idCell.appendChild(wrap); }
        }
      });
      // Re-run relative time formatter to refresh UI
      try{ if(window && typeof window.dispatchEvent==='function'){ window.dispatchEvent(new Event('refresh-rel-time')); } }catch(_){ }
      setLastUpdated(Date.now());
    }catch(_){ }
  }
  function tick(){
    var ids = collectVisibleIds(); if(!ids.length) return;
    var url = '<?php echo addslashes(linkTo('api/monitor_workers_snapshot.php')); ?>' + '?'+ ids.map(function(x){ return 'ids[]='+ encodeURIComponent(x); }).join('&');
    fetch(url, { credentials:'same-origin' }).then(function(r){ return r.json(); }).then(function(j){
      if(!j || !j.ok || !j.list) return;
      var map = {}; j.list.forEach(function(it){ map[it.worker_id] = it; });
      updateUI(map);
    }).catch(function(){});
  }
  // Polling fallback (kept even with SSE but at a slower cadence when SSE is active)
  var pollIntervalMs = 12000;
  var pollTimer = setInterval(tick, pollIntervalMs);

  // Try SSE for smoother updates; auto-reconnect by reopening stream periodically or on filter change
  (function(){
    if(!('EventSource' in window)) return; // keep polling
    var sse = null, sseKey = null, liveInd = document.getElementById('live-ind');
    function setMode(mode){
      if(!liveInd) return;
      if(mode==='LIVE'){ liveInd.textContent='LIVE'; liveInd.classList.remove('live-off'); liveInd.classList.add('live-ok'); }
      else { liveInd.textContent='POLL'; liveInd.classList.remove('live-ok'); liveInd.classList.add('live-off'); }
    }
    setMode('POLL');
    function openSSE(){
      var ids = collectVisibleIds(); if(!ids.length) return;
      var key = ids.join(',');
      if(sse && key === sseKey) return; // already subscribed to this set
      if(sse){ try{sse.close();}catch(e){} sse=null; }
      var url = '<?php echo addslashes(linkTo('api/monitor_workers_stream.php')); ?>' + '?'+ ids.map(function(x){ return 'ids[]='+ encodeURIComponent(x); }).join('&') + '&interval=12&max=180';
      sse = new EventSource(url);
      sseKey = key;
      // When SSE is active, slow down polling as a safety net
      try{ clearInterval(pollTimer); }catch(_){ }
      pollTimer = setInterval(tick, 60000);
      setMode('LIVE');
      sse.onmessage = function(ev){
        try{ var j = JSON.parse(ev.data||'{}'); if(j && j.ok && j.list){ var map={}; j.list.forEach(function(it){ map[it.worker_id]=it; }); updateUI(map); } }catch(_){ }
      };
      sse.onerror = function(){
        // Switch badge to POLL, try a quick reopen soon
        setMode('POLL');
        try{ sse.close(); }catch(_){ }
        sse = null; sseKey = null;
        setTimeout(openSSE, 5000);
      };
    }
    // Initial open
    openSSE();
    // Re-open periodically to capture changed filters/visibility set
    setInterval(openSSE, 45000);
    // Also re-open on filter UI changes
    try{
      var stateSel = document.getElementById('flt-state');
      var verSel = document.getElementById('flt-ver');
      var qInput = document.getElementById('flt-q');
      if(stateSel) stateSel.addEventListener('change', function(){ setTimeout(openSSE, 10); });
      if(verSel) verSel.addEventListener('change', function(){ setTimeout(openSSE, 10); });
      if(qInput) qInput.addEventListener('input', function(){ setTimeout(openSSE, 10); });
    }catch(_){ }
  })();
})();
</script>
<?php include __DIR__ . '/../layout_footer.php'; ?>
