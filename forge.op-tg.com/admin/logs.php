<?php include __DIR__ . '/../layout_header.php'; $u=require_role('admin'); $pdo=db();
$q=trim($_GET['q']??''); $where=['1=1']; $params=[];
if($q!==''){ $where[]='(l.phone LIKE :q OR l.name LIKE :q OR u.name LIKE :q)'; $params[':q']="%$q%"; }
$sql="SELECT wl.id, wl.http_code, wl.response, wl.created_at, wl.lead_id, wl.agent_id, wl.sent_by_user_id,
             l.phone, l.name, au.name as agent_name, su.name as sender_name
      FROM washeej_logs wl
      LEFT JOIN leads l ON l.id=wl.lead_id
      LEFT JOIN users au ON au.id=wl.agent_id
      LEFT JOIN users su ON su.id=wl.sent_by_user_id
      WHERE ".implode(' AND ',$where)." ORDER BY wl.id DESC LIMIT 500";
$stmt=$pdo->prepare($sql); $stmt->execute($params); $rows=$stmt->fetchAll();
?>
<div class="card">
  <h2>سجل الإرسال (وشـيج) — المدير</h2>
  <form class="searchbar" method="get" data-persist>
    <input type="text" name="q" placeholder="بحث بالاسم/الرقم/المستخدم" value="<?php echo htmlspecialchars($q); ?>">
    <button class="btn">بحث</button>
    <button class="btn outline" type="button" data-persist-reset title="مسح التفضيلات">مسح</button>
  </form>
  <table data-dt="1" data-table-key="admin:logs:washeej" data-sticky-first="1">
    <thead><tr>
      <th data-required="1">#</th>
      <th data-default="1">الهاتف</th>
      <th>الاسم</th>
      <th>المندوب</th>
      <th>مرسل الطلب</th>
      <th data-default="1">HTTP</th>
      <th>الاستجابة</th>
      <th data-default="1">تاريخ</th>
    </tr></thead>
    <tbody>
      <?php foreach($rows as $r): ?>
      <tr>
        <td class="kbd"><?php echo $r['id']; ?></td>
        <td><?php echo htmlspecialchars($r['phone']); ?></td>
        <td><?php echo htmlspecialchars($r['name']); ?></td>
        <td><?php echo htmlspecialchars($r['agent_name'] ?? ''); ?></td>
        <td><?php echo htmlspecialchars($r['sender_name'] ?? ''); ?></td>
        <td><span class="badge"><?php echo intval($r['http_code']); ?></span></td>
        <td>
          <?php $resp = trim((string)($r['response'] ?? '')); ?>
          <?php if($resp===''): ?>
            <span class="muted">—</span>
          <?php else: ?>
            <?php $preview = substr($resp,0,80); if(strlen($resp)>80) $preview .= '…'; ?>
            <details class="collapsible">
              <summary title="عرض/إخفاء الاستجابة"><span class="muted">الاستجابة:</span> <code><?php echo htmlspecialchars($preview); ?></code></summary>
              <div class="collapsible-body">
                <div class="row" style="justify-content:flex-end;margin-bottom:6px">
                  <button type="button" class="btn small" data-copy data-copy-text="<?php echo htmlspecialchars($resp); ?>" title="نسخ النص">نسخ</button>
                </div>
                <pre class="code-block"><?php echo htmlspecialchars($resp); ?></pre>
              </div>
            </details>
          <?php endif; ?>
        </td>
        <td class="muted"><?php echo $r['created_at']; ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php include __DIR__ . '/../layout_footer.php'; ?>
