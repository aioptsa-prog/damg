<?php include __DIR__ . '/../layout_header.php'; $u=require_role('agent'); $pdo=db();
$rows=$pdo->prepare("SELECT wl.id,wl.http_code,wl.response,wl.created_at, l.phone, l.name FROM washeej_logs wl LEFT JOIN leads l ON l.id=wl.lead_id WHERE wl.agent_id=? OR wl.sent_by_user_id=? ORDER BY wl.id DESC LIMIT 300");
$rows->execute([$u['id'],$u['id']]); $rows=$rows->fetchAll();
?>
<div class="card">
  <h2>سجل الإرسال (وشـيج) — خاص بالمندوب</h2>
  <table data-dt="1" data-table-key="agent:logs:washeej" data-sticky-first="1">
    <thead><tr>
      <th data-required="1">#</th>
      <th data-default="1">الهاتف</th>
      <th>الاسم</th>
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
        <td><span class="badge"><?php echo intval($r['http_code']); ?></span></td>
        <td class="muted" style="max-width:420px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?php echo htmlspecialchars($r['response']); ?></td>
        <td class="muted"><?php echo $r['created_at']; ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php include __DIR__ . '/../layout_footer.php'; ?>
