<?php require_once __DIR__ . "/bootstrap.php"; require_once __DIR__ . '/lib/system.php'; $u=current_user(); $cur=basename($_SERVER['SCRIPT_NAME']??''); $isAdmin = $u && $u['role']==='admin';
// Admin-wide security headers (non-breaking)
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
?>
<!doctype html><html lang="ar" dir="rtl"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta http-equiv="Referrer-Policy" content="no-referrer">
<meta http-equiv="X-Content-Type-Options" content="nosniff">
<meta name="theme-color" content="#0ea5e9">
<title><?php echo htmlspecialchars(system_product_name()); ?> — <?php echo htmlspecialchars(system_tagline_en()); ?></title>
<?php if(function_exists('feature_enabled') && feature_enabled('seo_meta_enabled','0')): ?>
  <?php 
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS'])!=='off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO'])==='https');
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $uri  = $_SERVER['REQUEST_URI'] ?? '/';
    $canonical = $host ? ($scheme.'://'.$host.$uri) : '';
    $site = system_product_name();
    $desc = system_tagline_en();
  ?>
  <?php if($canonical): ?><link rel="canonical" href="<?php echo htmlspecialchars($canonical); ?>"><?php endif; ?>
  <meta name="description" content="<?php echo htmlspecialchars($desc); ?>">
  <meta name="robots" content="noindex, nofollow">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="<?php echo htmlspecialchars($site); ?>">
  <meta property="og:title" content="<?php echo htmlspecialchars($site.' — '.system_tagline_en()); ?>">
  <?php if($canonical): ?><meta property="og:url" content="<?php echo htmlspecialchars($canonical); ?>"><?php endif; ?>
  <meta property="og:locale" content="ar_SA">
  <meta property="og:description" content="<?php echo htmlspecialchars(system_tagline_ar()); ?>">
  <meta name="twitter:card" content="summary">
  <meta name="twitter:title" content="<?php echo htmlspecialchars($site); ?>">
  <meta name="twitter:description" content="<?php echo htmlspecialchars($desc); ?>">
<?php endif; ?>
<!-- Arabic UI font -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?php echo linkTo('assets/css/style.css'); ?>">
<!-- UI Libraries -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6/css/all.min.css">
<?php if($u): ?>
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
  <?php if($cur==='fetch.php'): ?>
    <?php $leafletLocalCss = is_file(__DIR__.'/assets/vendor/leaflet/leaflet.css'); $leafletLocalJs = is_file(__DIR__.'/assets/vendor/leaflet/leaflet.js'); ?>
    <?php if($leafletLocalCss): ?>
      <link rel="stylesheet" href="<?php echo linkTo('assets/vendor/leaflet/leaflet.css'); ?>"/>
    <?php else: ?>
      <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <?php endif; ?>
  <?php endif; ?>
  <script defer src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script defer src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
  <script defer src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
  <?php if($cur==='fetch.php'): ?>
    <?php if($leafletLocalJs): ?>
      <script src="<?php echo linkTo('assets/vendor/leaflet/leaflet.js'); ?>"></script>
    <?php else: ?>
      <script id="leaflet-cdn" src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>
