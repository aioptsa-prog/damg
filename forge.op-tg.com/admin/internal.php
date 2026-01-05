<?php include __DIR__ . '/../layout_header.php'; $u=require_role('admin'); $pdo=db();
// Global controls: pause/resume all workers & bulk actions
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])){
  $action = $_POST['action'];
  if(in_array($action, ['pause_all','resume_all','kill_processing','requeue_processing','cancel_all_queued','purge_all_jobs','delete_all_done'], true)){
    if(!csrf_verify($_POST['csrf'] ?? '')){ echo '<div class="alert">CSRF فشل التحقق</div>'; } else {
    if($action==='pause_all'){
      set_setting('system_global_stop','1');
      echo '<div class="alert">تم إيقاف جميع الوحدات مؤقتًا (Global Stop)</div>';
    } elseif($action==='resume_all'){
      set_setting('system_global_stop','0');
      echo '<div class="alert success">تم تشغيل جميع الوحدات (إلغاء الإيقاف العام)</div>';
    } elseif($action==='kill_processing'){
      // Mark all currently processing jobs as done=killed safely
      $st=$pdo->prepare("UPDATE internal_jobs SET status='done', done_reason='killed', finished_at=datetime('now'), updated_at=datetime('now'), lease_expires_at=NULL WHERE status='processing'");
      $st->execute();
      echo '<div class="alert">تم "قتل" جميع المهام الجارية دون إعادة تشغيلها</div>';
    } elseif($action==='requeue_processing'){
      // Requeue all processing jobs (regardless of lease) — safe rollback
      $st=$pdo->prepare("UPDATE internal_jobs SET status='queued', worker_id=NULL, lease_expires_at=NULL, updated_at=datetime('now') WHERE status='processing'");
      $st->execute();
      echo '<div class="alert info">تمت إعادة صف جميع المهام الجارية</div>';
    } elseif($action==='cancel_all_queued'){
      $st=$pdo->prepare("UPDATE internal_jobs SET status='done', done_reason='cancelled', finished_at=datetime('now'), updated_at=datetime('now'), lease_expires_at=NULL WHERE status='queued'");
      $st->execute();
      echo '<div class="alert">تم إلغاء جميع المهام في حالة الانتظار (Queued)</div>';
    } elseif($action==='purge_all_jobs'){
      // Emergency: delete ALL jobs (dangerous)
      $pdo->exec("DELETE FROM internal_jobs");
      echo '<div class="alert">تم حذف جميع المهام نهائيًا (Purged)</div>';
    } elseif($action==='delete_all_done'){
      // Cleanup: delete all done/failed jobs only
      $pdo->exec("DELETE FROM internal_jobs WHERE status IN ('done','failed')");
      echo '<div class="alert info">تم حذف جميع المهام المنتهية/الفاشلة</div>';
    }
    }
  }
}
// Actions: force requeue
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='requeue'){
  if(!csrf_verify($_POST['csrf'] ?? '')){ echo '<div class="alert">CSRF فشل التحقق</div>'; }
  else {
    $id=(int)($_POST['id']??0);
    if($id>0){
      $st=$pdo->prepare("UPDATE internal_jobs SET status='queued', worker_id=NULL, lease_expires_at=NULL, updated_at=datetime('now') WHERE id=?");
      $st->execute([$id]);
      echo '<div class="alert success">تمت إعادة صف المهمة #'.intval($id).'</div>';
    }
  }
}
// Actions: extend lease
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='extend_lease'){
  if(!csrf_verify($_POST['csrf'] ?? '')){ echo '<div class="alert">CSRF فشل التحقق</div>'; }
  else {
    $id=(int)($_POST['id']??0); $mins=max(1,(int)($_POST['mins']??3));
    if($id>0){
      $st=$pdo->prepare("UPDATE internal_jobs SET lease_expires_at=datetime('now', ?), updated_at=datetime('now') WHERE id=? AND status!='done'");
      $st->execute(['+'.$mins.' minutes',$id]);
      echo '<div class="alert info">تم تمديد الحجز للمهمة #'.intval($id).' '.$mins.' دقيقة</div>';
    }
  }
}
// Actions: mark done
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='mark_done'){
  if(!csrf_verify($_POST['csrf'] ?? '')){ echo '<div class="alert">CSRF فشل التحقق</div>'; }
  else {
    $id=(int)($_POST['id']??0);
    if($id>0){
      $st=$pdo->prepare("UPDATE internal_jobs SET status='done', finished_at=datetime('now'), updated_at=datetime('now'), lease_expires_at=NULL WHERE id=?");
      $st->execute([$id]);
      echo '<div class="alert success">تم إنهاء المهمة #'.intval($id).'</div>';
    }
  }
}
// Actions: cancel job
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='cancel_job'){
  if(!csrf_verify($_POST['csrf'] ?? '')){ echo '<div class="alert">CSRF فشل التحقق</div>'; }
  else {
    $id=(int)($_POST['id']??0);
    if($id>0){
      $st=$pdo->prepare("UPDATE internal_jobs SET status='done', done_reason='cancelled', finished_at=datetime('now'), updated_at=datetime('now'), lease_expires_at=NULL WHERE id=?");
      $st->execute([$id]);
      echo '<div class="alert">تم إلغاء المهمة #'.intval($id).'</div>';
    }
  }
}
// Actions: requeue all expired processing
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='requeue_expired'){
  if(!csrf_verify($_POST['csrf'] ?? '')){ echo '<div class="alert">CSRF فشل التحقق</div>'; }
  else {
    $st=$pdo->prepare("UPDATE internal_jobs SET status='queued', worker_id=NULL, lease_expires_at=NULL, updated_at=datetime('now') WHERE status='processing' AND (lease_expires_at IS NULL OR lease_expires_at < datetime('now'))");
    $st->execute();
    echo '<div class="alert">تمت إعادة صف جميع المهام ذات الحجز المنتهي</div>';
  }
}
// Filter
$status = trim($_GET['status'] ?? '');
$q = trim($_GET['q'] ?? '');
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$w = [];
if($status!==''){ $w[] = "j.status=".$pdo->quote($status); }
if($q!==''){ $qq = '%'.str_replace(['%','_'], ['\%','\_'], $q).'%'; $w[] = "(j.query LIKE ".$pdo->quote($qq)." ESCAPE '\\' OR j.ll LIKE ".$pdo->quote($qq)." ESCAPE '\\')"; }
if($from!=='' && preg_match('/^\d{4}-\d{2}-\d{2}/',$from)){ $w[] = "j.created_at >= ".$pdo->quote($from.' 00:00:00'); }
if($to!=='' && preg_match('/^\d{4}-\d{2}-\d{2}/',$to)){ $w[] = "j.created_at <= ".$pdo->quote($to.' 23:59:59'); }
$where = $w ? ('WHERE '.implode(' AND ',$w)) : '';
$rows=$pdo->query("SELECT j.*, u.name as requested_by FROM internal_jobs j LEFT JOIN users u ON u.id=j.requested_by_user_id $where ORDER BY j.id DESC LIMIT 500")->fetchAll();
// Small counters for quick triage
$_cnt = $pdo->query("SELECT status, COUNT(*) c FROM internal_jobs GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
$cnt = ['queued'=>0,'processing'=>0,'done'=>0];
foreach($_cnt as $c){ if(isset($cnt[$c['status']])) $cnt[$c['status']] = (int)$c['c']; }
$expired_processing = (int)$pdo->query("SELECT COUNT(*) FROM internal_jobs WHERE status='processing' AND (lease_expires_at IS NULL OR lease_expires_at < datetime('now'))")->fetchColumn();
?>
<div class="card">
  <h2>الوظائف الداخلية (Internal Server Queue)</h2>
  <?php $globStopped = get_setting('system_global_stop','0')==='1'; ?>
  <div class="row" style="gap:8px;flex-wrap:wrap;margin-bottom:6px">
  <span class="badge warn" title="Queued">منتظرة: <?php echo number_format($cnt['queued']); ?></span>
  <span class="badge" title="Processing">جارية: <?php echo number_format($cnt['processing']); ?></span>
  <span class="badge danger" title="Expired leases in processing">منتهية الحجز: <?php echo number_format($expired_processing); ?></span>
  <span class="badge success" title="Done">منتهية: <?php echo number_format($cnt['done']); ?></span>
    <span class="muted">| المعروض الآن: <?php echo count($rows); ?> عنصر</span>
  </div>
  <div class="row" style="gap:10px;align-items:center;flex-wrap:wrap">
    <form method="post" onsubmit="return confirm('تأكيد الإيقاف العام لكل الوحدات؟');">
      <?php echo csrf_input(); ?>
      <input type="hidden" name="action" value="pause_all" />
      <button class="btn danger" <?php echo $globStopped? 'disabled' : ''; ?>>إيقاف كل الوحدات مؤقتًا</button>
    </form>
    <form method="post" onsubmit="return confirm('تشغيل جميع الوحدات وإلغاء الإيقاف؟');">
      <?php echo csrf_input(); ?>
      <input type="hidden" name="action" value="resume_all" />
      <button class="btn success" <?php echo $globStopped? '' : 'disabled'; ?>>تشغيل الكل الآن</button>
    </form>
    <span class="badge" style="background:<?php echo $globStopped?'#7f1d1d':'#14532d'; ?>">الحالة: <?php echo $globStopped? 'مُتوقّف (Global Stop)':'يعمل'; ?></span>
  </div>
  <div class="row" style="gap:10px;align-items:center;flex-wrap:wrap;margin-top:8px">
    <form method="post" onsubmit="return confirm('سيتم إنهاء كل المهام الجارية فورًا. متابعة؟');">
      <?php echo csrf_input(); ?>
      <input type="hidden" name="action" value="kill_processing" />
      <button class="btn">قتل جميع المهام الجارية</button>
    </form>
    <form method="post" onsubmit="return confirm('سيتم إعادة جميع المهام الجارية إلى صف الانتظار. متابعة؟');">
      <?php echo csrf_input(); ?>
      <input type="hidden" name="action" value="requeue_processing" />
      <button class="btn warning">Requeue جميع الجارية</button>
    </form>
    <form method="post" onsubmit="return confirm('سيتم إلغاء جميع المهام المنتظرة (Queued). متابعة؟');">
      <?php echo csrf_input(); ?>
      <input type="hidden" name="action" value="cancel_all_queued" />
      <button class="btn">إلغاء جميع المنتظرة</button>
    </form>
    <form method="post" onsubmit="return confirm('تحذير: سيتم حذف جميع المهام نهائيًا (لا يمكن التراجع). هل أنت متأكد؟');">
      <?php echo csrf_input(); ?>
      <input type="hidden" name="action" value="purge_all_jobs" />
      <button class="btn danger">حذف كل المهام (طارئ)</button>
    </form>
    <form method="post" onsubmit="return confirm('سيتم حذف المهام المنتهية/الفاشلة فقط. متابعة؟');">
      <?php echo csrf_input(); ?>
      <input type="hidden" name="action" value="delete_all_done" />
      <button class="btn">حذف المنتهية/الفاشلة</button>
    </form>
  </div>
  <form method="post" class="row gap">
    <?php echo csrf_input(); ?>
    <input type="hidden" name="action" value="requeue_expired" />
    <button class="btn warning">Requeue expired (processing)</button>
  </form>
  <form method="get" class="row gap" style="flex-wrap:wrap" data-persist>
    <label>الحالة
      <select name="status">
        <option value="">الكل</option>
        <?php foreach(['queued','processing','done','failed'] as $s): $sel=$status===$s?'selected':''; ?>
          <option value="<?php echo $s; ?>" <?php echo $sel; ?>><?php echo $s; ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>بحث
      <input name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="الاستعلام أو LL">
    </label>
    <label>من
      <input type="date" name="from" value="<?php echo htmlspecialchars($from); ?>">
    </label>
    <label>إلى
      <input type="date" name="to" value="<?php echo htmlspecialchars($to); ?>">
    </label>
    <button class="btn">تصفية</button>
    <?php if($status||$q||$from||$to): ?>
      <a class="btn outline" href="<?php echo linkTo('admin/internal.php'); ?>">إعادة ضبط</a>
    <?php endif; ?>
    <button class="btn outline" type="button" data-persist-reset title="مسح التفضيلات">مسح</button>
    <?php
      // build export URL with same filters
      $qs = [];
      if($status!=='') $qs['status']=$status;
      if($q!=='') $qs['q']=$q;
      if($from!=='') $qs['from']=$from;
      if($to!=='') $qs['to']=$to;
      $exportUrl = linkTo('admin/internal_export.php') . ($qs? ('?'.http_build_query($qs)) : '');
    ?>
    <a class="btn" href="<?php echo $exportUrl; ?>">تصدير CSV</a>
  </form>
  <table data-dt="1" data-table-key="admin:internal-jobs" data-sticky-first="1">
    <thead><tr>
      <th data-required="1">#</th>
      <th data-default="1">الحالة</th>
      <th>السبب</th>
      <th>المستخدم</th>
      <th>الدور</th>
      <th>للمندوب</th>
      <th data-default="1">الاستعلام</th>
      <th>الهدف</th>
      <th>LL</th>
      <th>نصف القطر</th>
      <th>وركر</th>
      <th data-default="1">تقدم</th>
      <th>lease</th>
      <th>محاولات</th>
      <th>النتائج</th>
      <th data-default="1">أنشئت</th>
      <th>آخر تقدم</th>
      <th>انتهت</th>
      <th data-required="1">إجراءات</th>
    </tr></thead>
    <tbody>
      <?php foreach($rows as $r): ?>
      <tr>
        <td class="kbd"><?php echo $r['id']; ?></td>
        <td><span class="badge" style="background:<?php echo $r['status']==='done'?'#0b3a1a':($r['status']==='processing'?'#2a2a57':'#5b3a0b'); ?>"><?php echo htmlspecialchars($r['status']); ?></span></td>
  <td>
    <?php $dr=$r['done_reason'] ?: '—'; $color = ($dr==='target_reached')?'#134e4a':(($dr==='no_more_results')?'#3f0a0a':'#334155'); ?>
    <span class="badge" style="background:<?php echo $color; ?>"><?php echo htmlspecialchars($dr); ?></span>
  </td>
  <td><?php echo htmlspecialchars($r['requested_by']); ?></td>
        <td><?php echo htmlspecialchars($r['role']); ?></td>
        <td><?php echo $r['agent_id'] ?: '—'; ?></td>
        <td><?php echo htmlspecialchars($r['query']); ?></td>
  <td><?php echo $r['target_count'] ? intval($r['target_count']) : '—'; ?></td>
        <td class="kbd"><?php echo htmlspecialchars($r['ll']); ?></td>
        <td><?php echo intval($r['radius_km']); ?></td>
        <td><?php echo htmlspecialchars($r['worker_id'] ?: ''); ?></td>
        <td class="kbd">
          <?php $tgt = (int)($r['target_count'] ?? 0); $added=(int)$r['result_count']; $pct = ($tgt>0)? min(100, intval(($added*100)/$tgt)) : null; ?>
          <?php if($pct!==null): ?>
            <div style="min-width:160px">
              <div style="height:8px;background:var(--panel-3);border-radius:4px;overflow:hidden">
                <div style="height:8px;width:<?php echo $pct; ?>%;background:var(--accent)"></div>
              </div>
              <div style="font-size:12px;margin-top:2px"><?php echo $pct; ?>% • <?php echo $added; ?>/<?php echo $tgt; ?> (cursor=<?php echo intval($r['last_cursor']); ?> • partials=<?php echo intval($r['progress_count']); ?>)</div>
            </div>
          <?php else: ?>
            cursor=<?php echo intval($r['last_cursor']); ?> • added=<?php echo intval($added); ?> • partials=<?php echo intval($r['progress_count']); ?>
          <?php endif; ?>
        </td>
        <td class="muted">
          <?php if(!$r['lease_expires_at']): ?>—
          <?php else: ?>
            <?php echo htmlspecialchars($r['lease_expires_at']); ?>
            <?php if($r['status']==='processing' && strtotime($r['lease_expires_at']) < time()): ?>
              <span class="badge danger">expired</span>
            <?php endif; ?>
          <?php endif; ?>
        </td>
        <td><?php echo intval($r['attempts']); ?></td>
        <td><?php echo intval($r['result_count']); ?></td>
        <td class="muted"><?php echo htmlspecialchars($r['created_at']); ?></td>
        <td class="muted"><?php echo htmlspecialchars($r['last_progress_at'] ?: '—'); ?></td>
        <td class="muted"><?php echo htmlspecialchars($r['finished_at'] ?: '—'); ?></td>
        <td>
          <?php if($r['status']!=='done'): ?>
          <?php $leaseExpired = ($r['status']==='processing' && $r['lease_expires_at'] && strtotime($r['lease_expires_at']) < time()); ?>
          <div class="row" style="gap:4px;align-items:center;flex-wrap:wrap">
            <?php if($leaseExpired || $r['status']==='queued'): ?>
              <form method="post" onsubmit="return confirm('تأكيد إعادة الصف؟');" style="display:inline">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="action" value="requeue" />
                <input type="hidden" name="id" value="<?php echo intval($r['id']); ?>" />
                <button class="btn small">Requeue</button>
              </form>
            <?php endif; ?>
            <form method="post" class="row" style="gap:4px;align-items:center" onsubmit="return confirm('تمديد الحجز؟');">
              <?php echo csrf_input(); ?>
              <input type="hidden" name="action" value="extend_lease" />
              <input type="hidden" name="id" value="<?php echo intval($r['id']); ?>" />
              <input type="number" min="1" name="mins" value="3" class="input small" style="width:58px" />
              <button class="btn small" title="تمديد الحجز بالدقائق">+دقائق</button>
            </form>
            <form method="post" onsubmit="return confirm('تأكيد إنهاء المهمة يدوياً؟');" style="display:inline">
              <?php echo csrf_input(); ?>
              <input type="hidden" name="action" value="mark_done" />
              <input type="hidden" name="id" value="<?php echo intval($r['id']); ?>" />
              <button class="btn small success">إنهاء</button>
            </form>
            <form method="post" onsubmit="return confirm('تأكيد إلغاء المهمة؟');" style="display:inline">
              <?php echo csrf_input(); ?>
              <input type="hidden" name="action" value="cancel_job" />
              <input type="hidden" name="id" value="<?php echo intval($r['id']); ?>" />
              <button class="btn small danger">إلغاء</button>
            </form>
          </div>
          <?php else: ?>—<?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php include __DIR__ . '/../layout_footer.php'; ?>
