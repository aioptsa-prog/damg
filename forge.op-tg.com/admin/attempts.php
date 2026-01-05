<?php include __DIR__ . '/../layout_header.php'; $u=require_role('admin'); $pdo=db();
$q = trim((string)($_GET['q'] ?? '')); $succ = $_GET['success'] ?? '';
$from = trim((string)($_GET['from'] ?? '')); $to = trim((string)($_GET['to'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1)); $per=50; $off=($page-1)*$per;
$where = []; $args=[];
if($q!==''){ $where[] = '(job_id LIKE ? OR worker_id LIKE ? OR attempt_id LIKE ? OR log_excerpt LIKE ?)'; for($i=0;$i<4;$i++) $args[] = '%'.$q.'%'; }
if($succ!==''){ $where[] = 'success = ?'; $args[] = ((int)$succ)?1:0; }
if($from!==''){ $where[] = '(finished_at>=? OR (finished_at IS NULL AND started_at>=?))'; $args[]=$from; $args[]=$from; }
if($to!==''){ $where[] = '(finished_at<=? OR (finished_at IS NULL AND started_at<=?))'; $args[]=$to; $args[]=$to; }
$wsql = $where? ('WHERE '.implode(' AND ',$where)) : '';
$cntStmt = $pdo->prepare("SELECT COUNT(*) c FROM job_attempts $wsql"); $cntStmt->execute($args); $total = (int)($cntStmt->fetch()['c'] ?? 0);
$st = $pdo->prepare("SELECT * FROM job_attempts $wsql ORDER BY COALESCE(finished_at, started_at) DESC LIMIT $per OFFSET $off"); $st->execute($args); $rows=$st->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="card">
  <h2>Job Attempts</h2>
  <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:8px" data-persist>
    <input name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="بحث في job_id/worker/attempt/log...">
    <select name="success">
      <option value="" <?php echo $succ===''?'selected':''; ?>>All</option>
      <option value="1" <?php echo $succ==='1'?'selected':''; ?>>Success</option>
      <option value="0" <?php echo $succ==='0'?'selected':''; ?>>Failure</option>
    </select>
    <label>من <input type="datetime-local" name="from" value="<?php echo htmlspecialchars($from); ?>" style="min-width:220px"></label>
    <label>إلى <input type="datetime-local" name="to" value="<?php echo htmlspecialchars($to); ?>" style="min-width:220px"></label>
    <button class="btn">تصفية</button>
    <button class="btn outline" type="button" data-persist-reset title="مسح التفضيلات">مسح</button>
    <a class="btn" href="<?php echo linkTo('admin/monitor.php'); ?>">المراقبة</a>
  </form>
  <table data-dt="1" data-table-key="admin:attempts" data-sticky-first="1"><thead><tr><th>وقت</th><th>Job</th><th>Worker</th><th>Attempt</th><th>نجاح</th><th>ملاحظة</th></tr></thead><tbody>
    <?php foreach($rows as $r): ?>
      <tr>
        <td class="muted"><?php echo htmlspecialchars($r['finished_at'] ?: $r['started_at']); ?></td>
  <td class="kbd"><a target="_blank" rel="noopener" href="<?php echo linkTo('api/debug_job.php'); ?>?id=<?php echo (int)$r['job_id']; ?>">#<?php echo (int)$r['job_id']; ?></a></td>
        <td><?php echo htmlspecialchars($r['worker_id'] ?? ''); ?></td>
        <td><code><?php echo htmlspecialchars($r['attempt_id'] ?? ''); ?></code></td>
        <td><?php echo ((int)($r['success'] ?? 0)) ? '✓' : '✗'; ?></td>
        <td>
          <?php $lex = trim((string)($r['log_excerpt'] ?? '')); ?>
          <?php if($lex===''): ?>
            <span class="muted">—</span>
          <?php else: ?>
            <?php $pv = substr($lex,0,80); if(strlen($lex)>80) $pv .= '…'; ?>
            <details class="collapsible">
              <summary><span class="muted">سجل:</span> <code><?php echo htmlspecialchars($pv); ?></code></summary>
              <div class="collapsible-body">
                <div class="row" style="justify-content:flex-end;margin-bottom:6px">
                  <button type="button" class="btn small" data-copy data-copy-text="<?php echo htmlspecialchars($lex); ?>">نسخ</button>
                </div>
                <pre class="code-block"><?php echo htmlspecialchars($lex); ?></pre>
              </div>
            </details>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody></table>
  <div class="muted" style="margin-top:8px;display:flex;justify-content:space-between;align-items:center">
    <span>الإجمالي: <?php echo (int)$total; ?> — صفحة <?php echo (int)$page; ?></span>
    <span>
      <?php if($page>1): ?><a class="btn sm" href="?<?php echo http_build_query(['q'=>$q,'success'=>$succ,'from'=>$from,'to'=>$to,'page'=>$page-1]); ?>">السابق</a><?php endif; ?>
      <?php if(($off+$per) < $total): ?><a class="btn sm" href="?<?php echo http_build_query(['q'=>$q,'success'=>$succ,'from'=>$from,'to'=>$to,'page'=>$page+1]); ?>">التالي</a><?php endif; ?>
    </span>
  </div>
</div>
<?php include __DIR__ . '/../layout_footer.php'; ?>
