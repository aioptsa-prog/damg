<?php
include __DIR__ . '/../layout_header.php'; $u=require_role('admin'); require_once __DIR__.'/../lib/csrf.php';
$pdo = db();
$wid = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
if($wid===''){ echo '<div class="card"><div class="empty"><p>معرّف عامل مفقود.</p></div></div>'; include __DIR__.'/../layout_footer.php'; exit; }

$msg = '';$err='';
// Load current info row (best-effort)
$st = $pdo->prepare("SELECT * FROM internal_workers WHERE worker_id=? LIMIT 1"); $st->execute([$wid]); $row = $st->fetch();
// Settings blobs
$nameMapRaw = get_setting('worker_name_overrides_json','{}'); $nameMap = json_decode($nameMapRaw, true); if(!is_array($nameMap)) $nameMap = [];
$cfgOvRaw  = get_setting('worker_config_overrides_json','{}'); $cfgMap = json_decode($cfgOvRaw, true); if(!is_array($cfgMap)) $cfgMap = [];
$cmdMapRaw = get_setting('worker_commands_json','{}'); $cmdMap = json_decode($cmdMapRaw, true); if(!is_array($cmdMap)) $cmdMap = [];
$curName = isset($nameMap[$wid]) ? (string)$nameMap[$wid] : '';
$curOv   = isset($cfgMap[$wid]) && is_array($cfgMap[$wid]) ? $cfgMap[$wid] : [];

function saveMaps($nameMap,$cfgMap,$cmdMap){
  set_setting('worker_name_overrides_json', json_encode($nameMap, JSON_UNESCAPED_UNICODE));
  set_setting('worker_config_overrides_json', json_encode($cfgMap, JSON_UNESCAPED_UNICODE));
  set_setting('worker_commands_json', json_encode($cmdMap, JSON_UNESCAPED_UNICODE));
}

