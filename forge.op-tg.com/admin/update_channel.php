<?php
require_once __DIR__ . '/../layout_header.php';
$u = require_role('admin');
$pdo = db();

// Superadmin or admin allowed; actions: set rollout percent, promote channel, set overrides
$action = $_POST['action'] ?? '';
$tokenOk = csrf_check_post();
if($action && $tokenOk){
  try{
    if($action === 'set_rollout'){
      $p = (int)max(0,min(100,(int)($_POST['percent']??0)));
      set_setting('rollout_canary_percent', (string)$p);
      audit_log($u['id'], 'rollout_set', 'canary', json_encode(['percent'=>$p], JSON_UNESCAPED_UNICODE));
    }elseif($action === 'promote_channel'){
      $ch = trim((string)($_POST['channel']??'stable'));
      if(!in_array($ch,['stable','canary','beta'],true)) $ch='stable';
      // Update default worker channel
  $prevCh = get_setting('worker_update_channel','stable');
  set_setting('latest_previous_channel', $prevCh);
  set_setting('worker_update_channel', $ch);
      audit_log($u['id'], 'channel_promote', $ch, json_encode(['prev'=>$prevCh,'new'=>$ch], JSON_UNESCAPED_UNICODE));
    }elseif($action === 'override_worker'){
      $wid = trim((string)($_POST['worker_id']??''));
      $ch = trim((string)($_POST['channel']??''));
      $raw = get_setting('worker_channel_overrides_json','{}');
      $map = json_decode($raw,true); if(!is_array($map)) $map=[];
      if($wid!=='' && in_array($ch,['stable','canary','beta'],true)){
        $map[$wid] = $ch;
        set_setting('worker_channel_overrides_json', json_encode($map, JSON_UNESCAPED_UNICODE));
        audit_log($u['id'], 'channel_override_set', $wid, json_encode(['channel'=>$ch], JSON_UNESCAPED_UNICODE));
      }
    }elseif($action === 'clear_override'){
      $wid = trim((string)($_POST['worker_id']??''));
      $raw = get_setting('worker_channel_overrides_json','{}');
      $map = json_decode($raw,true); if(!is_array($map)) $map=[];
      unset($map[$wid]);
      set_setting('worker_channel_overrides_json', json_encode($map, JSON_UNESCAPED_UNICODE));
      audit_log($u['id'], 'channel_override_clear', $wid, '');
    }
  }catch(Throwable $e){ /* ignore */ }
}

$roll = (int)get_setting('rollout_canary_percent','0');
$defaultCh = get_setting('worker_update_channel','stable');
$overrides = json_decode(get_setting('worker_channel_overrides_json','{}'), true) ?: [];
?>
<div class="card">
  <h2>قنوات التحديث والكاناري</h2>
  <form method="post">
    <?php csrf_input(); ?>
    <div class="row">
      <label>القناة الافتراضية</label>
      <select name="channel">
        <option value="stable" <?php echo $defaultCh==='stable'?'selected':''; ?>>stable</option>
        <option value="canary" <?php echo $defaultCh==='canary'?'selected':''; ?>>canary</option>
        <option value="beta" <?php echo $defaultCh==='beta'?'selected':''; ?>>beta</option>
        <option value="dev" <?php echo $defaultCh==='dev'?'selected':''; ?>>dev</option>
      </select>
      <button class="btn" name="action" value="promote_channel">تعيين القناة</button>
    </div>
    <div class="row mt-2">
      <label>نسبة الكاناري (٪)</label>
      <input type="number" name="percent" min="0" max="100" value="<?php echo (int)$roll; ?>" />
      <button class="btn" name="action" value="set_rollout">حفظ النسبة</button>
      <div class="muted">السياسة: hash(worker_id) mod 100 &lt; percent ⇒ canary.</div>
    </div>
  </form>
</div>

<div class="card">
  <h3>تجاوزات حسب العامل</h3>
  <form method="post">
    <?php csrf_input(); ?>
    <div class="row">
      <input type="text" name="worker_id" placeholder="Worker ID" />
      <select name="channel">
        <option value="stable">stable</option>
        <option value="canary">canary</option>
        <option value="beta">beta</option>
        <option value="dev">dev</option>
      </select>
      <button class="btn" name="action" value="override_worker">تعيين</button>
      <button class="btn warn" name="action" value="clear_override">حذف التجاوز</button>
    </div>
  </form>
  <?php if(!empty($overrides)){ ?>
  <table>
    <thead><tr><th>Worker</th><th>Channel</th></tr></thead>
    <tbody>
      <?php foreach($overrides as $wk=>$ch){ ?>
        <tr><td class="kbd"><?php echo htmlspecialchars($wk); ?></td><td><?php echo htmlspecialchars($ch); ?></td></tr>
      <?php } ?>
    </tbody>
  </table>
  <?php } else { ?><div class="muted">لا توجد تجاوزات.</div><?php } ?>
</div>
<?php require_once __DIR__ . '/../layout_footer.php'; ?>
