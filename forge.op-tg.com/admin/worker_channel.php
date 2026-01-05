<?php include __DIR__ . '/../layout_header.php'; $u=require_role('admin'); require_once __DIR__.'/../lib/csrf.php';
$wid = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
if($wid===''){ echo '<div class="card"><div class="empty"><p>معرّف عامل مفقود.</p></div></div>'; include __DIR__.'/../layout_footer.php'; exit; }
$pdo = db(); $msg=''; $err='';
if($_SERVER['REQUEST_METHOD']==='POST' && csrf_verify($_POST['csrf'] ?? '')){
  $choice = isset($_POST['channel']) ? trim((string)$_POST['channel']) : '';
  try{
    $raw = get_setting('worker_channel_overrides_json','{}'); $map = json_decode($raw,true); if(!is_array($map)) $map=[];
    if($choice==='inherit' || $choice==='') { unset($map[$wid]); }
    elseif(in_array($choice, ['stable','canary','beta'], true)) { $map[$wid] = $choice; }
    set_setting('worker_channel_overrides_json', json_encode($map, JSON_UNESCAPED_UNICODE));
    try{ $pdo->prepare("INSERT INTO audit_logs(user_id,action,target,payload,created_at) VALUES(?,?,?,?,datetime('now'))")
      ->execute([$u['id'],'worker_channel_set','worker:'.$wid,json_encode(['channel'=>$choice])]); }catch(Throwable $e){}
    $msg='تم الحفظ.';
  }catch(Throwable $e){ $err='فشل الحفظ: '.$e->getMessage(); }
}
$global = (string)get_setting('worker_update_channel','stable');
$mapRaw = get_setting('worker_channel_overrides_json','{}');
$map = json_decode($mapRaw, true); if(!is_array($map)) $map=[];
$cur = $map[$wid] ?? 'inherit';
?>
<div class="card">
  <h2>قناة تحديث العامل</h2>
  <p class="muted">العامل: <span class="kbd"><?php echo htmlspecialchars($wid); ?></span>
    <button class="btn xs outline" type="button" title="نسخ المعرف" onclick="navigator.clipboard && navigator.clipboard.writeText('<?php echo htmlspecialchars($wid, ENT_QUOTES); ?>').then(()=>{try{toast('تم النسخ');}catch(e){}}).catch(()=>{})">نسخ</button>
  </p>
  <?php if($msg): ?><div class="alert success"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
  <?php if($err): ?><div class="alert"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
  <form method="post" class="form">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
    <label>الخيار:
      <select name="channel">
        <option value="inherit" <?php echo $cur==='inherit'?'selected':''; ?>>وراثة الإعداد العام (<?php echo htmlspecialchars($global); ?>)</option>
        <option value="stable" <?php echo $cur==='stable'?'selected':''; ?>>Stable</option>
        <option value="canary" <?php echo $cur==='canary'?'selected':''; ?>>Canary</option>
        <option value="beta" <?php echo $cur==='beta'?'selected':''; ?>>Beta</option>
      </select>
    </label>
    <div class="row" style="justify-content:flex-end;gap:8px">
      <a class="btn" href="<?php echo linkTo('admin/workers.php'); ?>">رجوع</a>
      <button class="btn primary" type="submit">حفظ</button>
    </div>
  </form>
</div>
<?php include __DIR__ . '/../layout_footer.php'; ?>