<meta name="ui-persist-filters" content="<?php echo (function_exists('feature_enabled') && feature_enabled('ui_persist_filters','0')) ? 'true' : 'false'; ?>">
<script defer src="<?php echo linkTo('assets/js/ui.js'); ?>"></script>
<?php $noAuth = !$u; ?>
</head><body class="<?php echo $noAuth ? 'no-auth' : ''; ?>">
<a class="skip-link" href="#main-content">تخطي إلى المحتوى</a>
<div class="app">
  <button type="button" class="fab" id="btn-to-top" aria-label="العودة للأعلى" title="العودة للأعلى" hidden>
    <i class="fa-solid fa-arrow-up"></i>
  </button>
  <?php if($u): ?>
  <aside class="sidebar" id="sidebar">
  <div class="brand"><span class="dot"></span><span class="brand-text"><?php echo htmlspecialchars(system_product_name()); ?></span></div>
    <nav class="menu">
      <?php if($u): ?>
        <?php if($isAdmin): ?>
  <a class="<?php echo $cur==='dashboard.php'?'active':''; ?>" <?php echo $cur==='dashboard.php'?'aria-current="page" title="الصفحة الحالية"':''; ?> href="<?php echo linkTo('admin/dashboard.php'); ?>"><i class="fa-solid fa-gauge"></i><span>لوحة المدير</span></a>
  <a class="<?php echo $cur==='users.php'?'active':''; ?>" <?php echo $cur==='users.php'?'aria-current="page" title="الصفحة الحالية"':''; ?> href="<?php echo linkTo('admin/users.php'); ?>"><i class="fa-solid fa-users"></i><span>المستخدمون</span></a>
  <a class="<?php echo $cur==='settings.php' && !isset($_GET['tab'])?'active':''; ?>" <?php echo ($cur==='settings.php' && !isset($_GET['tab']))?'aria-current="page" title="الصفحة الحالية"':''; ?> href="<?php echo linkTo('admin/settings.php'); ?>"><i class="fa-solid fa-sliders"></i><span>الإعدادات</span></a>
  <a class="<?php echo ($cur==='settings.php'&&($_GET['tab']??'')==='providers')?'active':''; ?>" <?php echo ($cur==='settings.php'&&($_GET['tab']??'')==='providers')?'aria-current="page" title="الصفحة الحالية"':''; ?> href="<?php echo linkTo('admin/settings.php?tab=providers'); ?>"><i class="fa-solid fa-database"></i><span>مزودات البيانات</span></a>
  <a class="<?php echo $cur==='fetch.php'?'active':''; ?>" <?php echo $cur==='fetch.php'?'aria-current="page" title="الصفحة الحالية"':''; ?> href="<?php echo linkTo('admin/fetch.php'); ?>"><i class="fa-solid fa-cloud-arrow-down"></i><span>جلب (Providers)</span></a>
  <a class="<?php echo $cur==='leads.php'?'active':''; ?>" <?php echo $cur==='leads.php'?'aria-current="page" title="الصفحة الحالية"':''; ?> href="<?php echo linkTo('admin/leads.php'); ?>"><i class="fa-solid fa-address-book"></i><span>كل الأرقام</span></a>
  <a class="<?php echo $cur==='assign.php'?'active':''; ?>" <?php echo $cur==='assign.php'?'aria-current="page" title="الصفحة الحالية"':''; ?> href="<?php echo linkTo('admin/assign.php'); ?>"><i class="fa-solid fa-share-nodes"></i><span>توزيع</span></a>
  <a class="<?php echo $cur==='logs.php'?'active':''; ?>" <?php echo $cur==='logs.php'?'aria-current="page" title="الصفحة الحالية"':''; ?> href="<?php echo linkTo('admin/logs.php'); ?>"><i class="fa-solid fa-paper-plane"></i><span>سجل الإرسال</span></a>
  <a class="<?php echo $cur==='internal.php'?'active':''; ?>" <?php echo $cur==='internal.php'?'aria-current="page" title="الصفحة الحالية"':''; ?> href="<?php echo linkTo('admin/internal.php'); ?>"><i class="fa-solid fa-gears"></i><span>الوظائف الداخلية</span></a>
  <a class="<?php echo $cur==='dlq.php'?'active':''; ?>" <?php echo $cur==='dlq.php'?'aria-current="page" title="الصفحة الحالية"':''; ?> href="<?php echo linkTo('admin/dlq.php'); ?>"><i class="fa-solid fa-triangle-exclamation"></i><span>قائمة الرسائل الميتة</span></a>
  <a class="<?php echo $cur==='health.php'?'active':''; ?>" <?php echo $cur==='health.php'?'aria-current="page" title="الصفحة الحالية"':''; ?> href="<?php echo linkTo('admin/health.php'); ?>"><i class="fa-solid fa-heart-pulse"></i><span>الصحة (Health)</span></a>
  <a class="<?php echo $cur==='monitor.php'?'active':''; ?>" <?php echo $cur==='monitor.php'?'aria-current="page" title="الصفحة الحالية"':''; ?> href="<?php echo linkTo('admin/monitor.php'); ?>"><i class="fa-solid fa-wave-square"></i><span>مراقبة مباشرة</span></a>
  <a class="<?php echo $cur==='workers.php'?'active':''; ?>" <?php echo $cur==='workers.php'?'aria-current="page" title="الصفحة الحالية"':''; ?> href="<?php echo linkTo('admin/workers.php'); ?>"><i class="fa-solid fa-robot"></i><span>الوحدات الطرفية</span></a>
  <a class="<?php echo $cur==='categories.php'?'active':''; ?>" <?php echo $cur==='categories.php'?'aria-current="page" title="الصفحة الحالية"':''; ?> href="<?php echo linkTo('admin/categories.php'); ?>"><i class="fa-solid fa-tags"></i><span>التصنيفات</span></a>
  <a class="<?php echo $cur==='geo.php'?'active':''; ?>" <?php echo $cur==='geo.php'?'aria-current="page" title="الصفحة الحالية"':''; ?> href="<?php echo linkTo('admin/geo.php'); ?>"><i class="fa-solid fa-map-location-dot"></i><span>البيانات الجغرافية</span></a>
  <a class="<?php echo $cur==='classification.php'?'active':''; ?>" <?php echo $cur==='classification.php'?'aria-current="page" title="الصفحة الحالية"':''; ?> href="<?php echo linkTo('admin/classification.php'); ?>"><i class="fa-solid fa-filter-circle-dollar"></i><span>قواعد التصنيف</span></a>
  <a class="<?php echo $cur==='autodata.php'?'active':''; ?>" <?php echo $cur==='autodata.php'?'aria-current="page" title="الصفحة الحالية"':''; ?> href="<?php echo linkTo('admin/autodata.php'); ?>"><i class="fa-solid fa-bolt"></i><span>البيانات التلقائية</span></a>
  <a class="<?php echo $cur==='worker_setup.php'?'active':''; ?>" <?php echo $cur==='worker_setup.php'?'aria-current="page" title="الصفحة الحالية"':''; ?> href="<?php echo linkTo('admin/worker_setup.php'); ?>"><i class="fa-solid fa-screwdriver-wrench"></i><span>إعداد عامل ويندوز</span></a>
  <a class="<?php echo $cur==='worker_updates.php'?'active':''; ?>" <?php echo $cur==='worker_updates.php'?'aria-current="page" title="الصفحة الحالية"':''; ?> href="<?php echo linkTo('admin/worker_updates.php'); ?>"><i class="fa-solid fa-cloud-arrow-up"></i><span>تحديثات العامل</span></a>
  <a class="<?php echo ($cur==='index.php' && strpos($_SERVER['REQUEST_URI']??'','/admin/diagnostics/')!==false)?'active':''; ?>" <?php echo ($cur==='index.php' && strpos($_SERVER['REQUEST_URI']??'','/admin/diagnostics/')!==false)?'aria-current="page" title="الصفحة الحالية"':''; ?> href="<?php echo linkTo('admin/diagnostics/index.php'); ?>"><i class="fa-solid fa-stethoscope"></i><span>التشخيصات</span></a>
  <a class="<?php echo $cur==='audit.php'?'active':''; ?>" <?php echo $cur==='audit.php'?'aria-current="page" title="الصفحة الحالية"':''; ?> href="<?php echo linkTo('admin/audit.php'); ?>"><i class="fa-solid fa-clipboard-list"></i><span>سجل التدقيق</span></a>
        <?php else: ?>
  <a class="<?php echo $cur==='dashboard.php'?'active':''; ?>" <?php echo $cur==='dashboard.php'?'aria-current="page" title="الصفحة الحالية"':''; ?> href="<?php echo linkTo('agent/dashboard.php'); ?>"><i class="fa-solid fa-gauge"></i><span>لوحة المندوب</span></a>
  <a class="<?php echo $cur==='fetch.php'?'active':''; ?>" <?php echo $cur==='fetch.php'?'aria-current="page" title="الصفحة الحالية"':''; ?> href="<?php echo linkTo('agent/fetch.php'); ?>"><i class="fa-solid fa-cloud-arrow-down"></i><span>جلب (Providers)</span></a>
  <a class="<?php echo $cur==='logs.php'?'active':''; ?>" <?php echo $cur==='logs.php'?'aria-current="page" title="الصفحة الحالية"':''; ?> href="<?php echo linkTo('agent/logs.php'); ?>"><i class="fa-solid fa-paper-plane"></i><span>سجل الإرسال</span></a>
  <a class="<?php echo $cur==='profile.php'?'active':''; ?>" <?php echo $cur==='profile.php'?'aria-current="page" title="الصفحة الحالية"':''; ?> href="<?php echo linkTo('agent/profile.php'); ?>"><i class="fa-solid fa-id-badge"></i><span>الملف الشخصي</span></a>
        <?php endif; ?>
        <a href="<?php echo linkTo('auth/logout.php'); ?>"><i class="fa-solid fa-right-from-bracket"></i><span>خروج</span></a>
      <?php endif; ?>
    </nav>
  </aside>
  <?php endif; ?>
  <div class="main" id="main-content">
    <header class="topbar">
  <?php if($u): ?><button class="icon-btn" data-toggle-sidebar title="إظهار/إخفاء القائمة" aria-controls="sidebar" aria-expanded="false" aria-label="إظهار/إخفاء القائمة"><i class="fa-solid fa-bars"></i></button><?php endif; ?>
      <div class="spacer"></div>
      <nav class="top-actions">
        <?php if($u): ?>
          <a class="icon-btn" href="<?php echo linkTo('admin/health.php'); ?>" title="الصحة (Health)" aria-label="الصحة (Health)"><i class="fa-solid fa-heart-pulse"></i></a>
          <a class="icon-btn" href="<?php echo linkTo('admin/internal.php'); ?>" title="الوظائف الداخلية" aria-label="الوظائف الداخلية"><i class="fa-solid fa-gears"></i></a>
          <a class="icon-btn" href="<?php echo linkTo('admin/settings.php'); ?>" title="الإعدادات" aria-label="الإعدادات"><i class="fa-solid fa-sliders"></i></a>
        <?php endif; ?>
  <button class="icon-btn" data-toggle-theme title="تبديل المظهر (فاتح/داكن)" aria-label="تبديل المظهر" aria-pressed="false"><i class="fa-solid fa-moon" aria-hidden="true"></i></button>
      </nav>
      <?php if($u): ?><div class="user-pill"><i class="fa-solid fa-user"></i><span><?php echo htmlspecialchars($u['name']??$u['email']??''); ?></span></div><?php endif; ?>
    </header>
    <?php $isStopped = system_is_globally_stopped(); $isPause = system_is_in_pause_window(); if($isStopped || $isPause): ?>
      <div class="alert alert-danger">
        <i class="fa-solid fa-circle-exclamation"></i>
        النظام متوقف مؤقتًا<?php if($isStopped): ?> (إيقاف شامل)<?php elseif($isPause): ?> (فترة إيقاف يومية)<?php endif; ?> — لا يمكن تنفيذ عمليات الجلب حتى يُعاد التشغيل.
      </div>
    <?php endif; ?>
    <div class="content"><div class="wrap">
