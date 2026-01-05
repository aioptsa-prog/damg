<?php include __DIR__ . '/../layout_header.php'; $u=require_role('admin'); $pdo=db(); $msg=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!csrf_verify($_POST['csrf'] ?? '')){ $msg='CSRF فشل التحقق'; $_POST=[]; }
  if(isset($_POST['create'])){
    $mobile=trim($_POST['mobile']); $name=trim($_POST['name']); $role=$_POST['role']; $pass=$_POST['password'];
    $ph = password_hash($pass, PASSWORD_DEFAULT);
    $stmt=$pdo->prepare("INSERT INTO users(mobile,name,role,password_hash,washeej_token,washeej_sender,whatsapp_message,created_at) VALUES(?,?,?,?,?,?,?,datetime('now'))");
    try{ $stmt->execute([$mobile,$name,$role,$ph,trim($_POST['washeej_token']??''),trim($_POST['washeej_sender']??''),trim($_POST['whatsapp_message']??'')]); $msg='تم إنشاء المستخدم'; } catch(Throwable $e){ $msg='خطأ: رقم الجوال مكرر؟'; }
  }
  if(isset($_POST['toggle'])){ $id=(int)$_POST['id']; $pdo->exec("UPDATE users SET active = CASE active WHEN 1 THEN 0 ELSE 1 END WHERE id=$id"); $msg='تم تغيير حالة المستخدم'; }
  if(isset($_POST['save_token'])){ $id=(int)$_POST['id']; $tok=trim($_POST['washeej_token']??''); $snd=trim($_POST['washeej_sender']??''); $tpl=trim($_POST['whatsapp_message']??''); $stmt=$pdo->prepare("UPDATE users SET washeej_token=?, washeej_sender=?, whatsapp_message=? WHERE id=?"); $stmt->execute([$tok,$snd,$tpl,$id]); $msg='تم حفظ بيانات المستخدم'; }
  if(isset($_POST['reset_pass'])){ $id=(int)$_POST['id']; $ph=password_hash($_POST['new_password'], PASSWORD_DEFAULT); $stmt=$pdo->prepare("UPDATE users SET password_hash=? WHERE id=?"); $stmt->execute([$ph,$id]); $msg='تم تغيير كلمة المرور'; }
}
$rows=$pdo->query("SELECT id,mobile,name,role,active,washeej_token,washeej_sender,whatsapp_message,created_at FROM users ORDER BY id DESC")->fetchAll();
$openModal = ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['create']));
?>
<div class="card">
  <div class="page-head"><h2>إدارة المستخدمين</h2><button id="openAddUser" class="btn primary sm">+ إضافة مستخدم</button></div>
  <?php if($msg): ?><p class="badge"><?php echo htmlspecialchars($msg); ?></p><?php endif; ?>
</div>

