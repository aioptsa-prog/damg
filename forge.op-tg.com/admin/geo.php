<?php include __DIR__ . '/../layout_header.php'; $u=require_role('admin'); $pdo=db();
$geoDir = __DIR__.'/../storage/data/geo/sa';
$counts = ['regions'=>0,'cities'=>0,'districts'=>0]; $updated=null; $errMsg=null;
try{
  $dbPath = __DIR__ . '/../storage/data/geo/sa/sa_geo.db';
  if(is_file($dbPath)){
    $g = new PDO('sqlite:'.$dbPath); $g->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $counts['regions'] = (int)$g->query("SELECT COUNT(*) c FROM regions")->fetch()['c'];
    $counts['cities'] = (int)$g->query("SELECT COUNT(*) c FROM cities")->fetch()['c'];
    $counts['districts'] = (int)$g->query("SELECT COUNT(*) c FROM sqlite_master WHERE type='table' AND name='districts'")->fetch()['c'] ? (int)$g->query("SELECT COUNT(*) c FROM districts")->fetch()['c'] : 0;
    $updated = date('Y-m-d H:i', filemtime($dbPath));
  } else {
    // Diagnose storage dir
    $dir = dirname($dbPath);
    $exists = is_dir($dir);
    $w = $exists ? is_writable($dir) : false;
    if(!$exists || !$w){ $errMsg = 'Storage not writable: '.str_replace('\\','/',$dir); }
  }
}catch(Throwable $e){ $errMsg = 'Geo DB error: '.$e->getMessage(); }
?>
<div class="card">
  <h2>البيانات الجغرافية — السعودية</h2>
  <?php if(isset($_GET['msg'])): ?><p class="badge"><?php echo htmlspecialchars($_GET['msg']); ?></p><?php endif; ?>
  <?php if($errMsg): ?>
    <div class="alert alert-danger">
      <div>
        <div><b>تعذّر فتح قاعدة البيانات الجغرافية</b></div>
        <div class="muted"><?php echo htmlspecialchars($errMsg); ?></div>
        <div class="muted">يمكنك إصلاح الأذونات على مجلد <code>storage/data/geo/sa</code>، أو استخدام أداة CLI: <code>php tools/geo_init_sa.php init</code></div>
      </div>
    </div>
  <?php endif; ?>
  <div class="grid-3">
    <div class="card"><h3>المناطق</h3><div style="font-size:24px;font-weight:700"><?php echo $counts['regions']; ?></div></div>
    <div class="card"><h3>المدن</h3><div style="font-size:24px;font-weight:700"><?php echo $counts['cities']; ?></div></div>
    <div class="card"><h3>الأحياء</h3><div style="font-size:24px;font-weight:700"><?php echo $counts['districts']; ?></div></div>
  </div>
  <div class="muted">آخر تحديث للقاعدة: <?php echo $updated? htmlspecialchars($updated):'غير متوفر'; ?></div>
  <div class="card" style="margin-top:12px">
    <h3>التكامل</h3>
    <ul>
      <li>التصنيف النصي والبالنقطة مفعل في عمليات الإدخال تلقائيًا (sa_geo.db).</li>
      <li>سيتم تلخيص المهام حسب المدينة/الحي في لوحة المراقبة لاحقًا.</li>
    </ul>
  </div>
  <div class="card" style="margin-top:12px">
    <h3>إدارة القاعدة</h3>
    <form method="post" action="<?php echo linkTo('api/geo_admin.php'); ?>" class="row" style="gap:8px;align-items:center">
      <?php echo csrf_input(); ?>
      <input type="hidden" name="action" value="init">
      <button class="btn">تهيئة القاعدة وزرع المناطق</button>
      <span class="muted">ينشئ sa_geo.db والجداول ويزرع 13 منطقة</span>
    </form>
    <form method="post" action="<?php echo linkTo('api/geo_admin.php'); ?>" class="row" style="gap:8px;align-items:center; margin-top:8px">
      <?php echo csrf_input(); ?>
      <input type="hidden" name="action" value="fetch_cities">
      <button class="btn">جلب المدن من OpenStreetMap</button>
      <span class="muted">قد يستغرق دقائق — يُحدّث/يضيف المدن</span>
    </form>
    <form method="post" action="<?php echo linkTo('api/geo_admin.php'); ?>" class="row" style="gap:8px;align-items:center; margin-top:8px">
      <?php echo csrf_input(); ?>
      <input type="hidden" name="action" value="fetch_districts">
      <button class="btn">جلب الأحياء من OpenStreetMap</button>
      <span class="muted">قد يستغرق وقتًا — يربط الحي بأقرب مدينة (≤ 60 كم)</span>
    </form>
  </div>
</div>
<?php include __DIR__ . '/../layout_footer.php'; ?>
