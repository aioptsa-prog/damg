<?php include __DIR__ . '/../layout_header.php'; $u=require_role('admin'); $pdo=db();
try{ $rows = $pdo->query("SELECT a.id,a.action,a.target,a.payload,a.created_at,u.email AS user_email FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 500")->fetchAll(); }catch(Throwable $e){ $rows=[]; }
?>
<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
    <h2 style="margin:0">سجل التدقيق (آخر 500)</h2>
  </div>
  <?php if(!$rows): ?>
    <div class="empty"><p>لا توجد سجلات.</p></div>
  <?php else: ?>
    <table data-dt="1" data-table-key="admin:audit" data-sticky-first="1">
      <thead><tr>
        <th>#</th><th>الوقت</th><th>المستخدم</th><th>الإجراء</th><th>الهدف</th><th>البيانات</th>
      </tr></thead>
      <tbody>
        <?php foreach($rows as $r): ?>
          <tr>
            <td class="kbd"><?php echo (int)$r['id']; ?></td>
            <td class="muted"><?php echo htmlspecialchars((string)$r['created_at']); ?></td>
            <td><?php echo htmlspecialchars((string)($r['user_email']??'')); ?></td>
            <td><span class="kbd"><?php echo htmlspecialchars((string)$r['action']); ?></span></td>
            <td><?php echo htmlspecialchars((string)$r['target']); ?></td>
            <td><pre class="code-block" style="max-width:420px;white-space:pre-wrap;word-break:break-word"><?php echo htmlspecialchars((string)$r['payload']); ?></pre></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/../layout_footer.php'; ?>