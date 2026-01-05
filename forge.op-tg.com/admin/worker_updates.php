<?php include __DIR__ . '/../layout_header.php'; $u=require_role('admin'); require_once __DIR__.'/../lib/csrf.php';
$root = dirname(__DIR__);
$topReleases = $root . DIRECTORY_SEPARATOR . 'releases';
if(!is_dir($topReleases)) { @mkdir($topReleases, 0777, true); }
$channel = isset($_GET['channel']) ? strtolower(trim((string)$_GET['channel'])) : 'stable';
if(!in_array($channel, ['stable','canary','beta'], true)) $channel = 'stable';
$logFile = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'update-admin.log';
@mkdir(dirname($logFile), 0777, true);
function uaudit($msg){ global $logFile; @file_put_contents($logFile, '['.date('c').'] '.$msg."\n", FILE_APPEND); }

function readLatest($ch){ global $topReleases; $f1 = $topReleases . '/latest_' . $ch . '.json'; $f2 = $topReleases . '/latest.json'; $p = is_file($f1)? $f1 : (is_file($f2)? $f2 : null); if(!$p) return null; $j = json_decode((string)@file_get_contents($p), true); return is_array($j)? $j : null; }
$current = readLatest($channel);
$stable  = readLatest('stable');
$canary  = readLatest('canary');

$msg = '';$err='';
if($_SERVER['REQUEST_METHOD']==='POST' && csrf_verify($_POST['csrf'] ?? '')){
  $action = $_POST['action'] ?? '';
  if($action==='upload'){
    try{
      if(empty($_FILES['artifact']) || ($_FILES['artifact']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK){ throw new Exception('الملف غير مرفوع'); }
      $ver = trim((string)($_POST['version'] ?? '')); if($ver===''){ throw new Exception('رقم الإصدار مطلوب'); }
      $up = $_FILES['artifact']; $name = (string)($up['name'] ?? '');
      $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION)); if(!in_array($ext, ['exe','zip'], true)) throw new Exception('نوع الملف غير مدعوم');
      $kind = ($ext==='zip') ? 'portable' : 'exe';
      $safeName = preg_replace('/[^A-Za-z0-9_\-\.]+/','_', basename($name));
      $targetName = ($kind==='exe' ? 'OptForgeWorker_Setup_v'.$ver.'.exe' : 'OptForgeWorker_Portable_v'.$ver.'.zip');
      $dest = $topReleases . DIRECTORY_SEPARATOR . $targetName;
      if(!@move_uploaded_file($up['tmp_name'], $dest)){
        // Fallback to copy
        if(!@copy($up['tmp_name'], $dest)) throw new Exception('فشل حفظ الملف');
      }
      @chmod($dest, 0644);
      clearstatcache(true, $dest);
      $size = (int)@filesize($dest); if($size<=0) throw new Exception('حجم الملف غير صالح');
      $sha = @hash_file('sha256', $dest) ?: '';
      // latest_{channel}.json
      $meta = [ 'version'=>$ver, 'url'=>'/releases/'.$targetName, 'sha256'=>$sha, 'size'=>$size, 'channel'=>$channel, 'kind'=>$kind, 'mtime'=>gmdate('c') ];
      @file_put_contents($topReleases . '/latest_' . $channel . '.json', json_encode($meta, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
      if($channel==='stable'){
        @file_put_contents($topReleases . '/latest.json', json_encode($meta, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        @file_put_contents($topReleases . '/installer_meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
      }
      $current = $meta;
      uaudit('upload channel='.$channel.' file='.$targetName.' ver='.$ver.' size='.$size);
      $msg = 'تم الرفع بنجاح وتحديث قناة '.$channel;
    }catch(Throwable $e){ $err = $e->getMessage(); }
  } elseif($action==='promote' && $channel==='canary'){
    try{
      if(!$canary) throw new Exception('لا توجد نسخة Canary');
      @file_put_contents($topReleases . '/latest_stable.json', json_encode($canary, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
      @file_put_contents($topReleases . '/latest.json', json_encode($canary, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
      @file_put_contents($topReleases . '/installer_meta.json', json_encode($canary, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
      $stable = $canary; $msg = 'تمت ترقية Canary إلى Stable'; uaudit('promote canary->stable ver='.(string)($canary['version']??''));
    }catch(Throwable $e){ $err = $e->getMessage(); }
  }
}
?>
<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap">
    <h2 style="margin:0">تحديثات العامل — قنوات الإصدار</h2>
    <div>
      <a class="btn sm <?php echo $channel==='stable'?'':'outline'; ?>" href="?channel=stable">Stable</a>
      <a class="btn sm <?php echo $channel==='canary'?'':'outline'; ?>" href="?channel=canary">Canary</a>
      <a class="btn sm <?php echo $channel==='beta'?'':'outline'; ?>" href="?channel=beta">Beta</a>
    </div>
  </div>
  <?php if($msg): ?><div class="alert success"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
  <?php if($err): ?><div class="alert">
    <?php echo htmlspecialchars($err); ?></div><?php endif; ?>

  <div class="row" style="gap:16px;align-items:stretch">
    <div class="col">
      <h3>الحالي (<?php echo htmlspecialchars($channel); ?>)</h3>
      <?php if(!$current): ?>
        <div class="empty"><p>لا توجد نسخة حالية.</p></div>
      <?php else: ?>
        <table>
          <tr><th>Version</th><td><?php echo htmlspecialchars((string)($current['version']??'')); ?></td></tr>
          <tr><th>Kind</th><td><?php echo htmlspecialchars((string)($current['kind']??'')); ?></td></tr>
          <tr><th>Size</th><td><?php echo number_format((int)($current['size']??0)); ?> bytes</td></tr>
          <tr><th>SHA256</th><td><code><?php echo htmlspecialchars((string)($current['sha256']??'')); ?></code></td></tr>
          <tr><th>URL</th><td><a href="<?php echo htmlspecialchars((string)($current['url']??'')); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars((string)($current['url']??'')); ?></a></td></tr>
        </table>
      <?php endif; ?>
    </div>
    <div class="col">
      <h3>رفع نسخة جديدة</h3>
      <form method="post" enctype="multipart/form-data" class="form">
        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="action" value="upload">
        <div class="row">
          <label>الإصدار</label>
          <input type="text" name="version" placeholder="مثال: 1.5.2" required>
        </div>
        <div class="row">
          <label>الملف (EXE أو ZIP)</label>
          <input type="file" name="artifact" accept=".exe,.zip" required>
        </div>
        <div class="row">
          <button class="btn" type="submit">رفع وتحديث قناة <?php echo htmlspecialchars($channel); ?></button>
        </div>
      </form>
      <?php if($channel==='canary' && $canary): ?>
        <form method="post" class="mt-2">
          <input type="hidden" name="csrf" value="<?php echo htmlspecialchars(csrf_token()); ?>">
          <input type="hidden" name="action" value="promote">
          <button class="btn ok" type="submit" onclick="return confirm('ترقية النسخة الحالية من Canary إلى Stable؟');">ترقية Canary → Stable</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../layout_footer.php'; ?>