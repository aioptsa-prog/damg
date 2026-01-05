<?php include __DIR__ . '/../layout_header.php'; require_once __DIR__ . '/../lib/limits.php'; $u=require_role('admin'); $msg=null; $tab = $_GET['tab'] ?? 'general';
$skipGeneral=false;
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!csrf_verify($_POST['csrf'] ?? '')){ $msg='CSRF فشل التحقق'; $_POST=[]; }
  // Lightweight action: toggle global stop without touching other settings
  if(($_POST['action']??'')==='toggle_global' && ($msg===null || strpos($msg,'CSRF')===false)){
    $val = isset($_POST['value']) && $_POST['value']==='1' ? '1' : '0';
    set_setting('system_global_stop', $val);
    $skipGeneral=true;
    $msg = ($val==='1') ? 'تم إيقاف النظام (Global Stop)'
                        : 'تم تشغيل النظام (إلغاء الإيقاف العام)';
  }
  // Full system seeding action
  if(($_POST['action']??'')==='seed_full' && ($msg===null || strpos($msg,'CSRF')===false)){
    $pdo = db();
    $counters = ['cats'=>0,'kws'=>0,'rules'=>0,'users'=>0,'jobs'=>0,'leads'=>0];
    // 1) Classification: import full taxonomy from asset
    if(isset($_POST['seed_cls_full'])){
      $replace = isset($_POST['seed_cls_replace']);
      $path = __DIR__ . '/../assets/classification_full.json';
      if(is_file($path)){
        $data = json_decode(@file_get_contents($path), true);
        if(is_array($data)){
          $pdo->beginTransaction();
          try{
            if($replace){ $pdo->exec('DELETE FROM category_keywords'); $pdo->exec('DELETE FROM category_rules'); }
            $mapNameToId = [];
            $selCat = $pdo->prepare('SELECT id FROM categories WHERE name=?');
            $insCat = $pdo->prepare("INSERT INTO categories(parent_id, name, created_at) VALUES(?,?,datetime('now'))");
            if(!empty($data['categories']) && is_array($data['categories'])){
              foreach($data['categories'] as $c){ $name=trim((string)($c['name']??'')); if($name==='') continue; $selCat->execute([$name]); $row=$selCat->fetch(); if($row){ $mapNameToId[$name]=(int)$row['id']; } else { $insCat->execute([null,$name]); $mapNameToId[$name]=(int)$pdo->lastInsertId(); $counters['cats']++; } }
              $updPar = $pdo->prepare('UPDATE categories SET parent_id=? WHERE id=?');
              foreach($data['categories'] as $c){ $name=trim((string)($c['name']??'')); $pname=null; if(isset($c['parent'])) $pname=trim((string)$c['parent']); if(!$pname && isset($c['parent_name'])) $pname=trim((string)$c['parent_name']); if(!$pname && isset($c['parent_id'])){ foreach($data['categories'] as $cand){ if(($cand['id']??null)===($c['parent_id']??null) && !empty($cand['name'])){ $pname=$cand['name']; break; } } } $cid=$mapNameToId[$name]??null; $pid=$pname?($mapNameToId[$pname]??null):null; if($cid){ $updPar->execute([$pid,$cid]); } }
            }
            if(!empty($data['keywords']) && is_array($data['keywords'])){
              $insKw = $pdo->prepare("INSERT INTO category_keywords(category_id, keyword, created_at) VALUES(?,?,datetime('now'))");
              foreach($data['keywords'] as $k){ $kw=trim((string)($k['keyword']??'')); if($kw==='') continue; $cname=null; if(isset($k['category'])) $cname=trim((string)$k['category']); if(!$cname && isset($k['category_name'])) $cname=trim((string)$k['category_name']); if(!$cname && isset($k['category_id'])){ if(!empty($data['categories']) && is_array($data['categories'])){ foreach($data['categories'] as $c){ if(($c['id']??null)===($k['category_id']??null) && !empty($c['name'])){ $cname=$c['name']; break; } } } } $cid=$cname?($mapNameToId[$cname]??null):null; if(!$cid) continue; $insKw->execute([$cid,$kw]); $counters['kws']++; }
            }
            if(!empty($data['rules']) && is_array($data['rules'])){
              $insRule = $pdo->prepare("INSERT INTO category_rules(category_id, target, pattern, match_mode, weight, note, enabled, created_at) VALUES(?,?,?,?,?,?,?,datetime('now'))");
              foreach($data['rules'] as $r){ $target=trim((string)($r['target']??'name')); $pattern=trim((string)($r['pattern']??'')); $mode=trim((string)($r['match_mode']??'contains')); $weight=(float)($r['weight']??1.0); $enabled=isset($r['enabled'])?(int)!!$r['enabled']:1; $cname=null; if(isset($r['category'])) $cname=trim((string)$r['category']); if(!$cname && isset($r['category_name'])) $cname=trim((string)$r['category_name']); if(!$cname && isset($r['category_id'])){ if(!empty($data['categories']) && is_array($data['categories'])){ foreach($data['categories'] as $c){ if(($c['id']??null)===($r['category_id']??null) && !empty($c['name'])){ $cname=$c['name']; break; } } } } $cid=$cname?($mapNameToId[$cname]??null):null; if(!$cid || $pattern==='') continue; $insRule->execute([$cid,$target,$pattern,$mode,$weight,trim((string)($r['note']??'')),$enabled]); $counters['rules']++; }
            }
            $pdo->commit();
          }catch(Throwable $e){ $pdo->rollBack(); $msg='فشل استيراد الحزمة الشاملة: '.$e->getMessage(); }
        }
      }
    }

    // 2) Users: seed demo agents if missing
    if(isset($_POST['seed_users'])){
      $insU = $pdo->prepare("INSERT INTO users(mobile,name,role,password_hash,created_at) VALUES(?,?,?,?,datetime('now'))");
      $needA = !$pdo->query("SELECT 1 FROM users WHERE role='agent' AND mobile='0588888888' LIMIT 1")->fetch(); if($needA){ $insU->execute(['0588888888','Agent A','agent', password_hash('pass',PASSWORD_DEFAULT)]); $counters['users']++; }
      $needB = !$pdo->query("SELECT 1 FROM users WHERE role='agent' AND mobile='0577777777' LIMIT 1")->fetch(); if($needB){ $insU->execute(['0577777777','Agent B','agent', password_hash('pass',PASSWORD_DEFAULT)]); $counters['users']++; }
    }

    // 3) Settings: enable internal server and sensible defaults
    if(isset($_POST['enable_internal'])){
      set_setting('internal_server_enabled','1');
      $sec = get_setting('internal_secret',''); if($sec===''){ $sec = bin2hex(random_bytes(32)); set_setting('internal_secret',$sec); }
      if(get_setting('worker_pull_interval_sec','')==='') set_setting('worker_pull_interval_sec','30');
    }
    if(get_setting('default_ll','')==='') set_setting('default_ll','24.7136,46.6753');
    if(get_setting('default_language','')==='') set_setting('default_language','ar');
    if(get_setting('default_region','')==='') set_setting('default_region','sa');
    if(get_setting('default_radius_km','')==='') set_setting('default_radius_km','25');
    if(get_setting('provider_order','')==='') set_setting('provider_order','osm,foursquare,mapbox,radar,google');
    set_setting('classify_enabled','1');

    // 4) Seed demo internal jobs
    if(isset($_POST['seed_jobs'])){
      $adminId = (int)($pdo->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetch()['id'] ?? 0);
      if(!$adminId){ $pdo->prepare("INSERT INTO users(mobile,name,role,password_hash,created_at) VALUES(?,?,?,?,datetime('now'))")->execute(['0599999999','Administrator','admin', password_hash('admin123',PASSWORD_DEFAULT)]); $adminId=(int)$pdo->lastInsertId(); }
      $ll = get_setting('default_ll','24.7136,46.6753');
      $jobs = [ ['مطعم',$ll], ['كوفي',$ll], ['نجارة',$ll], ['صيدلية',$ll] ];
      $insJ = $pdo->prepare("INSERT INTO internal_jobs(requested_by_user_id, role, agent_id, query, ll, radius_km, lang, region, status, created_at, updated_at) VALUES(?,?,?,?,?,?,?,?,'queued',datetime('now'),datetime('now'))");
      foreach($jobs as $j){ $insJ->execute([$adminId,'admin', null, $j[0], $j[1], (int)get_setting('default_radius_km','25'), get_setting('default_language','ar'), get_setting('default_region','sa')]); $counters['jobs']++; }
    }

    // 5) Seed a few demo leads
    if(isset($_POST['seed_leads'])){
      $adminId = (int)($pdo->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetch()['id'] ?? 0);
      $insL = $pdo->prepare("INSERT OR IGNORE INTO leads(phone,name,city,country,created_at,source,created_by_user_id) VALUES(?,?,?,?,datetime('now'),'demo',?)");
      $samples = [ ['0500000001','مطعم الأصدقاء','الرياض','السعودية'], ['0500000002','كوفي كيان','الرياض','السعودية'], ['0500000003','صيدلية النهدي','الرياض','السعودية'] ];
      foreach($samples as $s){ $insL->execute([$s[0],$s[1],$s[2],$s[3],$adminId]); $counters['leads']++; }
    }

    $msg = 'تمت التهيئة: أقسام+'.(int)$counters['cats'].', كلمات+'.(int)$counters['kws'].', قواعد+'.(int)$counters['rules'].', مستخدمون+'.(int)$counters['users'].', وظائف+'.(int)$counters['jobs'].', أرقام+'.(int)$counters['leads'];
  }
  if($tab==='general' && !$skipGeneral){
    set_setting('google_api_key', trim($_POST['google_api_key'] ?? ''));
    set_setting('default_ll', trim($_POST['default_ll'] ?? ''));
    set_setting('default_radius_km', (string)intval($_POST['default_radius_km'] ?? 25));
    set_setting('default_language', trim($_POST['default_language'] ?? 'ar'));
    set_setting('default_region', trim($_POST['default_region'] ?? 'sa'));
    set_setting('whatsapp_message', trim($_POST['whatsapp_message'] ?? ''));
    set_setting('washeej_url', trim($_POST['washeej_url'] ?? 'https://wa.washeej.com/api/qr/rest/send_message'));
    set_setting('washeej_token', trim($_POST['washeej_token'] ?? ''));
    set_setting('washeej_sender', trim($_POST['washeej_sender'] ?? ''));
    set_setting('washeej_use_per_agent', isset($_POST['washeej_use_per_agent']) ? '1' : '0');
    set_setting('washeej_instance_id', trim($_POST['washeej_instance_id'] ?? ''));
    set_setting('internal_server_enabled', isset($_POST['internal_server_enabled']) ? '1' : '0');
    set_setting('internal_secret', trim($_POST['internal_secret'] ?? ''));
  // Alerts configuration
  set_setting('alert_webhook_url', trim($_POST['alert_webhook_url'] ?? ''));
  set_setting('alert_email', trim($_POST['alert_email'] ?? ''));
  set_setting('alert_slack_token', trim($_POST['alert_slack_token'] ?? ''));
  set_setting('alert_slack_channel', trim($_POST['alert_slack_channel'] ?? ''));
  // Self-update enable toggle
  set_setting('enable_self_update', isset($_POST['enable_self_update']) ? '1' : '0');
  set_setting('worker_pull_interval_sec', (string)max(5,intval($_POST['worker_pull_interval_sec'] ?? 30)));
    // Worker runtime knobs
    set_setting('worker_headless', isset($_POST['worker_headless']) ? '1' : '0');
    set_setting('worker_max_pages', (string)max(1,intval($_POST['worker_max_pages'] ?? 5)));
    set_setting('worker_lease_sec', (string)max(60,intval($_POST['worker_lease_sec'] ?? 180)));
    set_setting('worker_report_batch_size', (string)max(1,intval($_POST['worker_report_batch_size'] ?? 10)));
    set_setting('worker_report_every_ms', (string)max(1000,intval($_POST['worker_report_every_ms'] ?? 15000)));
  set_setting('worker_report_first_ms', (string)max(200,intval($_POST['worker_report_first_ms'] ?? 2000)));
    set_setting('worker_item_delay_ms', (string)max(0,intval($_POST['worker_item_delay_ms'] ?? 800)));
    set_setting('worker_chrome_exe', trim($_POST['worker_chrome_exe'] ?? ''));
    set_setting('worker_chrome_args', trim($_POST['worker_chrome_args'] ?? ''));
    // Health thresholds
    $thrMin = max(5, min(180, intval($_POST['stuck_processing_threshold_min'] ?? (int)get_setting('stuck_processing_threshold_min','10'))));
    set_setting('stuck_processing_threshold_min', (string)$thrMin);
  // Worker centralized config
  $wb = trim($_POST['worker_base_url'] ?? '');
  if($wb!=='') $wb = rtrim($wb, '/');
  set_setting('worker_base_url', $wb);
  set_setting('worker_config_code', trim($_POST['worker_config_code'] ?? ''));
  // Internal dispatch strategy
  $jpo = $_POST['job_pick_order'] ?? get_setting('job_pick_order','fifo');
  if(!in_array($jpo, ['fifo','newest','random','pow2','rr_agent','fair_query'], true)) $jpo = 'fifo';
  set_setting('job_pick_order', $jpo);
  // Classification settings
  set_setting('classify_enabled', isset($_POST['classify_enabled']) ? '1' : '0');
  set_setting('classify_threshold', (string)max(0,(float)($_POST['classify_threshold'] ?? 1.0)));
  // Per-target weights
  foreach([
    'classify_w_kw_name','classify_w_kw_types','classify_w_name','classify_w_types','classify_w_website','classify_w_email','classify_w_source_url','classify_w_city','classify_w_country','classify_w_phone'
  ] as $wk){
    set_setting($wk, (string)max(0,(float)($_POST[$wk] ?? get_setting($wk,'1.0'))));
  }
  // Maintenance settings
  set_setting('maintenance_secret', trim($_POST['maintenance_secret'] ?? ''));
  set_setting('reclassify_default_limit', (string)max(1,intval($_POST['reclassify_default_limit'] ?? 200)));
  set_setting('reclassify_only_empty', isset($_POST['reclassify_only_empty']) ? '1' : '0');
  set_setting('reclassify_override', isset($_POST['reclassify_override']) ? '1' : '0');
  // System control
  set_setting('system_pause_enabled', isset($_POST['system_pause_enabled']) ? '1' : '0');
  set_setting('system_pause_start', trim($_POST['system_pause_start'] ?? '23:59'));
  set_setting('system_pause_end', trim($_POST['system_pause_end'] ?? '09:00'));
  set_setting('system_global_stop', isset($_POST['system_global_stop']) ? '1' : '0');
    // Simple audit log
    try{
      $logDir = __DIR__ . '/../storage/logs'; if(!is_dir($logDir)) @mkdir($logDir,0777,true);
      $who = (int)($u['id'] ?? 0);
      $line = sprintf("[%s] settings_update tab=general by_user=%d from_ip=%s\n", date('c'), $who, $_SERVER['REMOTE_ADDR'] ?? '-');
      file_put_contents($logDir.'/audit.log', $line, FILE_APPEND);
    }catch(Throwable $e){}
    $msg='تم حفظ الإعدادات';
  } else if($tab==='providers'){
    set_setting('provider_order', trim($_POST['provider_order'] ?? 'osm,foursquare,mapbox,radar,google'));
    set_setting('foursquare_api_key', trim($_POST['foursquare_api_key'] ?? ''));
    set_setting('mapbox_api_key', trim($_POST['mapbox_api_key'] ?? ''));
    set_setting('radar_api_key', trim($_POST['radar_api_key'] ?? ''));
  set_setting('tile_ttl_days', (string)max(1,intval($_POST['tile_ttl_days'] ?? 14)));
  // Allow very high caps for internal use cases
  $cap = isset($_POST['daily_details_cap']) ? intval($_POST['daily_details_cap']) : intval(get_setting('daily_details_cap','1000000'));
  if($cap < 0) $cap = 0; if($cap > 100000000) $cap = 100000000; // safety upper bound
  set_setting('daily_details_cap', (string)$cap);
    // Optional custom Leaflet tile sources (JSON array of {url, att})
    $ts = trim($_POST['tile_sources_json'] ?? '');
    if($ts !== ''){
      $arr = json_decode($ts, true);
      if(is_array($arr)){
        set_setting('tile_sources_json', json_encode($arr, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
      }
    } else {
      set_setting('tile_sources_json','');
    }
    // Exhaustive fetch controls
    set_setting('fetch_exhaustive', isset($_POST['fetch_exhaustive']) ? '1' : '0');
    $step = isset($_POST['exhaustive_grid_step_km']) ? (float)$_POST['exhaustive_grid_step_km'] : (float)get_setting('exhaustive_grid_step_km','2');
    if(!is_finite($step) || $step<=0) $step = 2.0; if($step<0.2) $step=0.2; // sanity
    set_setting('exhaustive_grid_step_km', (string)$step);
  $maxPts = isset($_POST['exhaustive_max_points']) ? intval($_POST['exhaustive_max_points']) : intval(get_setting('exhaustive_max_points','1000'));
  if($maxPts<1) $maxPts=1; if($maxPts>10000) $maxPts=10000; // widened upper bound for broad scans
    set_setting('exhaustive_max_points', (string)$maxPts);
    // OSM Overpass limit
  $ov = isset($_POST['overpass_limit']) ? intval($_POST['overpass_limit']) : intval(get_setting('overpass_limit','1000'));
  if($ov<50) $ov=50; if($ov>10000) $ov=10000;
    set_setting('overpass_limit', (string)$ov);
    $msg='تم حفظ مزودات البيانات';
  }
}
$values=[
  'google_api_key'=>get_setting('google_api_key',''),
  'default_ll'=>get_setting('default_ll',''),
  'default_radius_km'=>get_setting('default_radius_km','25'),
  'default_language'=>get_setting('default_language','ar'),
  'default_region'=>get_setting('default_region','sa'),
  'whatsapp_message'=>get_setting('whatsapp_message','مرحبًا {name}، راسلنا بخصوص خدمتك.'),
  'washeej_url'=>get_setting('washeej_url','https://wa.washeej.com/api/qr/rest/send_message'),
  'washeej_token'=>get_setting('washeej_token',''),
  'washeej_sender'=>get_setting('washeej_sender',''),
  'washeej_use_per_agent'=>get_setting('washeej_use_per_agent','0'),
  'washeej_instance_id'=>get_setting('washeej_instance_id',''),
  'provider_order'=>get_setting('provider_order','osm,foursquare,mapbox,radar,google'),
  'foursquare_api_key'=>get_setting('foursquare_api_key',''),
  'mapbox_api_key'=>get_setting('mapbox_api_key',''),
  'radar_api_key'=>get_setting('radar_api_key',''),
  'tile_ttl_days'=>get_setting('tile_ttl_days','14'),
  'daily_details_cap'=>get_setting('daily_details_cap','1000'),
  'fetch_exhaustive'=>get_setting('fetch_exhaustive','0'),
  'exhaustive_grid_step_km'=>get_setting('exhaustive_grid_step_km','2'),
  'exhaustive_max_points'=>get_setting('exhaustive_max_points','400'),
  'overpass_limit'=>get_setting('overpass_limit','100'),
  'tile_sources_json'=>get_setting('tile_sources_json',''),
  'job_pick_order'=>get_setting('job_pick_order','fifo'),
  'worker_base_url'=>get_setting('worker_base_url',''),
  'worker_config_code'=>get_setting('worker_config_code',''),
  'worker_headless'=>get_setting('worker_headless','0'),
  'worker_max_pages'=>get_setting('worker_max_pages','5'),
  'worker_lease_sec'=>get_setting('worker_lease_sec','180'),
  'worker_report_batch_size'=>get_setting('worker_report_batch_size','10'),
  'worker_report_every_ms'=>get_setting('worker_report_every_ms','15000'),
  'worker_report_first_ms'=>get_setting('worker_report_first_ms','2000'),
  'worker_item_delay_ms'=>get_setting('worker_item_delay_ms','800'),
  'worker_chrome_exe'=>get_setting('worker_chrome_exe',''),
  'worker_chrome_args'=>get_setting('worker_chrome_args',''),
  'system_pause_enabled'=>get_setting('system_pause_enabled','0'),
  'system_pause_start'=>get_setting('system_pause_start','23:59'),
  'system_pause_end'=>get_setting('system_pause_end','09:00'),
  'system_global_stop'=>get_setting('system_global_stop','0'),
  'classify_enabled'=>get_setting('classify_enabled','1'),
  'classify_threshold'=>get_setting('classify_threshold','1.0'),
  'classify_w_kw_name'=>get_setting('classify_w_kw_name','2.0'),
  'classify_w_kw_types'=>get_setting('classify_w_kw_types','1.5'),
  'classify_w_name'=>get_setting('classify_w_name','1.0'),
  'classify_w_types'=>get_setting('classify_w_types','1.0'),
  'classify_w_website'=>get_setting('classify_w_website','1.0'),
  'classify_w_email'=>get_setting('classify_w_email','1.0'),
  'classify_w_source_url'=>get_setting('classify_w_source_url','1.0'),
  'classify_w_city'=>get_setting('classify_w_city','1.0'),
  'classify_w_country'=>get_setting('classify_w_country','1.0'),
  'classify_w_phone'=>get_setting('classify_w_phone','1.0'),
  'maintenance_secret'=>get_setting('maintenance_secret',''),
  'alert_webhook_url'=>get_setting('alert_webhook_url',''),
  'alert_email'=>get_setting('alert_email',''),
  'alert_slack_token'=>get_setting('alert_slack_token',''),
  'alert_slack_channel'=>get_setting('alert_slack_channel',''),
  'reclassify_default_limit'=>get_setting('reclassify_default_limit','200'),
  'reclassify_only_empty'=>get_setting('reclassify_only_empty','1'),
  'reclassify_override'=>get_setting('reclassify_override','0'),
];
?>
<div class="card">
  <div class="tabs">
    <a class="<?php echo $tab==='general'?'active':''; ?>" href="?tab=general">عام</a>
    <a class="<?php echo $tab==='providers'?'active':''; ?>" href="?tab=providers">مزودات البيانات</a>
  </div>
  <h2>إعدادات النظام</h2>
  <?php
    // Weak admin password health banner (non-blocking)
    try{
      $pdo = db();
      $admin = $pdo->query("SELECT id,mobile,password_hash FROM users WHERE role='admin' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
      $weak = false; $why = '';
      if($admin){
        $cands = ['admin','admin123','123456','12345678','password','000000','111111'];
        foreach($cands as $pw){ if(password_verify($pw, $admin['password_hash'])){ $weak=true; $why='كلمة مرور المشرف ضعيفة/افتراضية'; break; } }
        // Heuristic: if hash is null/empty or very old phpBB-like $H$ style
        if(!$weak && (!$admin['password_hash'] || strlen($admin['password_hash'])<20 || str_starts_with((string)$admin['password_hash'], '$P$') || str_starts_with((string)$admin['password_hash'], '$H$'))){
          $weak = true; $why = 'تنسيق تجزئة قديم/غير آمن أو كلمة مرور غير مضبوطة';
        }
      }
      if($weak){
        echo '<div class="banner danger" style="margin:8px 0">⚠️ توصية أمنية: '.$why.' — يُرجى تغيير كلمة المرور الآن من صفحة المستخدمين.</div>';
      }
    }catch(Throwable $e){}
  ?>
  <div class="muted" style="margin:6px 0 12px">
    ملاحظة العلامة التجارية: يمكن تخصيص اسم المنتج والشعارات عبر مفاتيح الإعدادات التالية (من صفحة قاعدة البيانات/الإعدادات أو سكربت ترحيل):
    <code>brand_name</code>، <code>brand_tagline_ar</code>، <code>brand_tagline_en</code>.
    إذا لم تُضبط، سيُستخدم اسم افتراضي (OptForge) مع شعارات افتراضية، وقد تتراجع بعض الصفحات إلى <code>product_name</code> (سلوك قديم) إن وُجد.
  </div>
  <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:6px 0">
    <?php $globStopped = get_setting('system_global_stop','0')==='1'; ?>
    <span class="badge" style="background:<?php echo $globStopped?'#7f1d1d':'#14532d'; ?>">الحالة العامة: <?php echo $globStopped? 'مُتوقّف (Global Stop)':'يعمل'; ?></span>
    <form method="post" style="display:inline" onsubmit="return confirm('تأكيد الإيقاف العام؟');">
      <?php echo csrf_input(); ?><input type="hidden" name="system_global_stop" value="1">
      <button class="btn danger" <?php echo $globStopped?'disabled':''; ?>>إيقاف الكل</button>
    </form>
      <form method="post" style="display:inline" onsubmit="return confirm('تشغيل جميع الوحدات؟');">
        <?php echo csrf_input(); ?>
        <input type="hidden" name="action" value="toggle_global">
        <input type="hidden" name="value" value="0">
        <button class="btn success" <?php echo $globStopped?'':'disabled'; ?>>تشغيل الكل</button>
      </form>
  </div>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <?php if($msg): ?><p class="badge"><?php echo htmlspecialchars($msg); ?></p><?php endif; ?>
    <?php $seedJustPosted = ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='seed_full'); ?>
    <?php if($tab==='general'): ?>
      <button type="button" id="btn_open_seed" class="btn blue sm">تهيئة سريعة</button>
    <?php endif; ?>
  </div>
  <?php if($tab==='general'): ?>
  <form method="post" class="grid-3">
    <?php echo csrf_input(); ?>
    <input type="hidden" name="action" value="settings_update" />
    <div><label>Google Maps API Key</label><input name="google_api_key" value="<?php echo htmlspecialchars($values['google_api_key']); ?>"></div>
    <div><label>LL (lat,lng)</label><input name="default_ll" value="<?php echo htmlspecialchars($values['default_ll']); ?>" placeholder="24.7136,46.6753"></div>
    <div><label>Radius (km)</label><input type="number" name="default_radius_km" value="<?php echo htmlspecialchars($values['default_radius_km']); ?>"></div>
    <div><label>Language</label><input name="default_language" value="<?php echo htmlspecialchars($values['default_language']); ?>"></div>
    <div><label>Region</label><input name="default_region" value="<?php echo htmlspecialchars($values['default_region']); ?>"></div>
    <div style="grid-column:1/-1"><label>WhatsApp Message Template (Global)</label><input name="whatsapp_message" value="<?php echo htmlspecialchars($values['whatsapp_message']); ?>"></div>
    <div><label>Washeej URL</label><input name="washeej_url" value="<?php echo htmlspecialchars($values['washeej_url']); ?>"></div>
    <div><label>Washeej Global Token</label><input name="washeej_token" value="<?php echo htmlspecialchars($values['washeej_token']); ?>"></div>
    <div><label>رقم المُرسِل (Global)</label><input name="washeej_sender" value="<?php echo htmlspecialchars($values['washeej_sender']); ?>" placeholder="9665XXXXXXXX"></div>
    <div><label>Instance ID (اختياري)</label><input name="washeej_instance_id" value="<?php echo htmlspecialchars($values['washeej_instance_id']); ?>"></div>
  <div style="grid-column:1/-1;display:flex;align-items:center;gap:10px"><label style="margin:0"><input class="ui-switch" type="checkbox" name="washeej_use_per_agent" <?php echo $values['washeej_use_per_agent']==='1'?'checked':''; ?>></label><span>استخدام مفتاح/مرسل خاص بكل مندوب</span></div>

  <div style="grid-column:1/-1"><hr style="border-color:var(--border)"></div>
  <div style="grid-column:1/-1;display:flex;align-items:center;gap:10px"><label style="margin:0"><input class="ui-switch" type="checkbox" name="internal_server_enabled" <?php echo get_setting('internal_server_enabled','0')==='1'?'checked':''; ?>></label><span>تفعيل "السيرفر الداخلي" (إيقاف جميع المزوّدات وAPI، وإرسال الطلبات إلى قائمة انتظار داخلية)</span></div>
    <div><label>Internal Secret (للوحدة الطرفية)</label><input name="internal_secret" value="<?php echo htmlspecialchars(get_setting('internal_secret','')); ?>" placeholder="يُفضّل سلسلة عشوائية طويلة"></div>
    <div><label>زمن السحب (ثوان)</label><input type="number" name="worker_pull_interval_sec" value="<?php echo htmlspecialchars(get_setting('worker_pull_interval_sec','30')); ?>"></div>
  <div style="grid-column:1/-1"><hr style="border-color:var(--border)"></div>
  <div style="grid-column:1/-1"><strong>الصحة والمراقبة</strong></div>
  <div><label>عتبة اعتبار المهمة عالقة (دقائق)</label><input type="number" min="5" max="180" name="stuck_processing_threshold_min" value="<?php echo htmlspecialchars(get_setting('stuck_processing_threshold_min','10')); ?>"> <small class="muted">لا تُعتبر المهمة عالقة إلا إذا كان العامل متصلاً ولم يُحدّث التقدم منذ هذه الدقائق</small></div>
  <div style="grid-column:1/-1;display:flex;align-items:center;gap:10px"><label style="margin:0"><input class="ui-switch" type="checkbox" name="enable_self_update" <?php echo get_setting('enable_self_update','0')==='1'?'checked':''; ?>></label><span>تفعيل التحديث الذاتي (على الخادم فقط)</span></div>
  <div><label>Worker Base URL</label><input name="worker_base_url" value="<?php echo htmlspecialchars($values['worker_base_url']); ?>" placeholder="مثال: https://your-domain/LeadsMembershipPRO"></div>
  <div><label>Worker Config Code</label><input name="worker_config_code" value="<?php echo htmlspecialchars($values['worker_config_code']); ?>" placeholder="رمز مشاركة الإعدادات (اختياري)"></div>
  <div style="grid-column:1/-1;display:flex;align-items:center;gap:10px"><label style="margin:0"><input class="ui-switch" type="checkbox" name="worker_headless" <?php echo $values['worker_headless']==='1'?'checked':''; ?>></label><span>تشغيل بدون واجهة (HEADLESS)</span></div>
  <div><label>MAX_PAGES</label><input type="number" name="worker_max_pages" value="<?php echo htmlspecialchars($values['worker_max_pages']); ?>" min="1" max="20"></div>
  <div><label>LEASE_SEC</label><input type="number" name="worker_lease_sec" value="<?php echo htmlspecialchars($values['worker_lease_sec']); ?>" min="60" max="600"></div>
  <div><label>REPORT_BATCH_SIZE</label><input type="number" name="worker_report_batch_size" value="<?php echo htmlspecialchars($values['worker_report_batch_size']); ?>" min="1" max="100"></div>
  <div><label>REPORT_EVERY_MS</label><input type="number" name="worker_report_every_ms" value="<?php echo htmlspecialchars($values['worker_report_every_ms']); ?>" min="1000" step="1000"></div>
  <div><label>REPORT_FIRST_MS</label><input type="number" name="worker_report_first_ms" value="<?php echo htmlspecialchars($values['worker_report_first_ms']); ?>" min="200" step="100"></div>
  <div><label>ITEM_DELAY_MS</label><input type="number" name="worker_item_delay_ms" value="<?php echo htmlspecialchars($values['worker_item_delay_ms']); ?>" min="0" step="50"></div>
  <div><label>CHROME_EXE (اختياري)</label><input name="worker_chrome_exe" value="<?php echo htmlspecialchars($values['worker_chrome_exe']); ?>" placeholder="مسار Chrome إن رغبت باستخدام المثبت على النظام"></div>
  <div><label>CHROME_ARGS (اختياري)</label><input name="worker_chrome_args" value="<?php echo htmlspecialchars($values['worker_chrome_args']); ?>" placeholder="سطر معاملات إضافية للمتصفح"></div>
    <div><label>ترتيب سحب المهام</label>
      <select name="job_pick_order">
        <option value="fifo" <?php echo $values['job_pick_order']==='fifo'?'selected':''; ?>>FIFO (الأقدم أولاً)</option>
        <option value="newest" <?php echo $values['job_pick_order']==='newest'?'selected':''; ?>>الأحدث أولاً</option>
        <option value="random" <?php echo $values['job_pick_order']==='random'?'selected':''; ?>>عشوائي</option>
        <option value="pow2" <?php echo $values['job_pick_order']==='pow2'?'selected':''; ?>>Power-of-two (توازن ذكي)</option>
        <option value="rr_agent" <?php echo $values['job_pick_order']==='rr_agent'?'selected':''; ?>>Round-robin حسب المندوب</option>
        <option value="fair_query" <?php echo $values['job_pick_order']==='fair_query'?'selected':''; ?>>Fairness by Query (عدالة حسب الاستعلام)</option>
      </select>
      <small class="muted">pow2 يختار مرشحين عشوائياً ثم الأقل محاولات/الأقدم. rr_agent يدوّر بين agent_id المتاحين. fair_query يقلّل الاحتكار بين الاستعلامات عبر تفضيل الأقل نشاطاً خلال 24 ساعة.</small>
    </div>

  <div style="grid-column:1/-1"><hr style="border-color:var(--border)"></div>
  <div style="grid-column:1/-1"><strong>التصنيف</strong></div>
  <div style="display:flex;align-items:center;gap:10px"><label style="margin:0"><input class="ui-switch" type="checkbox" name="classify_enabled" <?php echo $values['classify_enabled']==='1'?'checked':''; ?>></label><span>تفعيل التصنيف التلقائي</span></div>
  <div><label>الحد الأدنى للنقاط (Threshold)</label><input type="number" step="0.1" name="classify_threshold" value="<?php echo htmlspecialchars($values['classify_threshold']); ?>"> <small class="muted">لن تُسند فئة إذا كان مجموع النقاط أقل من هذا الحد</small></div>
  <div style="grid-column:1/-1" class="muted">أوزان عامة تُضاعف وزن القاعدة (rules) أو تُستخدم للكلمات المفتاحية (keywords). اضبطها لضبط حساسية كل حقل.</div>
  <div style="grid-column:1/-1;display:flex;gap:8px;align-items:center">
    <label style="margin:0">Preset</label>
    <select id="cls_preset">
      <option value="balanced">Balanced (افتراضي)</option>
      <option value="kw_aggr">Aggressive Keywords</option>
      <option value="name_focus">Name Focus</option>
      <option value="strict_rules">Strict Rules</option>
    </select>
    <button type="button" class="btn" id="btn_apply_preset">تطبيق</button>
    <button type="button" class="btn" id="btn_reset_defaults">إعادة الضبط للقيم الافتراضية</button>
  </div>
  <div><label>وزن كلمات الاسم (kw-name)</label><input type="number" step="0.1" name="classify_w_kw_name" title="وزن الكلمات المفتاحية التي تطابق الاسم مباشرة" value="<?php echo htmlspecialchars($values['classify_w_kw_name']); ?>"></div>
  <div><label>وزن كلمات Place Types (kw-types)</label><input type="number" step="0.1" name="classify_w_kw_types" title="وزن الكلمات المفتاحية لأنواع Google المرافقة" value="<?php echo htmlspecialchars($values['classify_w_kw_types']); ?>"><br><small class="muted">تستخدم عند مطابقة أنواع Google (مثل: Beauty salon)</small></div>
  <details class="collapsible" style="grid-column:1/-1">
    <summary>أوزان متقدمة للتصنيف</summary>
    <div class="collapsible-body grid-3">
      <div class="collapsible-help">تعديل الأوزان يؤثر على حساسية التصنيف. استخدمها فقط عند الحاجة.</div>
      <div><label>Multiplier — name</label><input type="number" step="0.1" name="classify_w_name" title="معامل مضروب لنتائج القواعد التي تستهدف الاسم" value="<?php echo htmlspecialchars($values['classify_w_name']); ?>"><br><small class="muted">يضرب وزن القواعد التي تستهدف الاسم</small></div>
      <div><label>Multiplier — types</label><input type="number" step="0.1" name="classify_w_types" title="معامل مضروب لقواعد الأنواع" value="<?php echo htmlspecialchars($values['classify_w_types']); ?>"><br><small class="muted">يضرب وزن القواعد التي تستهدف Place Types</small></div>
      <div><label>Multiplier — website</label><input type="number" step="0.1" name="classify_w_website" title="معامل مضروب لقواعد الموقع" value="<?php echo htmlspecialchars($values['classify_w_website']); ?>"><br><small class="muted">يضرب وزن القواعد التي تستهدف الموقع</small></div>
      <div><label>Multiplier — email</label><input type="number" step="0.1" name="classify_w_email" title="معامل مضروب لقواعد البريد" value="<?php echo htmlspecialchars($values['classify_w_email']); ?>"><br><small class="muted">يضرب وزن القواعد التي تستهدف البريد</small></div>
      <div><label>Multiplier — source_url</label><input type="number" step="0.1" name="classify_w_source_url" title="معامل مضروب لقواعد رابط المصدر" value="<?php echo htmlspecialchars($values['classify_w_source_url']); ?>"><br><small class="muted">يضرب وزن القواعد التي تستهدف رابط المصدر</small></div>
      <div><label>Multiplier — city</label><input type="number" step="0.1" name="classify_w_city" title="معامل مضروب لقواعد المدينة" value="<?php echo htmlspecialchars($values['classify_w_city']); ?>"><br><small class="muted">يضرب وزن القواعد التي تستهدف المدينة</small></div>
      <div><label>Multiplier — country</label><input type="number" step="0.1" name="classify_w_country" title="معامل مضروب لقواعد الدولة" value="<?php echo htmlspecialchars($values['classify_w_country']); ?>"><br><small class="muted">يضرب وزن القواعد التي تستهدف الدولة</small></div>
      <div><label>Multiplier — phone</label><input type="number" step="0.1" name="classify_w_phone" title="معامل مضروب لقواعد الهاتف" value="<?php echo htmlspecialchars($values['classify_w_phone']); ?>"><br><small class="muted">يضرب وزن القواعد التي تستهدف الهاتف</small></div>
    </div>
  </details>

  <details class="collapsible" style="grid-column:1/-1">
    <summary>إعادة تصنيف سريعة</summary>
    <div class="collapsible-body grid-3">
      <div class="collapsible-help">أداة سريعة للتشغيل اليدوي. للأدوات الكاملة انتقل إلى صفحة قواعد التصنيف.</div>
      <div><label>الحد لكل دفعة</label><input id="qrc-limit" type="number" value="<?php echo htmlspecialchars(get_setting('reclassify_default_limit','200')); ?>" min="10" max="2000"></div>
      <div style="display:flex;align-items:center;gap:10px"><label style="margin:0"><input class="ui-switch" id="qrc-only-empty" type="checkbox" <?php echo get_setting('reclassify_only_empty','1')==='1'?'checked':''; ?>></label><span>فقط الذين بلا تصنيف</span></div>
      <div style="display:flex;align-items:center;gap:10px"><label style="margin:0"><input class="ui-switch" id="qrc-override" type="checkbox" <?php echo get_setting('reclassify_override','0')==='1'?'checked':''; ?>></label><span>تجاوز التصنيف الموجود</span></div>
      <div style="grid-column:1/-1;display:flex;gap:8px;align-items:center">
        <button type="button" class="btn" id="btn_reclassify_quick"><i class="fa fa-magic"></i> تشغيل الآن</button>
        <span id="qrc-status" class="muted"></span>
      </div>
    </div>
  </details>

  <details class="collapsible" style="grid-column:1/-1">
    <summary>التحكم بالتوقف والصيانة اليومية</summary>
    <div class="collapsible-body grid-3">
      <div style="display:flex;align-items:center;gap:10px"><label style="margin:0"><input class="ui-switch" type="checkbox" name="system_pause_enabled" <?php echo $values['system_pause_enabled']==='1'?'checked':''; ?>></label><span>تفعيل فترة الإيقاف اليومية</span></div>
      <div><label>بداية الإيقاف</label><input name="system_pause_start" value="<?php echo htmlspecialchars($values['system_pause_start']); ?>" placeholder="23:59"></div>
      <div><label>نهاية الإيقاف</label><input name="system_pause_end" value="<?php echo htmlspecialchars($values['system_pause_end']); ?>" placeholder="09:00"></div>
      <div style="grid-column:1/-1;display:flex;align-items:center;gap:10px"><label style="margin:0"><input class="ui-switch" type="checkbox" name="system_global_stop" <?php echo $values['system_global_stop']==='1'?'checked':''; ?>></label><span>إيقاف فوري شامل للنظام (يمكن تشغيله لاحقًا)</span></div>
    </div>
  </details>

  <div style="grid-column:1/-1"><hr style="border-color:var(--border)"></div>
  <div style="grid-column:1/-1"><strong>الصيانة والجدولة</strong></div>
  <div><label>Maintenance Secret</label><input name="maintenance_secret" value="<?php echo htmlspecialchars($values['maintenance_secret']); ?>" placeholder="سلسلة آمنة طويلة"></div>
  <div><label>Reclassify Default Limit</label><input type="number" name="reclassify_default_limit" value="<?php echo htmlspecialchars($values['reclassify_default_limit']); ?>" min="1"></div>
  <div style="display:flex;align-items:center;gap:10px"><label style="margin:0"><input class="ui-switch" type="checkbox" name="reclassify_only_empty" <?php echo $values['reclassify_only_empty']==='1'?'checked':''; ?>></label><span>فقط الذين بلا تصنيف (افتراضي)</span></div>
  <div style="display:flex;align-items:center;gap:10px"><label style="margin:0"><input class="ui-switch" type="checkbox" name="reclassify_override" <?php echo $values['reclassify_override']==='1'?'checked':''; ?>></label><span>تجاوز التصنيف الموجود (افتراضي)</span></div>
  <div style="grid-column:1/-1"><hr style="border-color:var(--border)"></div>
  <div style="grid-column:1/-1"><strong>التنبيهات (Alerts)</strong> <span class="muted">يُنصح بضبط واحد على الأقل</span></div>
  <div><label>Alert Webhook URL</label><input name="alert_webhook_url" value="<?php echo htmlspecialchars($values['alert_webhook_url']); ?>" placeholder="مثال: https://hooks.example.com/endpoint"></div>
  <div><label>Alert Email</label><input name="alert_email" value="<?php echo htmlspecialchars($values['alert_email']); ?>" placeholder="ops@example.com"></div>
  <div><label>Slack Token</label><input type="password" name="alert_slack_token" value="<?php echo htmlspecialchars($values['alert_slack_token']); ?>" placeholder="xapp-… أو xoxe-… (لا تُسرب)"></div>
  <div><label>Slack Channel</label><input name="alert_slack_channel" value="<?php echo htmlspecialchars($values['alert_slack_channel']); ?>" placeholder="#alerts أو ID مثل C0123456789"></div>
  <div class="muted" style="grid-column:1/-1">ستتلقى تنبيهات عند: عمّال غير متصلين، عناصر في DLQ، وظائف عالقة. لتفعيل الجدولة على ويندوز استخدم سكربت <code>tools/ops/schedule_alerts.ps1</code>.</div>

    <div style="grid-column:1/-1"><button class="btn primary">حفظ</button></div>
  </form>
  <div class="card" style="margin-top:16px">
    <h3>تحديث النظام (تجريبي)</h3>
  <div class="muted">يعرض وجود نسخة أحدث في مجلد <code>releases/</code>. يعمل فقط على الخادم (ليس محلياً) وعند تفعيل خيار self-update.</div>
    <?php
      $curVer = get_setting('app_version','');
      $relDir = __DIR__ . '/../releases';
      $webRelPath = 'releases';
      if (!is_dir($relDir)) { $relDir = __DIR__ . '/../storage/releases'; $webRelPath = 'storage/releases'; }
      $latest = null; $latestTs = null;
      if (is_dir($relDir)){
        $list = glob($relDir . DIRECTORY_SEPARATOR . 'site-*.zip');
        if($list){ usort($list, function($a,$b){ return strcmp(basename($b),basename($a)); }); $latest = basename($list[0]); if(preg_match('/site-(\d{8}_\d{6})\.zip$/',$latest,$mm)) $latestTs=$mm[1]; }
      }
      $enabledUpdate = get_setting('enable_self_update','0')==='1';
    ?>
    <div class="badge">الإصدار الحالي: <b><?php echo htmlspecialchars($curVer ?: 'غير محدد'); ?></b> — الأحدث: <b><?php echo htmlspecialchars($latestTs ?: 'لا يوجد'); ?></b></div>
    <div style="display:flex;gap:10px;align-items:center">
  <a class="btn" href="<?php echo linkTo($webRelPath); ?>" target="_blank" rel="noopener">فتح مجلد الإصدارات</a>
      <?php if($enabledUpdate && $latestTs && $latestTs!==$curVer): ?>
        <button type="button" class="btn primary" id="btn_self_update">تحديث الآن</button>
      <?php else: ?>
        <button type="button" class="btn" disabled>لا يوجد تحديث متاح</button>
      <?php endif; ?>
    </div>
    <small class="muted">لتفعيل هذه الميزة اضبط <b>enable_self_update</b>=1 في الإعدادات.</small>
    <pre id="su_out" class="muted" style="white-space:pre-wrap"></pre>
  </div>
  <!-- Modal: تهيئة سريعة للنظام -->
  <div class="modal-backdrop" id="seedModal">
    <div class="modal">
      <div class="modal-header">
        <h3>تهيئة سريعة للنظام</h3>
        <button class="modal-close" data-close title="إغلاق">✕</button>
      </div>
      <form method="post" class="grid-3" onsubmit="return confirm('ستُطبَّق إعدادات وبيانات افتراضية وتجريبية وفق الإختيارات. متابعة؟');">
        <?php echo csrf_input(); ?>
        <input type="hidden" name="action" value="seed_full" />
        <div style="grid-column:1/-1;display:flex;flex-wrap:wrap;gap:14px 18px;align-items:center">
          <label style="margin:0;display:flex;align-items:center;gap:8px"><input class="ui-switch" type="checkbox" name="seed_cls_full" checked><span>استيراد الحزمة الشاملة للتصنيف</span></label>
          <label style="margin:0;display:flex;align-items:center;gap:8px"><input class="ui-switch" type="checkbox" name="seed_cls_replace" checked><span>استبدال كامل للكلمات والقواعد</span></label>
        </div>
        <div><label style="display:flex;align-items:center;gap:8px"><input class="ui-switch" type="checkbox" name="seed_users" checked><span>إنشاء مندوبيْن تجريبييْن</span></label></div>
        <div><label style="display:flex;align-items:center;gap:8px"><input class="ui-switch" type="checkbox" name="enable_internal" checked><span>تفعيل السيرفر الداخلي وضبط السر</span></label></div>
        <div><label style="display:flex;align-items:center;gap:8px"><input class="ui-switch" type="checkbox" name="seed_jobs" checked><span>إضافة وظائف داخلية تجريبية (Queued)</span></label></div>
        <div><label style="display:flex;align-items:center;gap:8px"><input class="ui-switch" type="checkbox" name="seed_leads"><span>إدراج أمثلة لأرقام عملاء قليلة</span></label></div>
        <div style="grid-column:1/-1;display:flex;gap:8px;justify-content:flex-end">
          <button type="button" class="btn outline" data-close>إلغاء</button>
          <button class="btn blue"><i class="fa fa-wand-magic-sparkles"></i> تهيئة الآن</button>
        </div>
        <small class="muted" style="grid-column:1/-1">ملاحظة: لا يتم حذف الأقسام عند الاستبدال، فقط الكلمات والقواعد. بقية الخيارات لا تستبدل القيم الموجودة؛ تُنشئ المفقود فقط حيثما أمكن.</small>
      </form>
    </div>
  <script nonce="<?php echo htmlspecialchars(csp_nonce()); ?>">
      (function(){
        const modal = document.getElementById('seedModal');
        const openBtn = document.getElementById('btn_open_seed');
        function open(){ modal.classList.add('show'); }
        function close(){ modal.classList.remove('show'); }
        if(openBtn) openBtn.addEventListener('click', open);
        modal.addEventListener('click', (e)=>{ if(e.target===modal) close(); });
        modal.querySelectorAll('[data-close]').forEach(el=> el.addEventListener('click', close));
        <?php if($seedJustPosted): ?> open(); <?php endif; ?>
      })();
    </script>
  </div>
  <?php else: ?>
  <form method="post" class="grid-3">
    <?php echo csrf_input(); ?>
    <div style="grid-column:1/-1"><label>ترتيب المزودات (من اليسار للأولوية العليا)</label>
      <input name="provider_order" value="<?php echo htmlspecialchars($values['provider_order']); ?>" placeholder="osm,foursquare,mapbox,radar,google">
      <small class="muted">القيم: osm, foursquare, mapbox, radar, google</small>
    </div>
  <div><label>Foursquare API Key</label><input name="foursquare_api_key" value="<?php echo htmlspecialchars($values['foursquare_api_key']); ?>"><br><small class="muted"><a href="https://location.foursquare.com/developer/" target="_blank" rel="noopener">فورسكوير — إنشاء مفتاح</a></small></div>
  <div><label>Mapbox Access Token</label><input name="mapbox_api_key" value="<?php echo htmlspecialchars($values['mapbox_api_key']); ?>"><br><small class="muted"><a href="https://account.mapbox.com/access-tokens/" target="_blank" rel="noopener">Mapbox — Access Tokens</a></small></div>
  <div><label>Radar Secret Key</label><input name="radar_api_key" value="<?php echo htmlspecialchars($values['radar_api_key']); ?>"><br><small class="muted"><a href="https://radar.com/documentation/api" target="_blank" rel="noopener">Radar.io — API Docs</a></small></div>
    <div><label>Tile TTL Days</label><input type="number" name="tile_ttl_days" value="<?php echo htmlspecialchars($values['tile_ttl_days']); ?>"></div>
    <div><label>سقف يومي لـ Google Details</label><input type="number" name="daily_details_cap" value="<?php echo htmlspecialchars($values['daily_details_cap']); ?>"></div>
  <div class="badge" style="grid-column:1/-1">المتبقي اليوم من Google Details: <?php echo cap_remaining_google_details(); ?></div>
  <div style="grid-column:1/-1"><hr style="border-color:#11233d"></div>
  <div style="grid-column:1/-1"><strong>وضع الجلب المكثّف (Exhaustive)</strong></div>
  <div style="grid-column:1/-1;display:flex;align-items:center;gap:10px"><label style="margin:0"><input class="ui-switch" type="checkbox" name="fetch_exhaustive" <?php echo $values['fetch_exhaustive']==='1'?'checked':''; ?>></label><span>تفعيل الجلب عبر شبكة نقاط ضمن نصف القطر (قد يستهلك وقتاً/طلبات أكثر)</span></div>
  <div><label>Grid Step (كم)</label><input type="number" step="0.1" min="0.2" name="exhaustive_grid_step_km" value="<?php echo htmlspecialchars($values['exhaustive_grid_step_km']); ?>"> <small class="muted">المسافة بين النقاط</small></div>
  <div><label>أقصى عدد نقاط</label><input type="number" min="1" max="5000" name="exhaustive_max_points" value="<?php echo htmlspecialchars($values['exhaustive_max_points']); ?>"></div>
  <div><label>حد Overpass (OSM)</label><input type="number" min="50" max="1000" name="overpass_limit" value="<?php echo htmlspecialchars($values['overpass_limit']); ?>"><br><small class="muted">طلبات OSM تُحدّ بـ 50–1000 عنصر لكل استعلام</small></div>
  <details class="collapsible" style="grid-column:1/-1">
    <summary>مصادر خرائط Leaflet المخصصة (اختياري)</summary>
    <div class="collapsible-body">
      <div class="collapsible-help">يمكن تحديد قائمة بالمصادر البديلة لطبقة الخرائط على صفحات الجلب عند تعذّر الوصول إلى مصدر افتراضي. الصيغة: JSON Array من عناصر بشكل { url, att }، مثلاً: [{"url":"https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png","att":"&copy; OpenStreetMap contributors"}]</div>
      <label>tile_sources_json</label>
      <textarea name="tile_sources_json" rows="6" placeholder='[{"url":"https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png","att":"&copy; OpenStreetMap contributors"}]'><?php echo htmlspecialchars($values['tile_sources_json']); ?></textarea>
    </div>
  </details>
  <div style="grid-column:1/-1"><button class="btn primary">حفظ</button></div>
  </form>
  <?php endif; ?>
</div>
<script nonce="<?php echo htmlspecialchars(csp_nonce()); ?>">
// Classification weight presets (client-side convenience)
(function(){
  const $ = sel => document.querySelector(sel);
  const fields = ['classify_w_kw_name','classify_w_kw_types','classify_w_name','classify_w_types','classify_w_website','classify_w_email','classify_w_source_url','classify_w_city','classify_w_country','classify_w_phone'];
  const get = n => $(`input[name="${n}"]`);
  const apply = map => { fields.forEach(k => { if(map[k]!==undefined && get(k)) get(k).value = map[k]; }); };

  const defaults = { classify_w_kw_name:2.0, classify_w_kw_types:1.5, classify_w_name:1.0, classify_w_types:1.0, classify_w_website:1.0, classify_w_email:1.0, classify_w_source_url:1.0, classify_w_city:1.0, classify_w_country:1.0, classify_w_phone:1.0 };
  const presets = {
    balanced: { ...defaults },
    kw_aggr: { ...defaults, classify_w_kw_name:3.0, classify_w_kw_types:2.2 },
    name_focus: { ...defaults, classify_w_name:1.6, classify_w_kw_name:2.6 },
    strict_rules: { ...defaults, classify_w_kw_name:1.2, classify_w_kw_types:1.0, classify_w_name:1.8, classify_w_types:1.6 }
  };
  const sel = $('#cls_preset');
  const btnApply = $('#btn_apply_preset');
  const btnReset = $('#btn_reset_defaults');
  if(btnApply){ btnApply.addEventListener('click', ()=>{ const p = sel && sel.value || 'balanced'; apply(presets[p]||presets.balanced); }); }
  if(btnReset){ btnReset.addEventListener('click', ()=> apply(defaults)); }
})();
// Quick reclassify (single batch)
(function(){
  const btn = document.getElementById('btn_reclassify_quick');
  const status = document.getElementById('qrc-status');
  if(!btn) return;
  btn.addEventListener('click', async function(){
    status.textContent = 'جارٍ...';
    try{
      const payload = {
        csrf: '<?php echo htmlspecialchars(csrf_token()); ?>',
        limit: parseInt((document.getElementById('qrc-limit')?.value)||'200') || 200,
        only_empty: !!(document.getElementById('qrc-only-empty')?.checked),
        override: !!(document.getElementById('qrc-override')?.checked)
      };
      const res = await fetch('<?php echo linkTo('api/reclassify.php'); ?>', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) });
      const j = await res.json();
      if(!j.ok) throw new Error('failed');
      const remainStr = (j.remaining==null) ? '' : (' — المتبقي بلا تصنيف: '+j.remaining);
      status.textContent = `تمت معالجة ${j.processed||0}, تحديث ${j.updated||0}, تجاوز ${j.skipped||0}${remainStr}`;
    }catch(e){ status.textContent='فشل الإجراء'; }
  });
})();
</script>
<script nonce="<?php echo htmlspecialchars(csp_nonce()); ?>">
// Self-update trigger
(function(){
  const btn = document.getElementById('btn_self_update');
  const out = document.getElementById('su_out');
  if(!btn || !out) return;
  btn.addEventListener('click', async function(){
    btn.disabled = true; out.textContent = 'جارٍ التحديث...';
    try{
      const res = await fetch('<?php echo linkTo('api/self_update.php'); ?>', { method:'POST', headers:{'X-Requested-With':'fetch'}, body: new URLSearchParams({csrf:'<?php echo htmlspecialchars(csrf_token()); ?>'}) });
      const j = await res.json();
      if(!j.ok){ out.textContent = 'فشل: ' + (j.error||'unknown'); btn.disabled=false; return; }
      out.textContent = 'تم التحديث إلى الإصدار: ' + (j.version||'');
      setTimeout(()=> location.reload(), 1200);
    }catch(e){ out.textContent = 'فشل الاتصال بالخادم'; btn.disabled=false; }
  });
})();
</script>
<?php include __DIR__ . '/../layout_footer.php'; ?>
