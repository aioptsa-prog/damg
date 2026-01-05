<?php include __DIR__ . '/../layout_header.php'; $u=require_role('admin'); $pdo=db();
$id = isset($_GET['id'])? (int)$_GET['id'] : 0; $dl = isset($_GET['download']);
$st = $pdo->prepare("SELECT worker_id, info, last_seen FROM internal_workers WHERE id=?"); $st->execute([$id]); $row=$st->fetch();
if(!$row){ echo '<div class="card"><div class="empty"><p>العامل غير موجود.</p></div></div>'; include __DIR__ . '/../layout_footer.php'; exit; }
$json = (string)($row['info']??''); if($json===''){ $json='{}'; }
if($dl){ header('Content-Type: application/json'); header('Content-Disposition: attachment; filename="worker_'.$id.'.json"'); echo $json; exit; }
?>
<div class="card">
  <h2>تفاصيل العامل</h2>
  <p class="muted">Worker ID: <span class="kbd"><?php echo htmlspecialchars((string)$row['worker_id']); ?></span> — آخر ظهور: <span class="kbd"><?php echo htmlspecialchars((string)$row['last_seen']); ?></span></p>
  <div class="row" style="justify-content:flex-end">
    <a class="btn xs outline" href="<?php echo linkTo('admin/worker_info.php'); ?>?id=<?php echo intval($id); ?>&download=1">تنزيل JSON</a>
  </div>
  <pre class="code-block" style="max-height:70vh;overflow:auto"><?php echo htmlspecialchars($json); ?></pre>
</div>
<?php include __DIR__ . '/../layout_footer.php'; ?>