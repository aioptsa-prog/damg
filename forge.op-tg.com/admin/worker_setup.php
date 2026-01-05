<?php
require_once __DIR__ . '/../bootstrap.php';
$u=require_role('admin');
$pdo=db();

// Production-focused page: single, professional download with metadata; hides dev ZIP flows
// Defaults for worker configuration (for reference only; the installer handles first-run config)
$base_url = get_setting('worker_base_url','');
if($base_url===''){ if(function_exists('app_base_url')){ $base_url = app_base_url(); } }
if($base_url===''){ $base_url = 'https://nexus.op-tg.com'; }
$secret = get_setting('internal_secret','');
$interval = get_setting('worker_pull_interval_sec','30');

// Discover artifacts: prefer portable ZIP for manual setup, keep EXE as optional
$latest = null; $zipFile = null; $zipSizeMB = null; $zipMTime = null; $zipSha = null; $installerFile = null; $installerSizeMB = null; $installerMTime = null; $installerVer = null; $publisher = null;
$appVer = null; $zipStale = false; $zipMeta = null; $zipArchiveAvailable = class_exists('ZipArchive');
$workerRoot = realpath(__DIR__.'/../worker') ?: (__DIR__.'/../worker');
$hasEmbeddedNode = is_file($workerRoot.'/node/node.exe');
$hasNodeModules = is_dir($workerRoot.'/node_modules');
$dlZip = linkTo('api/download_worker.php?kind=zip');
$dlExe = linkTo('api/download_worker.php');
$latestPath = __DIR__.'/../releases/latest.json';
if(!is_file($latestPath)) $latestPath = __DIR__.'/../storage/releases/latest.json';
if (is_file($latestPath)){
  $latest = json_decode((string)@file_get_contents($latestPath), true);
  if (is_array($latest)){
    if (!empty($latest['url']) && preg_match('/\.zip$/i', (string)$latest['url'])){
      $abs = realpath(__DIR__.'/..'.'/'.ltrim((string)$latest['url'],'/'));
      if ($abs && is_file($abs)) { $zipFile = $abs; }
    }
    $installerVer = $latest['version'] ?? null;
    $zipSha = $latest['sha256'] ?? null;
    $zipMTime = isset($latest['last_modified']) ? date('Y-m-d H:i', strtotime($latest['last_modified'])) : null;
    if (isset($latest['size'])) { $zipSizeMB = round(((int)$latest['size'])/1048576,1); }
  }
}
if (!$zipFile){
  foreach ([__DIR__.'/../storage/releases', __DIR__.'/../releases'] as $dir){
    foreach (glob($dir.'/*Portable*.zip') ?: [] as $p){ if (is_file($p)) { $zipFile = realpath($p); $zipSizeMB = round(filesize($zipFile)/1048576,1); $zipMTime = date('Y-m-d H:i', filemtime($zipFile)); break 2; } }
  }
}
// If we have a zip file but no sha256 from metadata, compute it once
if ($zipFile && !$zipSha){
  try { $zipSha = hash_file('sha256', $zipFile); } catch (Throwable $e) { $zipSha = null; }
}
// Determine worker APP_VER and whether ZIP is stale relative to sources
try{
  $idx = __DIR__ . '/../worker/index.js';
  if (is_file($idx)){
    $src = @file_get_contents($idx);
    if (is_string($src) && preg_match("/APP_VER\s*=\s*'([^']+)'/", $src, $m)) { $appVer = trim($m[1]); }
  }
  $workerDir = realpath(__DIR__.'/../worker') ?: (__DIR__.'/../worker');
  $latestSrcM = 0; $bump = function($p) use (&$latestSrcM){ $t=@filemtime($p); if($t!==false && $t>$latestSrcM) $latestSrcM=$t; };
  foreach (['index.js','package.json','worker_run.bat','worker_service.bat','install_service.ps1','update_worker.ps1'] as $fn){ $p=$workerDir.'/'.$fn; if(is_file($p)) $bump($p); }
  $nodeDir = $workerDir.'/node'; if(is_dir($nodeDir)){ $it=new DirectoryIterator($nodeDir); foreach($it as $fi){ if($fi->isFile()) $bump($fi->getPathname()); } }
  if ($zipFile){ $zipMRaw = @filemtime($zipFile) ?: 0; if ($latestSrcM && $zipMRaw + 2 < $latestSrcM) $zipStale = true; }
  // Read builder marker if present
  $marker = __DIR__.'/../storage/releases/worker_zip_meta.json';
  if (is_file($marker)) { $j = json_decode((string)@file_get_contents($marker), true); if (is_array($j)) $zipMeta = $j; }
}catch(Throwable $e){}
// Optional EXE discovery
// Optional EXE discovery (pick newest *Worker_Setup*.exe by mtime across common locations)
$exeBest = null; $exeBestM = -1;
$showExe = (get_setting('worker_exe_visible','0') === '1');
if ($showExe){
  foreach ([
    __DIR__ . '/../storage/releases/*Worker_Setup*.exe',
    __DIR__ . '/../releases/*Worker_Setup*.exe',
    __DIR__ . '/../worker/build/*Worker_Setup*.exe',
  ] as $g){
    foreach ((glob($g) ?: []) as $p){
      if (is_file($p)){
        $m = @filemtime($p);
        if ($m !== false && $m > $exeBestM){ $exeBestM = $m; $exeBest = realpath($p); }
      }
    }
  }
  if ($exeBest){ $installerFile = $exeBest; $installerSizeMB = round(filesize($installerFile)/1048576, 1); $installerMTime = date('Y-m-d H:i', filemtime($installerFile)); }
}
include __DIR__ . '/../layout_header.php';
?>
<div class="card">
  <h2>إعداد عامل ويندوز</h2>
  <p class="muted">يمكنك الاختيار بين حزمة محمولة ZIP (موصى بها حاليًا) أو المُثبت EXE (اختياري). بعد التشغيل، ستُفتح شاشة المعلومات الأساسية تلقائيًا.</p>
  <div class="badge">تأكد من تفعيل "السيرفر الداخلي" وإعداد <b>INTERNAL_SECRET</b> القوي أولًا.</div>

  <div class="card" style="display:flex;align-items:center;justify-content:space-between;gap:12px">
    <div>
      <h3 style="margin:0 0 4px">تحميل الحزمة المحمولة (ZIP)</h3>
      <div class="muted">تفك الضغط وتشغّل مباشرة بدون تثبيت. تتضمن Node و Playwright ومتطلبات التشغيل.</div>
      <?php if ($zipFile): ?>
        <div class="badge success" style="margin-top:6px">
          جاهز للتنزيل — ~<?php echo htmlspecialchars((string)$zipSizeMB); ?> MB
          <?php if ($zipSha): ?><br>SHA256: <span style="font-family:ui-monospace,monospace"><?php echo htmlspecialchars($zipSha); ?></span><?php endif; ?>
          <br>آخر تحديث: <?php echo htmlspecialchars($zipMTime); ?>
          <?php if ($appVer): ?><br>إصدار العامل المتوقع (APP_VER): <b><?php echo htmlspecialchars($appVer); ?></b><?php endif; ?>
          <?php if (is_array($zipMeta) && !empty($zipMeta['built_at'])): ?><br>تم البناء: <?php echo htmlspecialchars((string)$zipMeta['built_at']); ?><?php endif; ?>
        </div>
        <?php if ($zipStale): ?>
          <div class="badge warning" style="margin-top:6px">قد تكون هذه الحزمة قديمة مقارنةً بمصدر العامل الحالي. يُنصح بإعادة البناء التلقائي عبر زر التنزيل (سنعيد توليد الحزمة) أو تحديث الحزمة يدويًا.</div>
        <?php endif; ?>
        <?php if (!$zipArchiveAvailable): ?>
          <div class="badge danger" style="margin-top:6px">تحذير: امتداد ZipArchive غير متاح على الخادم — التعويل على توليد الحزمة تلقائيًا قد يفشل.</div>
        <?php endif; ?>
        <?php if (!$hasEmbeddedNode): ?>
          <div class="badge warning" style="margin-top:6px">لا يوجد node\node.exe مضمّن في مجلد العامل على الخادم. ستعتمد الحزمة على worker.exe أو Node المثبّت على جهاز العامل. يُفضَّل تضمين Node (محمول) للحزم المحمولة.</div>
        <?php endif; ?>
        <?php if (!$hasNodeModules): ?>
          <div class="badge warning" style="margin-top:6px">مجلد node_modules غير موجود في المصدر. إن كانت الحزمة ستُشغَّل دون اتصال، ضمّن node_modules داخل الحزمة أو جهّز npm install على جهاز العامل.</div>
        <?php endif; ?>
      <?php else: ?>
          <div class="badge danger" style="margin-top:6px">لا توجد حزمة ZIP حالية في <code>storage/releases</code>.</div>
      <?php endif; ?>
    </div>
    <a href="<?php echo $dlZip; ?>" class="btn" style="white-space:nowrap">تنزيل ZIP</a>
  </div>

  <div class="card" style="display:flex;align-items:center;justify-content:space-between;gap:12px">
    <div>
      <h3 style="margin:0 0 4px">ملف التهيئة الجاهز (.env)</h3>
      <div class="muted">يُولَّد تلقائيًا من الإعدادات الحالية (Worker Base URL, Internal Secret, وغيرها). ضعه بجانب index.js داخل مجلد العامل قبل التشغيل.</div>
      <div class="badge" style="margin-top:6px">BASE_URL = <?php echo htmlspecialchars($base_url); ?> • PULL = <?php echo htmlspecialchars($interval); ?>s</div>
    </div>
    <a href="<?php echo linkTo('api/worker_env.php'); ?>" class="btn outline" style="white-space:nowrap">تنزيل worker.env</a>
  </div>

  <?php if ($showExe && $installerFile): ?>
  <div class="card" style="display:flex;align-items:center;justify-content:space-between;gap:12px">
    <div>
      <h3 style="margin:0 0 4px">المُثبت (EXE)</h3>
      <div class="muted">خيار بديل للتثبيت كخدمة تلقائيًا؛ قد يظهر تحذير SmartScreen على بعض الأجهزة.</div>
        <div class="badge" style="margin-top:6px">
        حجم ~<?php echo htmlspecialchars((string)$installerSizeMB); ?> MB — آخر تحديث: <?php echo htmlspecialchars($installerMTime); ?>
      </div>
    </div>
    <a href="<?php echo $dlExe; ?>" class="btn outline" style="white-space:nowrap">تنزيل EXE</a>
  </div>
  <?php endif; ?>

  <div class="card">
    <h3>خطوات سريعة (ZIP)</h3>
    <ol>
  <li>نزّل الحزمة ZIP وفكّها في أي مجلد (مثال: <span class="kbd">C:\OptForgeWorker</span>).</li>
      <li>شغّل <span class="kbd">worker_run.bat</span>. عند أول تشغيل افتح <span class="kbd">http://127.0.0.1:4499/setup</span>، أدخل <b>BASE_URL</b> = <?php echo htmlspecialchars($base_url); ?> و <b>INTERNAL_SECRET</b> الحالي، و<b>WORKER_ID</b>.</li>
      <li>للتشغيل المستمر كخدمة: نفّذ <span class="kbd">worker_service.bat</span> أو استخدم <span class="kbd">install_service.ps1</span> (يتطلب صلاحيات المدير).</li>
      <li>سجلات التشغيل داخل المجلد <span class="kbd">logs/</span>.</li>
    </ol>
  </div>

</div>
<?php include __DIR__ . '/../layout_footer.php'; ?>
