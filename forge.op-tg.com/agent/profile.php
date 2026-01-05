<?php include __DIR__ . '/../layout_header.php'; $u=require_role('agent'); $pdo=db(); $msg=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!csrf_verify($_POST['csrf'] ?? '')){ $msg='CSRF فشل التحقق'; $_POST=[]; }
  $tpl=trim($_POST['whatsapp_message'] ?? ''); $tok=trim($_POST['washeej_token'] ?? ''); $snd=trim($_POST['washeej_sender'] ?? '');
  $stmt=$pdo->prepare("UPDATE users SET whatsapp_message=?, washeej_token=?, washeej_sender=? WHERE id=?");
  $stmt->execute([$tpl,$tok,$snd,$u['id']]); $msg='تم الحفظ';
}
$me=$pdo->prepare("SELECT mobile,name,whatsapp_message,washeej_token,washeej_sender FROM users WHERE id=?"); $me->execute([$u['id']]); $me=$me->fetch();
?>
<div class="card">
  <h2>الملف الشخصي</h2>
  <?php if($msg): ?><p class="badge"><?php echo htmlspecialchars($msg); ?></p><?php endif; ?>
  <form method="post" class="grid-3">
    <?php echo csrf_input(); ?>
    <div style="grid-column:1/-1"><label>قالب رسالة واتساب (خاص بي)</label><input name="whatsapp_message" value="<?php echo htmlspecialchars($me['whatsapp_message'] ?? ''); ?>" placeholder="مثال: مرحبًا {name}"></div>
    <div><label>توكن وشـيج (اختياري)</label><input name="washeej_token" value="<?php echo htmlspecialchars($me['washeej_token'] ?? ''); ?>"></div>
    <div><label>رقم المُرسِل</label><input name="washeej_sender" value="<?php echo htmlspecialchars($me['washeej_sender'] ?? ''); ?>" placeholder="9665XXXXXXXX"></div>
    <div style="grid-column:1/-1"><button class="btn primary">حفظ</button></div>
  </form>
</div>
<?php include __DIR__ . '/../layout_footer.php'; ?>
