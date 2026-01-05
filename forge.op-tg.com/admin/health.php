<?php include __DIR__ . '/../layout_header.php'; $u=require_role('admin'); $pdo=db();
// Action: requeue expired processing
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='requeue_expired'){
  if(csrf_verify($_POST['csrf'] ?? '')){
    $st=$pdo->prepare("UPDATE internal_jobs SET status='queued', worker_id=NULL, lease_expires_at=NULL, updated_at=datetime('now') WHERE status='processing' AND (lease_expires_at IS NULL OR lease_expires_at < datetime('now'))");
    $st->execute();
    echo '<div class="alert">تمت إعادة صف جميع المهام ذات الحجز المنتهي</div>';
  } else {
    echo '<div class="alert">فشل CSRF</div>';
  }
}

// Action: centralized worker command (pause/resume/restart/update-now/heartbeat-now)
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='worker_cmd'){
  if(csrf_verify($_POST['csrf'] ?? '')){
    $cmd = trim((string)($_POST['cmd'] ?? ''));
    $allowed = ['pause','resume','restart','update-now','heartbeat-now','arm','disarm'];
    if(!in_array($cmd, $allowed, true)){
      echo '<div class="alert">أمر غير مسموح</div>';
    } else {
      try{
        $rev = (int)get_setting('worker_command_rev','0');
        set_setting('worker_command', $cmd);
        set_setting('worker_command_rev', (string)($rev+1));
        echo '<div class="alert success">تم إرسال الأمر للوحدات: '.htmlspecialchars($cmd).'</div>';
      }catch(Throwable $e){ echo '<div class="alert">فشل إرسال الأمر: '.htmlspecialchars($e->getMessage()).'</div>'; }
    }
  } else {
    echo '<div class="alert">فشل CSRF</div>';
  }
}

// Metrics
$now = date('Y-m-d H:i:s');
$twoMinAgo = date('Y-m-d H:i:s', time()-120);
$workersOnline = workers_online_count(false);
$workersTotal = (int)$pdo->query("SELECT COUNT(*) c FROM internal_workers")->fetch()['c'];
$jobsQueued = (int)$pdo->query("SELECT COUNT(*) c FROM internal_jobs WHERE status='queued'")->fetch()['c'];
$jobsProcessing = (int)$pdo->query("SELECT COUNT(*) c FROM internal_jobs WHERE status='processing'")->fetch()['c'];
$jobsExpired = (int)$pdo->query("SELECT COUNT(*) c FROM internal_jobs WHERE status='processing' AND (lease_expires_at IS NULL OR lease_expires_at < datetime('now'))")->fetch()['c'];
$jobsDone24h = (int)$pdo->query("SELECT COUNT(*) c FROM internal_jobs WHERE status='done' AND finished_at >= datetime('now','-1 day')")->fetch()['c'];

$isStopped = system_is_globally_stopped();
$isPause = system_is_in_pause_window();
$pickOrder = get_setting('job_pick_order','fifo');

// Taxonomy counts (diagnostics)
$catsCount = (int)$pdo->query("SELECT COUNT(*) c FROM categories")->fetch()['c'];
$kwCount = (int)$pdo->query("SELECT COUNT(*) c FROM category_keywords")->fetch()['c'];
$rulesCount = (int)$pdo->query("SELECT COUNT(*) c FROM category_rules")->fetch()['c'];

