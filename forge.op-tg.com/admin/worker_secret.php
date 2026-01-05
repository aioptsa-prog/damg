<?php include __DIR__ . '/../layout_header.php'; $u=require_role('admin'); $pdo=db(); require_once __DIR__.'/../lib/csrf.php';
$wid = isset($_GET['id']) ? trim((string)$_GET['id']) : '';
if($wid===''){ echo '<div class="alert">Missing worker id</div>'; include __DIR__ . '/../layout_footer.php'; exit; }
$st = $pdo->prepare("SELECT * FROM internal_workers WHERE worker_id=? LIMIT 1"); $st->execute([$wid]); $row = $st->fetch();
if(!$row){ echo '<div class="alert">Worker not found</div>'; include __DIR__ . '/../layout_footer.php'; exit; }
$newSecretShown = null;
if($_SERVER['REQUEST_METHOD']==='POST' && csrf_verify($_POST['csrf'] ?? '')){
  $action = $_POST['action'] ?? '';
  if($action==='generate' || $action==='rotate'){
    $sec = bin2hex(random_bytes(32));
    if($action==='generate'){
      $pdo->prepare("UPDATE internal_workers SET secret=?, rotated_at=datetime('now'), rotating_to=NULL WHERE worker_id=?")
          ->execute([$sec, $wid]);
    } else {
      // staged rotation: set rotating_to and keep current secret valid until promotion
      $pdo->prepare("UPDATE internal_workers SET rotating_to=?, rotated_at=datetime('now') WHERE worker_id=?")
          ->execute([$sec, $wid]);
    }
    $newSecretShown = $sec; // show once; stored in DB as plaintext like internal_secret (improve later)
    $st->execute([$wid]); $row = $st->fetch();
  } elseif($action==='promote'){
    // Promote rotating_to -> secret
    $pdo->prepare("UPDATE internal_workers SET secret=rotating_to, rotating_to=NULL, rotated_at=datetime('now') WHERE worker_id=?")
        ->execute([$wid]);
    $st->execute([$wid]); $row = $st->fetch();
  } elseif($action==='clear'){
    $pdo->prepare("UPDATE internal_workers SET secret=NULL, rotating_to=NULL WHERE worker_id=?")
        ->execute([$wid]);
    $st->execute([$wid]); $row = $st->fetch();
  }
}
?>
<div class="card">
  <h2 style="margin:0">سر العامل — <?php echo htmlspecialchars($wid); ?></h2>
  <?php if($newSecretShown): ?>
    <div class="alert warn">أدناه السر الجديد — انسخه ووزّعه على الجهاز المعني. لأسباب أمنية قد لا يُعرض لاحقًا.</div>
    <div class="row" style="align-items:center;gap:8px">
      <div class="kbd" style="word-break:break-all">&lrm;<?php echo htmlspecialchars($newSecretShown); ?></div>
      <button type="button" class="btn xs outline" data-copy data-copy-text="<?php echo htmlspecialchars($newSecretShown, ENT_QUOTES); ?>">نسخ</button>
    </div>
  <?php endif; ?>
  <div class="mt-2">
    <form method="post" style="display:flex;gap:8px;align-items:center">
      <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
      <button class="btn" name="action" value="generate" type="submit">توليد سر جديد</button>
      <button class="btn warn" name="action" value="rotate" type="submit">بدء تدوير (rotating_to)</button>
      <button class="btn ok" name="action" value="promote" type="submit">ترقية السر (تفعيل الجديد)</button>
      <button class="btn danger" name="action" value="clear" type="submit" onclick="return confirm('مسح السر سيعيد العامل للاعتماد على السر العام/HMAC. متابعة؟');">مسح السر</button>
    </form>
  </div>
  <div class="mt-2">
    <table>
      <tr><th>secret</th><td>
        <?php $secVal = (string)($row['secret'] ?? ''); ?>
        <code><?php echo htmlspecialchars($secVal); ?></code>
        <?php if($secVal!==''): ?><button type="button" class="btn xs outline" data-copy data-copy-text="<?php echo htmlspecialchars($secVal, ENT_QUOTES); ?>" title="نسخ">نسخ</button><?php endif; ?>
      </td></tr>
      <tr><th>rotating_to</th><td>
        <?php $rotVal = (string)($row['rotating_to'] ?? ''); ?>
        <code><?php echo htmlspecialchars($rotVal); ?></code>
        <?php if($rotVal!==''): ?><button type="button" class="btn xs outline" data-copy data-copy-text="<?php echo htmlspecialchars($rotVal, ENT_QUOTES); ?>" title="نسخ">نسخ</button><?php endif; ?>
      </td></tr>
      <tr><th>rotated_at</th><td><?php echo htmlspecialchars((string)($row['rotated_at'] ?? '')); ?></td></tr>
      <tr><th>rate_limit_per_min</th><td><?php echo htmlspecialchars((string)($row['rate_limit_per_min'] ?? '')); ?></td></tr>
    </table>
    <p class="muted">التطبيق الحالي يقبل الحماية عبر: HMAC (السر العام)، X-Internal-Secret (قديم)، وX-Worker-Secret (خاص بالعامل). أثناء التدوير، يُقبل rotating_to مؤقتًا.</p>
  </div>
</div>
<?php include __DIR__ . '/../layout_footer.php'; ?>