<?php 
  // Auto page title + breadcrumb
  $titleMap = [
    'dashboard.php'=>'لوحة المدير',
    'users.php'=>'المستخدمون',
    'settings.php'=>'الإعدادات',
    'fetch.php'=>'جلب (Providers)',
    'leads.php'=>'كل الأرقام',
    'assign.php'=>'توزيع',
    'logs.php'=>'سجل الإرسال',
    'internal.php'=>'الوظائف الداخلية',
    'health.php'=>'الصحة (Health)',
  'monitor.php'=>'مراقبة مباشرة',
    'workers.php'=>'الوحدات الطرفية',
    'categories.php'=>'التصنيفات',
    'classification.php'=>'قواعد التصنيف',
  'geo.php'=>'البيانات الجغرافية',
    'autodata.php'=>'البيانات التلقائية',
    'worker_setup.php'=>'إعداد عامل ويندوز',
  ];
  $pageTitle = $titleMap[$cur] ?? ($isAdmin?'لوحة المدير':'');
  if($isAdmin && strpos($_SERVER['REQUEST_URI']??'','/admin/diagnostics/')!==false){ $pageTitle='التشخيصات'; }
?>
<div class="page-head">
  <?php if($pageTitle): ?><h1><?php echo htmlspecialchars($pageTitle); ?></h1><?php endif; ?>
  <nav class="crumbs">
    <a href="<?php echo linkTo($isAdmin?'admin/dashboard.php':'agent/dashboard.php'); ?>">الرئيسية</a>
    <?php if($pageTitle): ?><span>/</span><span><?php echo htmlspecialchars($pageTitle); ?></span><?php endif; ?>
  </nav>
</div>
