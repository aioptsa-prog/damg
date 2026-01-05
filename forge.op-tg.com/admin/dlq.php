<?php include __DIR__ . '/../layout_header.php'; $u=require_role('admin'); require_once __DIR__.'/../lib/csrf.php'; $pdo=db();
$msg=''; $err='';
if($_SERVER['REQUEST_METHOD']==='POST' && csrf_verify($_POST['csrf'] ?? '')){
  $action = $_POST['action'] ?? '';
  $id = (int)($_POST['id'] ?? 0);
  if($action==='retry' && $id>0){
    try{
      $st=$pdo->prepare("SELECT job_id FROM dead_letter_jobs WHERE id=?"); $st->execute([$id]); $row=$st->fetch();
      if($row){
        $jobId=(int)$row['job_id'];
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM dead_letter_jobs WHERE id=?")->execute([$id]);
        $pdo->prepare("UPDATE internal_jobs SET status='queued', next_retry_at=NULL, last_error=NULL, attempts=0, updated_at=datetime('now'), lease_expires_at=NULL WHERE id=?")
            ->execute([$jobId]);
        $pdo->commit();
        $pdo->prepare("INSERT INTO audit_logs(user_id,action,target,payload,created_at) VALUES(?,?,?,?,datetime('now'))")
            ->execute([$u['id'],'dlq_retry','job:'.$jobId,json_encode(['dlq_id'=>$id])]);
        $msg='تمت إعادة الجدولة.';
      }
    }catch(Throwable $e){ $pdo->rollBack(); $err='فشل إعادة الجدولة: '.$e->getMessage(); }
  } elseif($action==='delete' && $id>0){
    try{
      $st=$pdo->prepare("SELECT job_id FROM dead_letter_jobs WHERE id=?"); $st->execute([$id]); $row=$st->fetch();
      $pdo->prepare("DELETE FROM dead_letter_jobs WHERE id=?")->execute([$id]);
      $pdo->prepare("INSERT INTO audit_logs(user_id,action,target,payload,created_at) VALUES(?,?,?,?,datetime('now'))")
          ->execute([$u['id'],'dlq_delete','job:'.(int)($row['job_id']??0),json_encode(['dlq_id'=>$id])]);
      $msg='تم الحذف.';
    }catch(Throwable $e){ $err='فشل الحذف: '.$e->getMessage(); }
  }
}
$rows = $pdo->query("SELECT id,job_id,worker_id,reason,substr(payload,1,2000) payload,created_at FROM dead_letter_jobs ORDER BY created_at DESC LIMIT 500")->fetchAll();
?>
<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
    <h2 style="margin:0">قائمة الرسائل الميتة (DLQ)</h2>
  </div>
  <?php if($msg): ?><div class="alert success"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
  <?php if($err): ?><div class="alert"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
  <?php if(!$rows): ?>
    <div class="empty"><p>لا توجد عناصر في DLQ.</p></div>
  <?php else: ?>
  <table data-dt="1" data-table-key="admin:dlq" data-sticky-first="1">
      <thead><tr>
        <th>#</th><th>Job</th><th>Worker</th><th>Reason</th><th>Payload</th><th>Created</th><th>Actions</th>
      </tr></thead>
      <tbody>
        <?php foreach($rows as $r): ?>
          <tr>
            <td class="kbd"><?php echo (int)$r['id']; ?></td>
            <td class="kbd"><?php echo (int)$r['job_id']; ?></td>
            <td><?php echo htmlspecialchars((string)($r['worker_id']??'')); ?></td>
            <td><?php echo htmlspecialchars((string)($r['reason']??'')); ?></td>
            <td><pre class="code-block" style="max-width:360px;white-space:pre-wrap;word-break:break-word"><?php echo htmlspecialchars((string)($r['payload']??'')); ?></pre></td>
            <td class="muted"><?php echo htmlspecialchars((string)$r['created_at']); ?></td>
            <td>
              <form method="post" style="display:inline">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                <button class="btn xs" name="action" value="retry" type="submit">إعادة جدولة</button>
              </form>
              <form method="post" style="display:inline" onsubmit="return confirm('حذف نهائي؟');">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
                <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
                <button class="btn xs danger" name="action" value="delete" type="submit">حذف</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/../layout_footer.php'; ?>