if($_SERVER['REQUEST_METHOD']==='POST' && csrf_verify($_POST['csrf'] ?? '')){
  $action = $_POST['action'] ?? '';
  try{
    if($action==='save_name'){
      $newName = trim((string)($_POST['display_name'] ?? ''));
      if($newName===''){ unset($nameMap[$wid]); } else { $nameMap[$wid] = $newName; }
      saveMaps($nameMap,$cfgMap,$cmdMap);
      $msg = 'تم حفظ الاسم.';
    } elseif($action==='save_cfg'){
      // Collect and sanitize overrides
      $ov = [];
      $num = function($v){ return (int)$v; };
      $bool = function($v){ return is_bool($v) ? $v : (is_string($v)? ($v==='1'||strtolower($v)==='true'||$v==='on') : !!$v); };
      $fieldsN = ['pull_interval_sec','max_pages','lease_sec','report_batch_size','report_every_ms','report_first_ms','item_delay_ms'];
      foreach($fieldsN as $k){ if(isset($_POST[$k]) && $_POST[$k] !== ''){ $ov[$k] = $num($_POST[$k]); } }
      if(isset($_POST['headless'])){ $ov['headless'] = $bool($_POST['headless']); }
      if(isset($_POST['until_end'])){ $ov['until_end'] = $bool($_POST['until_end']); }
      if(isset($_POST['base_url']) && $_POST['base_url']!==''){ $ov['base_url'] = rtrim((string)$_POST['base_url'],'/'); }
      if(isset($_POST['chrome_exe'])){ $ov['chrome_exe'] = (string)$_POST['chrome_exe']; }
      if(isset($_POST['chrome_args'])){ $ov['chrome_args'] = (string)$_POST['chrome_args']; }
      if(isset($_POST['update_channel']) && $_POST['update_channel']!==''){ $ov['update_channel'] = (string)$_POST['update_channel']; }
      if(isset($_POST['clear']) && $_POST['clear']==='1'){ unset($cfgMap[$wid]); }
      else { $cfgMap[$wid] = $ov; }
      saveMaps($nameMap,$cfgMap,$cmdMap);
      $msg = 'تم حفظ الإعدادات.';
    } elseif(in_array($action, ['pause','resume','restart','update-now','arm','disarm','heartbeat-now','sync-config'], true)){
      $cmdMap[$wid] = [ 'command' => $action, 'rev' => time() ];
      saveMaps($nameMap,$cfgMap,$cmdMap);
      $msg = 'تم إرسال الأمر: ' . htmlspecialchars($action);
      try{ $pdo->prepare("INSERT INTO audit_logs(user_id,action,target,payload,created_at) VALUES(?,?,?,?,datetime('now'))")
        ->execute([$u['id'],'worker_command','worker:'.$wid,json_encode(['cmd'=>$action])]); }catch(Throwable $e){}
    } elseif($action==='clear_cmd'){
      unset($cmdMap[$wid]);
      saveMaps($nameMap,$cfgMap,$cmdMap);
      $msg = 'تم مسح الأمر المعلّق لهذا العامل.';
    }
  }catch(Throwable $e){ $err = 'فشل العملية: '.$e->getMessage(); }
  // Refresh current after POST
  $curName = isset($nameMap[$wid]) ? (string)$nameMap[$wid] : '';
  $curOv   = isset($cfgMap[$wid]) && is_array($cfgMap[$wid]) ? $cfgMap[$wid] : [];
}
?>
<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
    <h2 style="margin:0">إدارة عامل — <span class="kbd"><?php echo htmlspecialchars($wid); ?></span>
      <button class="btn xs outline" type="button" title="نسخ المعرف" onclick="navigator.clipboard && navigator.clipboard.writeText('<?php echo htmlspecialchars($wid, ENT_QUOTES); ?>').then(()=>{try{showToast('تم النسخ','success');}catch(e){}}).catch(()=>{})">نسخ</button>
    </h2>
    <div>
      <a class="btn sm" href="<?php echo linkTo('admin/workers.php'); ?>">رجوع</a>
  <a class="btn sm outline" target="_blank" rel="noopener" href="<?php echo linkTo('admin/worker_live.php'); ?>?id=<?php echo urlencode($wid); ?>">بث مباشر</a>
  <a class="btn sm outline" target="_blank" rel="noopener" href="<?php echo linkTo('admin/worker_channel.php'); ?>?id=<?php echo urlencode($wid); ?>">قناة التحديث</a>
    </div>
  </div>
  <?php if($msg): ?><div class="alert success"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
  <?php if($err): ?><div class="alert"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>

  <div class="grid-2">
    <div class="card">
      <h3>الاسم المعروض</h3>
      <form method="post" class="form">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="action" value="save_name">
        <label>اسم ودي (اختياري)</label>
        <input type="text" name="display_name" value="<?php echo htmlspecialchars($curName); ?>" placeholder="مثال: عامل مكتب الرياض #1">
        <div class="row" style="justify-content:flex-end;gap:8px">
          <button class="btn" type="submit">حفظ</button>
        </div>
      </form>
    </div>

    <div class="card">
      <h3>أوامر سريعة</h3>
      <form method="post" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <button class="btn sm" name="action" value="pause" type="submit">إيقاف مؤقّت</button>
        <button class="btn sm ok" name="action" value="resume" type="submit">استئناف</button>
        <button class="btn sm warn" name="action" value="restart" type="submit" onclick="return confirm('سيُعاد تشغيل العامل. المتابعة؟');">إعادة تشغيل</button>
        <button class="btn sm" name="action" value="update-now" type="submit">تحديث الآن</button>
        <button class="btn sm" name="action" value="arm" type="submit">Arm</button>
        <button class="btn sm" name="action" value="disarm" type="submit">Disarm</button>
        <button class="btn sm outline" name="action" value="heartbeat-now" type="submit">Heartbeat</button>
        <button class="btn sm outline" name="action" value="sync-config" type="submit">Sync Config</button>
        <button class="btn sm danger" name="action" value="clear_cmd" type="submit">مسح الأمر المعلّق</button>
      </form>
      <p class="muted">ملاحظة: تُرسل الأوامر عبر worker_config وتُطبق عند الاستعلام التالي. يُستخدم rev قائم على الوقت لضمان مرّة واحدة.</p>
    </div>
  </div>

  <div class="card">
    <h3>تخصيص إعدادات العامل</h3>
    <form method="post" class="form">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <input type="hidden" name="action" value="save_cfg">
      <div class="grid-3">
        <label>BASE URL
          <input type="text" name="base_url" value="<?php echo htmlspecialchars((string)($curOv['base_url'] ?? '')); ?>" placeholder="مثال: http://127.0.0.1:8080">
        </label>
        <label>Interval (sec)
          <input type="number" name="pull_interval_sec" min="1" value="<?php echo htmlspecialchars((string)($curOv['pull_interval_sec'] ?? '')); ?>">
        </label>
        <label>Max Pages
          <input type="number" name="max_pages" min="1" value="<?php echo htmlspecialchars((string)($curOv['max_pages'] ?? '')); ?>">
        </label>
        <label>Lease (sec)
          <input type="number" name="lease_sec" min="30" value="<?php echo htmlspecialchars((string)($curOv['lease_sec'] ?? '')); ?>">
        </label>
        <label>Report batch size
          <input type="number" name="report_batch_size" min="1" value="<?php echo htmlspecialchars((string)($curOv['report_batch_size'] ?? '')); ?>">
        </label>
        <label>Report every (ms)
          <input type="number" name="report_every_ms" min="200" value="<?php echo htmlspecialchars((string)($curOv['report_every_ms'] ?? '')); ?>">
        </label>
        <label>Report first (ms)
          <input type="number" name="report_first_ms" min="100" value="<?php echo htmlspecialchars((string)($curOv['report_first_ms'] ?? '')); ?>">
        </label>
        <label>Item delay (ms)
          <input type="number" name="item_delay_ms" min="0" value="<?php echo htmlspecialchars((string)($curOv['item_delay_ms'] ?? '')); ?>">
        </label>
        <label>Headless
          <select name="headless">
            <option value="">—</option>
            <option value="1" <?php echo (isset($curOv['headless']) && $curOv['headless']) ? 'selected' : ''; ?>>نعم</option>
            <option value="0" <?php echo (isset($curOv['headless']) && !$curOv['headless']) ? 'selected' : ''; ?>>لا</option>
          </select>
        </label>
        <label>Scroll until end
          <select name="until_end">
            <option value="">—</option>
            <option value="1" <?php echo (isset($curOv['until_end']) && $curOv['until_end']) ? 'selected' : ''; ?>>مفعّل</option>
            <option value="0" <?php echo (isset($curOv['until_end']) && !$curOv['until_end']) ? 'selected' : ''; ?>>غير مفعّل</option>
          </select>
        </label>
        <label>Chrome EXE
          <input type="text" name="chrome_exe" value="<?php echo htmlspecialchars((string)($curOv['chrome_exe'] ?? '')); ?>">
        </label>
        <label>Chrome args
          <input type="text" name="chrome_args" value="<?php echo htmlspecialchars((string)($curOv['chrome_args'] ?? '')); ?>">
        </label>
        <label>Update channel
          <select name="update_channel">
            <option value="">—</option>
            <option value="stable" <?php echo (isset($curOv['update_channel']) && $curOv['update_channel']==='stable')? 'selected':''; ?>>Stable</option>
            <option value="canary" <?php echo (isset($curOv['update_channel']) && $curOv['update_channel']==='canary')? 'selected':''; ?>>Canary</option>
            <option value="beta" <?php echo (isset($curOv['update_channel']) && $curOv['update_channel']==='beta')? 'selected':''; ?>>Beta</option>
            <option value="dev" <?php echo (isset($curOv['update_channel']) && $curOv['update_channel']==='dev')? 'selected':''; ?>>Dev</option>
          </select>
        </label>
      </div>
      <div class="row" style="justify-content:space-between;gap:8px">
        <label style="display:flex;align-items:center;gap:6px"><input type="checkbox" name="clear" value="1"> مسح التخصيص لهذا العامل</label>
        <div>
          <button class="btn" type="submit">حفظ التخصيص</button>
        </div>
      </div>
      <p class="muted">اترك الحقول فارغة لوراثة الإعداد العام. استخدم "مسح التخصيص" للعودة للإعدادات العامة.</p>
    </form>
  </div>
</div>
<?php include __DIR__ . '/../layout_footer.php'; ?>
