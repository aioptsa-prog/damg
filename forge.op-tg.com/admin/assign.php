<?php include __DIR__ . '/../layout_header.php'; $u=require_role('admin'); $pdo=db();
$msg=null;
$agents=$pdo->query("SELECT id,name FROM users WHERE role='agent' AND active=1 ORDER BY name")->fetchAll();
$pool=$pdo->query("SELECT l.id,l.phone,l.name,l.city,l.country,l.created_at FROM leads l LEFT JOIN assignments a ON a.lead_id=l.id WHERE a.id IS NULL ORDER BY l.id DESC")->fetchAll();
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!csrf_verify($_POST['csrf'] ?? '')){ $msg='CSRF فشل التحقق'; $_POST=[]; }
  $agent_id=(int)($_POST['agent_id']??0); $mode=$_POST['mode']??'selected'; $count=max(0,intval($_POST['count']??0));
  $to_assign=[]; if($mode==='selected'){ $to_assign = array_map('intval', $_POST['lead_ids'] ?? []); }
  elseif($mode==='all'){ $to_assign = array_map(fn($r)=>$r['id'], $pool); }
  elseif($mode==='count'){ $to_assign = array_map(fn($r)=>$r['id'], array_slice($pool,0,$count)); }
  $stmt=$pdo->prepare("INSERT INTO assignments(lead_id,agent_id,status,assigned_at) VALUES(?,?,?,datetime('now'))");
  $assigned=0; foreach($to_assign as $lid){ try{ $stmt->execute([$lid,$agent_id,'new']); $assigned++; }catch(Throwable $e){} }
  $msg="تم إسناد {$assigned} رقم"; $pool=$pdo->query("SELECT l.id,l.phone,l.name,l.city,l.country,l.created_at FROM leads l LEFT JOIN assignments a ON a.lead_id=l.id WHERE a.id IS NULL ORDER BY l.id DESC")->fetchAll();
}
?>
<div class="card">
  <h2>توزيع من المخزون إلى المندوب</h2>
  <?php if($msg): ?><p class="badge"><?php echo htmlspecialchars($msg); ?></p><?php endif; ?>
  <form method="post">
    <?php echo csrf_input(); ?>
    <div class="grid-3">
      <div><label>اختيار المندوب</label><select name="agent_id" required><?php foreach($agents as $a): ?><option value="<?php echo $a['id']; ?>"><?php echo htmlspecialchars($a['name']); ?></option><?php endforeach; ?></select></div>
      <div><label>وضع الإسناد</label><select name="mode"><option value="selected">المحدد</option><option value="all">إسناد الكل</option><option value="count">إسناد بعدد</option></select></div>
      <div><label>العدد (لو اخترت بالعدد)</label><input type="number" name="count" min="1"></div>
      <div style="grid-column:1/-1"><button class="btn blue">تنفيذ الإسناد</button></div>
    </div>
    <div style="margin:8px 0; display:flex; gap:8px; align-items:center">
      <button type="button" class="btn" data-select-none title="إلغاء تحديد الكل">إلغاء الكل</button>
      <button type="button" class="btn" data-select-invert title="عكس التحديد">عكس التحديد</button>
      <span class="muted">المحدد: <span class="kbd" data-selected-count>0</span></span>
    </div>
  <table data-dt="1" data-table-key="admin:assign:pool" data-sticky-first="1">
      <thead><tr>
        <th data-required="1"></th>
        <th data-required="1">#</th>
        <th data-default="1">الاسم</th>
        <th data-default="1">الهاتف</th>
        <th>المدينة</th>
        <th>الدولة</th>
        <th data-default="1">تاريخ الجلب</th>
      </tr></thead>
      <tbody>
        <?php foreach($pool as $r): ?>
          <tr>
            <td><input type="checkbox" name="lead_ids[]" value="<?php echo $r['id']; ?>"></td>
            <td class="kbd"><?php echo $r['id']; ?></td>
            <td><?php echo htmlspecialchars($r['name']); ?></td>
            <td><?php echo htmlspecialchars($r['phone']); ?></td>
            <td><?php echo htmlspecialchars($r['city']); ?></td>
            <td><?php echo htmlspecialchars($r['country']); ?></td>
            <td class="muted"><?php echo $r['created_at']; ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </form>
</div>
<?php include __DIR__ . '/../layout_footer.php'; ?>