// Load last 20 selection reasons (if any)
$selLines = [];
$selFile = __DIR__ . '/../storage/logs/selection.log';
if(is_file($selFile)){
  $all = @file($selFile, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) ?: [];
  $selLines = array_slice(array_reverse($all), 0, 20);
}
$workers = $pdo->query("SELECT worker_id,last_seen,host,version FROM internal_workers ORDER BY last_seen DESC LIMIT 8")->fetchAll();
?>
<div class="card">
  <h2>حالة النظام (Health)</h2>
  <div class="grid-3">
    <div class="card">
      <h3>النظام</h3>
      <div>إيقاف شامل: <span class="badge" style="background:<?php echo $isStopped?'#7f1d1d':'#0b3a1a'; ?>"><?php echo $isStopped?'موقّف':'يعمل'; ?></span></div>
      <div>فترة الإيقاف اليومية: <span class="badge" style="background:<?php echo $isPause?'#7f1d1d':'#0b3a1a'; ?>"><?php echo $isPause?'نشطة الآن':'غير نشطة'; ?></span></div>
    </div>
    <div class="card">
      <h3>الوحدات الطرفية</h3>
      <div>متصل الآن (~2m): <strong><?php echo $workersOnline; ?></strong> من إجمالي <strong><?php echo $workersTotal; ?></strong></div>
      <?php if(!empty($workers)): ?>
      <div class="mt-3">
  <table data-dt="1" data-table-key="admin:health:workers" data-sticky-first="1" style="width:100%">
          <thead><tr>
            <th data-default="1">المعرّف</th>
            <th>المضيف</th>
            <th data-default="1">الإصدار</th>
            <th data-default="1">آخر ظهور</th>
          </tr></thead>
          <tbody>
            <?php foreach($workers as $w): ?>
            <tr>
              <td class="kbd"><?php echo htmlspecialchars($w['worker_id']); ?></td>
              <td><?php echo htmlspecialchars($w['host'] ?: '—'); ?></td>
              <td><span class="badge"><?php echo htmlspecialchars($w['version'] ?: '—'); ?></span></td>
              <td class="muted"><?php echo htmlspecialchars($w['last_seen']); ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
    <div class="card">
      <h3>الوظائف الداخلية</h3>
      <div>Queued: <strong><?php echo $jobsQueued; ?></strong></div>
      <div>Processing: <strong><?php echo $jobsProcessing; ?></strong></div>
      <div>Expired (processing): <strong><?php echo $jobsExpired; ?></strong></div>
      <div>Done (24h): <strong><?php echo $jobsDone24h; ?></strong></div>
      <div>Pick order: <span class="badge"><?php echo htmlspecialchars($pickOrder); ?></span></div>
      <form method="post" style="margin-top:8px" onsubmit="return confirm('إعادة صف كل المهام منتهية الحجز؟');">
        <?php echo csrf_input(); ?>
        <input type="hidden" name="action" value="requeue_expired" />
        <button class="btn warning">Requeue expired (processing)</button>
      </form>
    </div>
    <div class="card">
      <h3>تحكم بالعامل</h3>
      <p class="muted">أرسل أمرًا مركزيًا للوحدات. سيتم استلامه عبر تهيئة العامل المركزية خلال ثوانٍ.</p>
      <form method="post">
        <?php echo csrf_input(); ?>
        <input type="hidden" name="action" value="worker_cmd" />
        <label for="cmd">الأمر:</label>
        <select id="cmd" name="cmd" required>
          <option value="pause">إيقاف مؤقت (pause)</option>
          <option value="resume">استئناف (resume)</option>
          <option value="restart">إعادة تشغيل (restart)</option>
          <option value="update-now">تحديث الآن (update-now)</option>
          <option value="heartbeat-now">نبضة الآن (heartbeat-now)</option>
          <option value="arm">تسليح (arm)</option>
          <option value="disarm">نزع التسليح (disarm)</option>
        </select>
        <button class="btn primary" type="submit" style="margin-inline-start:8px">إرسال</button>
      </form>
  <div class="muted" style="margin-top:8px">آخر أمر: <strong><?php echo htmlspecialchars(get_setting('worker_command','')); ?></strong> — الإصدار: <?php echo (int)get_setting('worker_command_rev','0'); ?><br>الوصول: تُقرأ الأوامر عبر endpoint إعداد العامل المركزي، وتصل للعامل خلال ثوانٍ؛ استخدم "نبضة الآن" للتحقق الفوري.</div>
    </div>
    <div class="card">
      <h3>تصنيف — إحصاءات</h3>
      <div>الأقسام: <strong><?php echo $catsCount; ?></strong></div>
      <div>الكلمات المفتاحية: <strong><?php echo $kwCount; ?></strong></div>
      <div>القواعد: <strong><?php echo $rulesCount; ?></strong></div>
    </div>
  </div>
  <div class="muted" style="margin-top:8px">الوقت الحالي: <?php echo htmlspecialchars($now); ?></div>
</div>

<div class="card">
  <h3>أسباب اختيار المهام (آخر 20)</h3>
  <?php if(empty($selLines)): ?>
    <div class="muted">لا توجد بيانات بعد.</div>
  <?php else: ?>
  <?php $selText = implode("\n", $selLines); ?>
  <details class="collapsible">
    <summary>عرض/إخفاء الأسباب (آخر 20)</summary>
    <div class="collapsible-body">
      <div class="row" style="justify-content:flex-end;margin-bottom:6px">
        <button type="button" class="btn small" data-copy data-copy-text="<?php echo htmlspecialchars($selText); ?>">نسخ</button>
      </div>
      <pre class="code-block"><?php echo htmlspecialchars($selText); ?></pre>
    </div>
  </details>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/../layout_footer.php'; ?>