<!-- Modal: إضافة مستخدم -->
<div class="modal-backdrop" id="addUserModal">
  <div class="modal">
    <div class="modal-header">
      <h3>إضافة مستخدم</h3>
      <button class="modal-close" data-close title="إغلاق">✕</button>
    </div>
    <form method="post" class="grid-3">
      <?php echo csrf_input(); ?>
      <input type="hidden" name="create" value="1">
      <div><label>الاسم</label><input name="name" required value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"></div>
      <div><label>رقم الجوال</label><input name="mobile" required value="<?php echo htmlspecialchars($_POST['mobile'] ?? ''); ?>"></div>
      <div><label>الدور</label>
        <select name="role">
          <?php $rSel = $_POST['role'] ?? 'agent'; ?>
          <option value="agent" <?php echo $rSel==='agent'? 'selected':''; ?>>مندوب</option>
          <option value="admin" <?php echo $rSel==='admin'? 'selected':''; ?>>مدير</option>
        </select>
      </div>
      <div><label>كلمة المرور</label><input name="password" type="password" required></div>
      <div style="grid-column:1/-1"><label>قالب رسالة واتساب (اختياري)</label><input name="whatsapp_message" placeholder="مثال: مرحبًا {name}" value="<?php echo htmlspecialchars($_POST['whatsapp_message'] ?? ''); ?>"></div>
      <div><label>مفتاح وشـيج</label><input name="washeej_token" value="<?php echo htmlspecialchars($_POST['washeej_token'] ?? ''); ?>"></div>
      <div><label>رقم المُرسِل</label><input name="washeej_sender" placeholder="9665XXXXXXXX" value="<?php echo htmlspecialchars($_POST['washeej_sender'] ?? ''); ?>"></div>
      <div style="grid-column:1/-1;display:flex;gap:8px;justify-content:flex-end">
        <button type="button" class="btn outline" data-close>إلغاء</button>
        <button class="btn primary">إنشاء مستخدم</button>
      </div>
    </form>
  </div>
  <script nonce="<?php echo htmlspecialchars(csp_nonce()); ?>">
    (function(){
      const modal = document.getElementById('addUserModal');
      const openBtn = document.getElementById('openAddUser');
      function open(){ modal.classList.add('show'); }
      function close(){ modal.classList.remove('show'); }
      if(openBtn) openBtn.addEventListener('click', open);
      modal.addEventListener('click', (e)=>{ if(e.target===modal) close(); });
      modal.querySelectorAll('[data-close]').forEach(el=> el.addEventListener('click', close));
      <?php if($openModal): ?> open(); <?php endif; ?>
    })();
  </script>
</div>
<div class="card">
  <h3>قائمة المستخدمين</h3>
  <?php if(!$rows): ?>
    <div class="empty mt-3">
      <i class="fa-solid fa-users"></i>
      <h3>لا يوجد مستخدمون بعد</h3>
      <p>استخدم زر إضافة مستخدم في الأعلى لإنشاء أول مستخدم.</p>
    </div>
  <?php else: ?>
  <table data-dt="1" data-table-key="admin:users" data-sticky-first="1">
    <thead><tr>
      <th data-required="1">#</th>
      <th data-default="1">الاسم</th>
      <th data-default="1">الجوال</th>
      <th>الدور</th>
      <th>الحالة</th>
      <th>واتساب/وشـيج</th>
      <th data-required="1">تحكم</th>
    </tr></thead>
    <tbody>
      <?php foreach($rows as $r): ?>
      <tr>
        <td class="kbd"><?php echo $r['id']; ?></td>
        <td><?php echo htmlspecialchars($r['name']); ?></td>
        <td><?php echo htmlspecialchars($r['mobile']); ?></td>
        <td><span class="badge"><?php echo $r['role']; ?></span></td>
        <td><?php echo $r['active']?'نشط':'موقوف'; ?></td>
        <td>
          <form method="post" class="grid-3">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
            <input name="whatsapp_message" value="<?php echo htmlspecialchars($r['whatsapp_message'] ?? ''); ?>" placeholder="قالب واتساب">
            <input name="washeej_token" value="<?php echo htmlspecialchars($r['washeej_token'] ?? ''); ?>" placeholder="توكن">
            <input name="washeej_sender" value="<?php echo htmlspecialchars($r['washeej_sender'] ?? ''); ?>" placeholder="مرسل">
            <button class="btn" name="save_token">حفظ</button>
          </form>
        </td>
        <td>
          <form method="post" style="display:inline-block;margin-left:6px;display:flex;align-items:center;gap:8px">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
            <label style="margin:0"><input class="ui-switch" type="checkbox" name="toggle" onchange="this.form.submit()" <?php echo $r['active']?'checked':''; ?>></label>
            <span class="muted"><?php echo $r['active']?'نشط':'موقوف'; ?></span>
          </form>
          <form method="post" class="grid-2" style="display:inline-block;margin-left:6px">
            <?php echo csrf_input(); ?>
            <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
            <input name="new_password" placeholder="كلمة جديدة">
            <button class="btn" name="reset_pass">تغيير كلمة المرور</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/../layout_footer.php'; ?>
