<?php
require_once __DIR__ . '/../layout_header.php';
$u = require_role('admin');
$pdo = db();

$can = !empty($u['is_superadmin']);
$info = ['can'=>$can];
$msg = '';
if($_SERVER['REQUEST_METHOD']==='POST' && $can && csrf_check_post()){
  try{
    // Revert worker_update_channel to previous and reset canary percent
    $prevCh = get_setting('latest_previous_channel','');
    if($prevCh!==''){
      $cur = get_setting('worker_update_channel','stable');
      set_setting('worker_update_channel', $prevCh);
      set_setting('rollout_canary_percent','0');
      audit_log($u['id'],'rollback_executed',$prevCh,json_encode(['from'=>$cur], JSON_UNESCAPED_UNICODE));
      // Alert event
      $st = $pdo->prepare("INSERT INTO alert_events(kind,message,payload,created_at) VALUES(?,?,?,datetime('now'))");
      $st->execute(['ROLLBACK_EXECUTED','Channel rollback executed', json_encode(['from'=>$cur,'to'=>$prevCh], JSON_UNESCAPED_UNICODE)]);
      $msg = 'تم تنفيذ الرجوع للخلف.';
    } else {
      $msg = 'لا توجد قناة سابقة مسجّلة.';
    }
  }catch(Throwable $e){ $msg = 'خطأ: '.$e->getMessage(); }
}
?>
<div class="card">
  <h2>رجوع فوري (Rollback)</h2>
  <?php if(!$can){ ?><div class="warn">يتطلب سوبر أدمن.</div><?php } ?>
  <div class="muted">يعيد القناة إلى الإعداد السابق ويوقف أي كاناري.</div>
  <form method="post">
    <?php csrf_input(); ?>
    <button class="btn danger" <?php echo $can?'':'disabled'; ?>>تنفيذ الرجوع فورًا</button>
  </form>
  <?php if($msg){ ?><div class="mt-2"><?php echo htmlspecialchars($msg); ?></div><?php } ?>
</div>
<?php require_once __DIR__ . '/../layout_footer.php'; ?>
