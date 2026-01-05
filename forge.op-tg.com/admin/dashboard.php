<?php include __DIR__ . '/../layout_header.php'; $u=require_role('admin'); $pdo=db();
// Avoid sending headers after layout output begins; rely on meta or default caching
$stats=[ 'users'=>$pdo->query("SELECT COUNT(*) c FROM users")->fetch()['c'], 'leads'=>$pdo->query("SELECT COUNT(*) c FROM leads")->fetch()['c'], 'assigned'=>$pdo->query("SELECT COUNT(*) c FROM assignments")->fetch()['c'], 'agents'=>$pdo->query("SELECT COUNT(*) c FROM users WHERE role='agent'")->fetch()['c'] ];
$internalEnabled = get_setting('internal_server_enabled','0')==='1';
$workers = workers_online_count(false);
$jobsQueued = $pdo->query("SELECT COUNT(*) c FROM internal_jobs WHERE status='queued'")->fetch()['c'];
$jobsProcessing = $pdo->query("SELECT COUNT(*) c FROM internal_jobs WHERE status='processing'")->fetch()['c'];
$jobs24 = $pdo->query("SELECT COUNT(*) c FROM internal_jobs WHERE created_at > datetime('now','-24 hours')")->fetch()['c'];
$done24 = $pdo->query("SELECT COUNT(*) c FROM internal_jobs WHERE status='done' AND finished_at > datetime('now','-24 hours')")->fetch()['c'];
// أحدث الوظائف
$recent = $pdo->query("SELECT id,status,query,ll,result_count,created_at,attempt_id,lease_expires_at FROM internal_jobs ORDER BY id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
// Synthetic p95 (best-effort)
$synFile = __DIR__.'/../storage/logs/synthetic.log';
$p95 = null; $lastOk = null;
try{
  if(is_file($synFile)){
    $lines = @file($synFile, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) ?: [];
    $lines = array_slice($lines, -50); // recent
    $lats = [];
    foreach($lines as $ln){ $j=json_decode($ln,true); if(!$j||empty($j['samples'])) continue; foreach($j['samples'] as $s){ if(isset($s['lat_ms'])) $lats[]=(float)$s['lat_ms']; } $lastOk = $j['ok']??null; }
    sort($lats); if($lats){ $idx = (int)floor(0.95*(count($lats)-1)); $p95 = round($lats[$idx]); }
  }
}catch(Throwable $e){}
?>
<div class="card fade-in">
  <h2>لوحة المدير</h2>
  <div class="kpis">
    <div class="kpi"><h4>المستخدمون</h4><div class="kpi-value"><?php echo (int)$stats['users']; ?></div><div class="kpi-sub">وكلاء: <?php echo (int)$stats['agents']; ?></div></div>
    <div class="kpi"><h4>كل الأرقام</h4><div class="kpi-value"><?php echo (int)$stats['leads']; ?></div><div class="kpi-sub">المُسنّد: <?php echo (int)$stats['assigned']; ?></div></div>
    <div class="kpi"><h4>الوحدات (≤2د)</h4><div class="kpi-value"><?php echo (int)$workers; ?></div><div class="kpi-sub"><?php echo $internalEnabled? 'السيرفر الداخلي مفعل' : 'السيرفر الداخلي مُعطّل'; ?></div></div>
    <div class="kpi"><h4>الوظائف الحالية</h4><div class="kpi-value">انتظار: <?php echo (int)$jobsQueued; ?></div><div class="kpi-sub">معالجة: <?php echo (int)$jobsProcessing; ?></div></div>
    <div class="kpi"><h4>الوظائف (24 ساعة)</h4><div class="kpi-value"><?php echo (int)$jobs24; ?></div><div class="kpi-sub">اكتملت: <?php echo (int)$done24; ?></div></div>
    <div class="kpi"><h4>Synthetic P95</h4><div class="kpi-value"><?php echo $p95!==null? ($p95.'ms') : '—'; ?></div><div class="kpi-sub"><?php echo ($lastOk===true?'OK':($lastOk===false?'FAIL':'—')); ?></div></div>
  </div>
    <div class="card">
      <div class="card-title">عمليات</div>
      <div class="row wrap gap">
  <a class="btn" href="/docs/openapi.php" target="_blank" rel="noopener">عارض OpenAPI</a>
  <a class="btn outline" href="/admin/synthetic.php" target="_blank" rel="noopener">تشغيل المراقبة التركيبية</a>
  <a class="btn outline" href="/admin/synthetic_alert.php" target="_blank" rel="noopener">تنبيه فشل المراقبة</a>
  <a class="btn outline" href="/admin/release_preflight.php" target="_blank" rel="noopener">فحص ما قبل الإصدار</a>
  <a class="btn outline" href="/admin/validate_post_deploy.php" target="_blank" rel="noopener">تحقق ما بعد النشر</a>
  <a class="btn outline" href="/admin/cleanup_safe_reset.php" target="_blank" rel="noopener">تنظيف آمن (Logs/Jobs)</a>
        <a class="btn outline" href="/admin/retention_purge.php?dry-run=1" target="_blank" rel="noopener">حذف حسب السياسات (تجريبي)</a>
      </div>
    </div>
  <div class="mt-3 qa-grid">
    <div class="qa"><h5>الصحة والتشخيص</h5><a class="btn sm" href="<?php echo linkTo('admin/health.php'); ?>">فتح</a></div>
    <div class="qa"><h5>الوظائف الداخلية</h5><a class="btn sm" href="<?php echo linkTo('admin/internal.php'); ?>">فتح</a></div>
    <div class="qa"><h5>الإعدادات</h5><a class="btn sm" href="<?php echo linkTo('admin/settings.php'); ?>">فتح</a></div>
    <div class="qa"><h5>إعداد عامل ويندوز</h5><a class="btn sm" href="<?php echo linkTo('admin/worker_setup.php'); ?>">فتح</a></div>
    <div class="qa"><h5>البيانات التلقائية</h5><a class="btn sm" href="<?php echo linkTo('admin/autodata.php'); ?>">فتح</a></div>
    <div class="qa"><h5>OpenAPI (الواجهة)</h5>
      <a class="btn sm" target="_blank" rel="noopener" href="<?php echo linkTo('docs/openapi.html'); ?>">عرض</a>
      <a class="btn sm" target="_blank" rel="noopener" href="<?php echo linkTo('docs/openapi.yaml'); ?>">YAML</a>
    </div>
  </div>
</div>

<div class="card">
  <h3>أحدث الوظائف</h3>
  <?php if(empty($recent)): ?>
    <div class="muted">لا توجد بيانات بعد.</div>
  <?php else: ?>
  <table data-dt="1" data-table-key="admin:dashboard:recent-jobs" data-sticky-first="1">
    <thead><tr>
      <th data-required="1">#</th>
      <th data-default="1">الحالة</th>
      <th>attempt</th>
      <th>انتهاء التأجير</th>
      <th data-default="1">الاستعلام</th>
      <th>LL</th>
      <th>النتائج</th>
      <th data-default="1">أنشئت</th>
    </tr></thead>
    <tbody>
      <?php foreach($recent as $r): ?>
      <tr>
        <td class="kbd"><?php echo (int)$r['id']; ?></td>
        <td><span class="badge"><?php echo htmlspecialchars($r['status']); ?></span></td>
        <td class="kbd" title="attempt_id"><?php echo htmlspecialchars($r['attempt_id'] ?: '—'); ?></td>
        <td class="muted"><?php echo htmlspecialchars($r['lease_expires_at'] ?: '—'); ?></td>
        <td><?php echo htmlspecialchars($r['query']); ?></td>
        <td class="kbd"><?php echo htmlspecialchars($r['ll']); ?></td>
        <td><?php echo (int)($r['result_count']??0); ?></td>
        <td class="muted"><?php echo htmlspecialchars($r['created_at']); ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/../layout_footer.php'; ?>
