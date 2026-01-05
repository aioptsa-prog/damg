<?php include __DIR__ . '/../layout_header.php'; require_once __DIR__ . '/../lib/providers.php'; require_once __DIR__ . '/../lib/system.php'; require_once __DIR__ . '/../lib/categories.php'; $u=require_role('admin'); $pdo=db();
$msg=null;$warn=null;$added=0;$summary=null;
// Preload categories for UI (flat list with path if available)
$catRows = [];
try{ $catRows = $pdo->query("SELECT id, name, COALESCE(path,name) AS path, COALESCE(depth,0) AS depth, COALESCE(is_active,1) AS active FROM categories ORDER BY COALESCE(path,name) ASC")->fetchAll(PDO::FETCH_ASSOC); }catch(Throwable $e){ $catRows=[]; }
// Preload per-category counts for keywords and templates to project expansion counts on the client
$kwCounts = []; $tplCounts = [];
try{
  $r = $pdo->query("SELECT category_id, COUNT(*) cnt FROM category_keywords GROUP BY category_id")->fetchAll(PDO::FETCH_ASSOC);
  foreach($r as $row){ $kwCounts[(int)$row['category_id']] = (int)$row['cnt']; }
}catch(Throwable $e){}
try{
  $r = $pdo->query("SELECT category_id, COUNT(*) cnt FROM category_query_templates GROUP BY category_id")->fetchAll(PDO::FETCH_ASSOC);
  foreach($r as $row){ $tplCounts[(int)$row['category_id']] = (int)$row['cnt']; }
}catch(Throwable $e){}

if($_SERVER['REQUEST_METHOD']==='POST'){
  if(system_is_globally_stopped() || system_is_in_pause_window()){ $warn='النظام متوقف مؤقتًا بقرار المدير. برجاء المحاولة لاحقًا.'; $_POST=[]; }
  if(!csrf_verify($_POST['csrf'] ?? '')){ $warn='CSRF فشل التحقق'; $_POST=[]; }
  $key = get_setting('google_api_key',''); $q = trim($_POST['q'] ?? '');
  $ll = trim($_POST['ll'] ?? get_setting('default_ll','')); $radius_km = max(1, intval($_POST['radius_km'] ?? get_setting('default_radius_km','25')));
  $city_hint = trim($_POST['city'] ?? '');
  $lang = get_setting('default_language','ar'); $region = get_setting('default_region','sa');
  $preview = isset($_POST['preview_only']); $internal = get_setting('internal_server_enabled','0')==='1';
  $category_id = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;
  $multi_search = !empty($_POST['multi_search']);
  if($q===''){ $warn='أدخل الاستعلام'; }
  else if(!preg_match('/^-?\d+(?:\.\d+)?,\s*-?\d+(?:\.\d+)?$/', $ll)){ $warn='صيغة LL غير صحيحة. مثال: 24.7136,46.6753'; }
  else if($category_id<=0){ $warn='يجب اختيار تصنيف'; }
  else{
    // Validate lat/lng numeric bounds and radius range; normalize LL formatting
    $parts = array_map('trim', explode(',', $ll));
    $lat = (float)$parts[0]; $lng = (float)$parts[1];
    if($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180){
      $warn = 'إحداثيات خارج النطاق المسموح (lat: -90..90, lng: -180..180)';
    }
    $radius_km = max(1, min(100, (int)$radius_km));
    // Normalize LL to 6 decimals for consistency
    $ll = sprintf('%.6f,%.6f', $lat, $lng);
  }
  if(!$warn){
    if($internal){
      $target = isset($_POST['target_count']) && $_POST['target_count']!=='' ? max(1, intval($_POST['target_count'])) : null;
      // Build multi-variant queries list
      $queries = [];
      if($q!==''){ $queries[] = ['text'=>$q,'source'=>'user']; }
      if($multi_search){
        try{
          $stC = $pdo->prepare("SELECT name FROM categories WHERE id=?"); $stC->execute([$category_id]);
          $cRow = $stC->fetch(PDO::FETCH_ASSOC);
          if($cRow){ $queries[] = ['text'=>$cRow['name'],'source'=>'category_name']; }
        }catch(Throwable $e){}
        foreach(category_get_keywords($category_id) as $kw){ $queries[] = ['text'=>$kw,'source'=>'keyword']; }
        foreach(category_get_templates($category_id) as $tpl){
          $txt = $tpl;
          if(isset($cRow['name'])){ $txt = str_replace(['{keyword}','{name}'], [$cRow['name'],$cRow['name']], $txt); }
          $txt = str_replace('{city}', $city_hint, $txt);
          $queries[] = ['text'=>$txt,'source'=>'template'];
        }
      }
  if(empty($queries)){ $queries[] = ['text'=>$q,'source'=>'user']; }
  // Cap expansions based on setting
  $max_jobs = (int)get_setting('MAX_EXPANDED_TASKS','30'); if($max_jobs<=0) $max_jobs=30; if($max_jobs>200) $max_jobs=200;
  $projected = count($queries);
  $trimmed = false;
  if($projected > $max_jobs){ $queries = array_slice($queries, 0, $max_jobs); $trimmed = true; }
      $created = 0;
      // Detect optional columns once
      $cols = $pdo->query("PRAGMA table_info(internal_jobs)")->fetchAll(PDO::FETCH_ASSOC);
      $has = function($n) use ($cols){ foreach($cols as $c){ if(($c['name']??$c['Name']??'')===$n) return true; } return false; };
      foreach($queries as $qq){
        $stmt=$pdo->prepare("INSERT INTO internal_jobs(requested_by_user_id,role,agent_id,query,ll,radius_km,lang,region,status,target_count,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?, 'queued', ?, datetime('now'), datetime('now'))");
        $stmt->execute([$u['id'],'admin',NULL,$qq['text'],$ll,$radius_km,$lang,$region,$target]);
        $job_id = $pdo->lastInsertId(); $created++;
        // Best-effort: payload_json and category_id
        try{
          if($has('payload_json')){
            $payload = ['query'=>$qq['text'],'query_source'=>$qq['source'],'category_id'=>$category_id,'center'=>$ll,'radius_km'=>$radius_km,'language'=>$lang,'region'=>$region];
            if($city_hint!==''){ $payload['city_hint']=$city_hint; }
            $pj = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $sqlU = $has('job_type') ? "UPDATE internal_jobs SET job_type='places_api_search', payload_json=:p WHERE id=:id" : "UPDATE internal_jobs SET payload_json=:p WHERE id=:id";
            $stU=$pdo->prepare($sqlU); $stU->execute([':p'=>$pj, ':id'=>$job_id]);
          }
          if($has('category_id')){ $pdo->prepare("UPDATE internal_jobs SET category_id=? WHERE id=?")->execute([$category_id, $job_id]); }
        }catch(Throwable $e){}
      }
  $msg = 'تم إنشاء '.$created.' مهمة إلى السيرفر الداخلي';
  if($trimmed){ $warn = 'تم تقليص التوسّع من '.$projected.' إلى الحد الأقصى '.$max_jobs.' (MAX_EXPANDED_TASKS).'; }
    } else {
  $opts=['q'=>$q,'ll'=>$ll,'radius_km'=>$radius_km,'lang'=>$lang,'region'=>$region,'google_key'=>$key,'foursquare_key'=>get_setting('foursquare_api_key',''), 'preview'=>$preview,'role'=>'admin','user_id'=>$u['id']];
      if($city_hint!==''){ $opts['city_hint'] = $city_hint; }
  // Optional overrides for power users
  if(isset($_POST['exhaustive'])){ $opts['exhaustive'] = true; }
  if(isset($_POST['ignore_tile_ttl'])){ $opts['ignore_tile_ttl'] = true; }
  // Pass-through category context (best-effort) for direct orchestrate path
  $opts['category_id'] = $category_id;
  $summary = orchestrate_fetch($opts);
      if($summary){ $added = (int)$summary['added']; $msg = 'ملخص: تمت إضافة '.$added.' رقم'; if($preview){ $msg .= ' • معاينة: '.(int)$summary['preview']; } }
    }
  }
}
?>
<div class="card">
  <h2>جلب (Providers) — المخزون العام</h2>
  <?php if(system_is_globally_stopped() || system_is_in_pause_window()): ?>
    <p class="badge danger">النظام متوقف مؤقتًا. لا يمكن تنفيذ الجلب الآن.</p>
  <?php endif; ?>
  <?php if($warn): ?><p class="badge danger"><?php echo htmlspecialchars($warn); ?></p><?php endif; ?>
  <?php if($msg): ?><p class="badge"><?php echo htmlspecialchars($msg); ?></p><?php endif; ?>
  <?php if($summary): ?>
    <?php
      $by = $summary['by'] ?? [];
      $line = 'OSM: '.intval($by['osm'] ?? 0).
              ' • Foursquare: '.intval($by['foursquare'] ?? 0).
              ' • Mapbox: '.intval($by['mapbox'] ?? 0).
              ' • Radar: '.intval($by['radar'] ?? 0).
              ' • Google Preview IDs: '.intval($by['google_preview'] ?? 0).
              ' • أُضيف: '.intval($summary['added'] ?? 0).
              ' • متبقي من الكاب: '.intval($summary['cap_remaining'] ?? 0);
      $errCount = !empty($summary['errors']) && is_array($summary['errors']) ? count($summary['errors']) : 0;
      $raw = json_encode($summary, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
    ?>
    <div class="card">
      <details class="collapsible">
        <summary>تفاصيل العملية — <span class="muted"><?php echo htmlspecialchars($line); ?></span><?php if($errCount>0): ?> <span class="badge danger" style="margin-inline-start:6px" title="أخطاء"><?php echo $errCount; ?> أخطاء</span><?php endif; ?></summary>
        <div class="collapsible-body">
          <p class="muted" style="margin-top:0"><?php echo htmlspecialchars($line); ?></p>
          <?php if($errCount>0): ?><p class="badge danger">أخطاء: <?php echo htmlspecialchars(implode(',', $summary['errors'])); ?></p><?php endif; ?>
          <div class="row" style="justify-content:flex-end;margin-bottom:6px">
            <button type="button" class="btn small" data-copy data-copy-text="<?php echo htmlspecialchars($raw); ?>">نسخ JSON</button>
          </div>
          <pre class="code-block"><?php echo htmlspecialchars($raw); ?></pre>
        </div>
      </details>
    </div>
  <?php endif; ?>
  <form method="post" class="grid-3">
    <?php echo csrf_input(); ?>
    <div><label>الاستعلام</label><input name="q" required placeholder="مثال: صالون نسائي الدمام"></div>
    <div>
      <label>التصنيف<span class="muted"> (إلزامي)</span></label>
      <!-- Fallback select for no-JS; will be enhanced into typeahead -->
      <select name="category_id" required data-role="category-select">
        <option value="">— اختر —</option>
        <?php foreach($catRows as $c): if(!(int)$c['active']) continue; $pad = str_repeat('— ', (int)$c['depth']); ?>
          <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($pad.($c['path']?:$c['name'])); ?></option>
        <?php endforeach; ?>
      </select>
      <div id="cat-ta" class="typeahead" style="position:relative; display:none;">
        <input type="text" id="cat-ta-input" placeholder="اكتب اسم التصنيف للبحث" autocomplete="off" aria-autocomplete="list" aria-haspopup="listbox">
        <div id="cat-ta-menu" class="suggest-menu" role="listbox"></div>
        <div id="cat-ta-selected" class="muted" style="margin-top:4px; display:none"></div>
      </div>
    </div>
  <div id="city-box" style="position:relative"><label>اسم المدينة</label><input name="city" value="<?php echo htmlspecialchars(get_setting('default_city','الرياض')); ?>" placeholder="مثال: الرياض" title="يمكن تعبئتها تلقائياً من الخريطة" autocomplete="off"><div id="city-suggest" class="suggest-menu"></div></div>
    <div><label>LL (lat,lng)</label><input name="ll" value="<?php echo htmlspecialchars(get_setting('default_ll','24.638916,46.716010')); ?>" placeholder="24.638916,46.716010"></div>
    <div><label>نصف القطر (كم)</label><input type="number" name="radius_km" value="<?php echo htmlspecialchars(get_setting('default_radius_km','25')); ?>"></div>
    <div><label><input type="checkbox" name="preview_only" value="1"> معاينة بلا تكلفة (IDs Only)</label></div>
    <div style="display:flex;gap:12px;align-items:center">
      <label style="margin:0"><input type="checkbox" name="exhaustive" value="1" <?php echo get_setting('fetch_exhaustive','0')==='1'?'checked':''; ?>> مسح شامل (شبكة نقاط)</label>
      <label style="margin:0"><input type="checkbox" name="ignore_tile_ttl" value="1"> تجاهل فترة التحديث (TTL)</label>
      <label style="margin:0"><input type="checkbox" name="multi_search" value="1"> بحث متعدد الصيغ</label>
    </div>
    <div class="muted" id="projected-tasks" style="grid-column:1/-1">...</div>
    <div class="badge">المتبقي اليوم من Google Details: <?php echo cap_remaining_google_details(); ?></div>
    <div style="grid-column:1/-1; display:flex; gap:12px; align-items:center; margin-top:6px">
      <label style="margin:0"><input type="checkbox" id="toggle-multi-loc" value="1"> تفعيل وضع متعدد المواقع</label>
      <span class="muted">اختياري — ينشئ مجموعة مهام لكل موقع.</span>
    </div>
    <div id="multi-loc-box" class="card" style="grid-column:1/-1; display:none; margin-top:8px">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap">
        <strong>متعدد المواقع</strong>
        <div class="muted" style="display:flex;gap:12px;align-items:center">
          <span id="ml-count" class="ml-badge"></span>
          <span>الحد: <?php echo (int)(get_setting('MAX_MULTI_LOCATIONS','10') ?: 10); ?> مواقع · القص لكل موقع: <?php echo (int)(get_setting('MAX_EXPANDED_TASKS','30') ?: 30); ?> مهام</span>
        </div>
      </div>
      <div id="multi-loc-list" style="margin-top:8px; display:grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap:8px"></div>
      <div class="row" style="justify-content:space-between; gap:8px; margin-top:8px">
        <button type="button" class="btn" id="btn-add-loc"><i class="fa fa-plus"></i> إضافة موقع</button>
        <button type="button" class="btn primary" id="btn-create-ml" <?php echo (system_is_globally_stopped()||system_is_in_pause_window())?'disabled':''; ?>>إنشاء مهام متعددة</button>
      </div>
      <div id="ml-result" class="muted" style="margin-top:8px"></div>
    </div>
  <div style="grid-column:1/-1">
    <label>اختر الموقع على الخريطة</label>
    <div id="pickmap" style="height:360px;border-radius:12px;overflow:hidden;border:1px solid var(--border);margin:6px 0"></div>
    <div class="row" style="justify-content:space-between;margin-top:4px;gap:8px;flex-wrap:wrap">
      <div id="ml-map-controls" style="display:none;gap:8px;align-items:center" class="row">
        <button type="button" class="btn small" id="btn-ml-add" title="إسقاط موقع جديد"><i class="fa fa-plus"></i> إضافة دبوس</button>
        <button type="button" class="btn small" id="btn-ml-place-toggle" title="وضع الإسقاط بالنقر"><i class="fa fa-crosshairs"></i> وضع الإسقاط بالنقر</button>
        <button type="button" class="btn small danger" id="btn-ml-remove" title="حذف الدبوس المحدد"><i class="fa fa-trash"></i> حذف المحدد</button>
        <span id="ml-map-count" class="muted"></span>
        <span id="ml-toast" class="muted" aria-live="polite" style="margin-inline-start:8px"></span>
      </div>
      <div class="row" style="gap:8px">
        <button type="button" class="btn small" id="btn-refit-map" title="إعادة ضبط إطار الخريطة">إعادة ضبط الإطار</button>
      </div>
    </div>
  </div>
  <?php if(get_setting('internal_server_enabled','0')==='1'): ?>
  <div><label>عدد الأرقام المطلوب الوصول إليها</label><input type="number" min="1" step="1" name="target_count" placeholder="مثال: 100"></div>
  <?php endif; ?>
    <div style="grid-column:1/-1"><button class="btn primary" <?php echo (system_is_globally_stopped()||system_is_in_pause_window())?'disabled':''; ?>>جلب الآن</button></div>
  </form>
</div>
<style>
  /* Multi-location UI highlights and responsive map tweaks */
  .ml-row.active { outline: 2px solid var(--primary, #2563eb); border-radius: 8px; }
  .ml-badge { font-size: 12px; color: var(--muted-fg,#667085); }
  @media (max-width: 640px){
    #pickmap{ height: 300px !important; }
    #multi-loc-list{ grid-template-columns: 1fr !important; }
  }
  /* DivIcon for ML pins */
  .ml-pin { width: 14px; height: 14px; border-radius: 50%; border: 2px solid #1e40af; background: #2563eb; box-shadow: 0 0 0 2px rgba(255,255,255,0.6); }
  .ml-pin.active { border-color: #166534; background: #16a34a; }
  .pulse { animation: pulse 0.8s ease-out 1; }
  @keyframes pulse {
    0% { box-shadow: 0 0 0 0 rgba(37,99,235,0.6); }
    70% { box-shadow: 0 0 0 6px rgba(37,99,235,0); }
    100% { box-shadow: 0 0 0 0 rgba(37,99,235,0); }
  }
  #ml-map-controls .btn.small.active { background: #1f2937; color: #fff; }
  @media (max-width: 640px){
    #pickmap{ height: 340px !important; width: 100% !important; }
    #ml-map-controls .btn.small { padding: 10px 12px; font-size: 14px; }
  }
</style>
<script nonce="<?php echo htmlspecialchars(csp_nonce()); ?>">
(function(){
  // Shared map + multi-location state across components
  const ML_MAX = <?php echo (int)(get_setting('MAX_MULTI_LOCATIONS','10') ?: 10); ?>;
  const ML = { map:null, layer:null, items:new Map(), idSeq:1, activeId:null, max: ML_MAX, suppress:false,
    placement:false, geoTimers:new Map(), geoInflight:new Map(), geoLastAt:new Map(),
    throttleLast:0 };
  const DEFAULT_LANG = '<?php echo htmlspecialchars(get_setting('default_language','ar')); ?>';
  const S = {
    limitReached: 'تم بلوغ الحد',
    cannotAddMore: 'تم بلوغ الحد الأقصى، لا يمكن إضافة المزيد.',
    removed: 'تم حذف الموقع',
    radiusClamped: 'تم ضبط نصف القطر إلى النطاق المسموح (0.5–100 كم)',
    placementOn: 'وضع الإسقاط مفعل',
    placementOff: 'وضع الإسقاط متوقف',
    geocodeFail: 'تعذّر جلب اسم المدينة'
  };
  // Fire-and-forget lightweight telemetry (admin-only, CSRF-protected)
  function sendTelemetry(evName){
    try{
      fetch('<?php echo linkTo('api/telemetry_event.php'); ?>', {
        method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ csrf: '<?php echo htmlspecialchars(csrf_token()); ?>', event: String(evName||'') })
      }).catch(()=>{});
    }catch(e){}
  }
  function toast(msg, kind){
    try{
      const el = document.getElementById('ml-toast'); if(!el) return;
      el.textContent = msg; el.className = kind==='danger' ? 'badge danger' : 'badge';
      setTimeout(()=>{ if(el.textContent===msg){ el.textContent=''; el.className='muted'; } }, 1800);
    }catch(e){}
  }
  function throttle(fn, wait){ let t=0, pending=false; return function(){ const now=Date.now(); const rem = t+wait-now; if(rem<=0){ t=now; return fn.apply(this, arguments); } if(!pending){ pending=true; setTimeout(()=>{ pending=false; t=Date.now(); fn.apply(this, arguments); }, rem); } }; }

  // Per-pin reverse geocoding with debounce and 1/sec throttle per pin
  function scheduleMlReverse(id, lat, lng){
    try{
      // Debounce 400ms
      if(ML.geoTimers.has(id)){ clearTimeout(ML.geoTimers.get(id)); ML.geoTimers.delete(id); }
      const fire = ()=>{
        const now = Date.now(); const last = ML.geoLastAt.get(id) || 0;
        const since = now - last;
        if(since < 1000){
          // throttle to 1/sec
          const delay = 1000 - since;
          ML.geoTimers.set(id, setTimeout(()=>scheduleMlReverse(id, lat, lng), delay));
          return;
        }
        ML.geoLastAt.set(id, now);
        // cancel inflight
        try{ const ctrl = ML.geoInflight.get(id); if(ctrl){ ctrl.abort(); } }catch(e){}
        const ctrl = new AbortController(); ML.geoInflight.set(id, ctrl);
        fetch('<?php echo linkTo('api/geo_point_city.php'); ?>', {
          method:'POST', credentials:'same-origin', signal: ctrl.signal,
          headers:{'Content-Type':'application/json'},
          body: JSON.stringify({ lat: lat, lng: lng, strict_city: true, lang: DEFAULT_LANG, csrf: '<?php echo htmlspecialchars(csrf_token()); ?>' })
        }).then(r=>r.json()).then(j=>{
          const it = ML.items.get(id); if(!it || !it.row) return;
          const input = it.row.querySelector('[data-city]'); if(!input) return;
          if(j && j.ok && j.city){ input.value = j.city; input.title = ''; try{ input.classList.add('pulse'); setTimeout(()=>input.classList.remove('pulse'), 800); }catch(e){}; try{ sendTelemetry('ml_geocode_ok'); }catch(e){} }
          else { input.title = S.geocodeFail; try{ sendTelemetry('ml_geocode_fail'); }catch(e){} }
        }).catch(err=>{
          const it = ML.items.get(id); if(!it || !it.row) return; const input = it.row.querySelector('[data-city]'); if(!input) return; input.title=S.geocodeFail; try{ sendTelemetry('ml_geocode_fail'); }catch(e){}
        });
      };
      ML.geoTimers.set(id, setTimeout(fire, 400));
    }catch(e){}
  }
  function isMultiEnabled(){ const t=document.getElementById('toggle-multi-loc'); return !!(t && t.checked); }
  function setSuppress(v){ ML.suppress = !!v; }
  function nextId(){ return ML.idSeq++; }
  function fmtLL(lat,lng){ return lat.toFixed(6)+','+lng.toFixed(6); }
  function parseLL(s){ if(!s) return null; const m = String(s).trim().match(/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/); if(!m) return null; return {lat:parseFloat(m[1]), lng:parseFloat(m[2])}; }
  function updateMlCounter(){
    try{
      const n = ML.items.size; const el = document.getElementById('ml-map-count'); const lbl = document.getElementById('ml-count');
      const txt = 'عدد المواقع المضافة: '+n+' من '+ML.max;
      if(el) el.textContent = txt;
      if(lbl) lbl.textContent = txt;
    }catch(e){}
  }
  function fitAllPins(){ try{
    if(!ML.map) return; if(ML.items.size===0) return;
    const fg = L.featureGroup(Array.from(ML.items.values()).map(it=>it.circle||it.marker));
    ML.map.invalidateSize(false);
    ML.map.fitBounds(fg.getBounds(), { padding:[40,40], maxZoom: 14, animate: false });
  }catch(e){}
  }
  const fitAllPinsTh = throttle(fitAllPins, 250);
  function setActive(id){
    ML.activeId = id;
    // update marker visuals
    ML.items.forEach((it, key)=>{
      try{
        if(it.marker && it.marker._icon){ it.marker._icon.classList.toggle('ml-pin', true); it.marker._icon.classList.toggle('active', key===id); }
      }catch(e){}
      try{
        if(it.row){ it.row.classList.toggle('active', key===id); }
      }catch(e){}
    });
  }
  function removeItem(id){
    const it = ML.items.get(id); if(!it) return;
    try{ if(it.marker) ML.layer.removeLayer(it.marker); }catch(e){}
    try{ if(it.circle) ML.layer.removeLayer(it.circle); }catch(e){}
    if(it.row){ try{ it.row.remove(); }catch(e){} }
    ML.items.delete(id);
    if(ML.activeId===id) ML.activeId=null;
    updateMlCounter(); fitAllPinsTh(); toast(S.removed); try{ sendTelemetry('ml_pin_remove'); }catch(e){}
  }
  function bindRowInputs(id, row){
    const iLL = row.querySelector('[data-ll]');
    const iR = row.querySelector('[data-radius]');
    const iC = row.querySelector('[data-city]');
    const dd = row.querySelector('[data-city-dd]');
    const it = ML.items.get(id); if(!it) return;
    function onLL(){
      if(ML.suppress) return; const p=parseLL(iLL.value); if(!p) return;
      it.marker.setLatLng(p); it.circle.setLatLng(p); updatePopup(it); setActive(id);
      scheduleMlReverse(id, p.lat, p.lng);
    }
    function onR(){
      if(ML.suppress) return; let km = parseFloat(iR.value);
      if(!(km>0)){ km = 1; }
      if(km < 0.5){ km = 0.5; toast(S.radiusClamped); }
      if(km > 100){ km = 100; toast(S.radiusClamped); }
      iR.value = km;
      it.circle.setRadius(km*1000); updatePopup(it);
    }
    function onC(){ if(ML.suppress) return; updatePopup(it); }
    iLL.addEventListener('change', onLL); iLL.addEventListener('blur', onLL); iLL.addEventListener('input', ()=>{/* live typing ignored */});
    iR.addEventListener('input', onR); iR.addEventListener('change', onR);
    iC.addEventListener('change', onC);
    // Per-row city suggest like single mode
    (function(){
      if(!dd || !iC) return;
      let t=null;
      function hideDD(){ dd.classList.remove('show'); dd.innerHTML=''; }
      async function suggest(){
        const q = iC.value.trim(); if(!q){ hideDD(); return; }
        try{
          const res = await fetch('<?php echo linkTo('api/geo_point_city.php'); ?>', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body: JSON.stringify({suggest:true, q:q, csrf:'<?php echo htmlspecialchars(csrf_token()); ?>'})});
          const j = await res.json(); if(!(j && j.ok && Array.isArray(j.suggestions))){ hideDD(); return; }
          if(j.suggestions.length===0){ hideDD(); return; }
          dd.innerHTML = j.suggestions.map(s=>`<a href="#" class="suggest-item" data-lat="${s.lat??''}" data-lng="${s.lng??''}"><span class="suggest-name">${s.name}</span> <span class="suggest-region muted">${(s.region||'')}</span></a>`).join('');
          dd.classList.add('show');
        }catch(e){ hideDD(); }
      }
      iC.addEventListener('input', ()=>{ if(t) clearTimeout(t); t=setTimeout(suggest, 250); });
      dd.addEventListener('click', (ev)=>{
        const a = ev.target.closest('a'); if(!a) return; ev.preventDefault();
        const nm = (a.querySelector('.suggest-name')?.textContent||a.textContent||'').trim();
        const la = parseFloat(a.getAttribute('data-lat')); const lo = parseFloat(a.getAttribute('data-lng'));
        iC.value = nm; if(!isNaN(la) && !isNaN(lo)){
          setSuppress(true);
          iLL.value = fmtLL(la,lo);
          setSuppress(false);
          onLL();
          setActive(id);
        }
        hideDD();
      });
      document.addEventListener('click', (e)=>{ if(!dd.contains(e.target) && e.target!==iC){ hideDD(); } });
      // On commit (change/blur) when value matches one of the suggestions we showed, move to that city center
      async function nameMatchesSuggestion(){
        const current = iC.value.trim(); if(!current) return false;
        const items = Array.from(dd.querySelectorAll('a.suggest-item .suggest-name')).map(el=>el.textContent.trim());
        return items.includes(current);
      }
      async function resolveIfCommitted(){
        try{
          if(!(await nameMatchesSuggestion())) return;
          const res = await fetch('<?php echo linkTo('api/geo_point_city.php'); ?>', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body: JSON.stringify({city_name:iC.value.trim(), csrf:'<?php echo htmlspecialchars(csrf_token()); ?>'})});
          const j = await res.json(); if(j && j.ok && typeof j.lat==='number' && typeof j.lng==='number'){
            setSuppress(true); iLL.value = fmtLL(j.lat, j.lng); setSuppress(false); onLL(); setActive(id);
          }
        }catch(e){}
      }
      iC.addEventListener('change', resolveIfCommitted);
      iC.addEventListener('blur', resolveIfCommitted);
    })();
  }
  function updatePopup(it){ try{
    const ll = it.marker.getLatLng(); const km = (it.row.querySelector('[data-radius]')?.value)||''; const city = (it.row.querySelector('[data-city]')?.value)||'';
    const html = `<div style="min-width:160px">
      <div class="muted" style="font-size:12px">${city?('المدينة: '+city):'—'}</div>
      <div class="muted" style="font-size:12px">نصف القطر: ${km||'—'} كم</div>
      <div class="muted" style="font-size:12px">LL: ${fmtLL(ll.lat,ll.lng)}</div>
      <div class="row" style="gap:6px;margin-top:6px">
        <button type="button" class="btn small" data-ml-goto>تعديل</button>
        <button type="button" class="btn small danger" data-ml-del>حذف</button>
      </div>
    </div>`;
    it.marker.bindPopup(html);
  }catch(e){}
  }
  function markerIcon(active){
    return L.divIcon({ className: (active?'ml-pin active':'ml-pin'), iconSize:[14,14], iconAnchor:[7,7] });
  }
  function addItemFromData(data){
    if(ML.items.size >= ML.max){
      try{ alert('تم بلوغ الحد الأقصى، لا يمكن إضافة المزيد.'); }catch(e){}
      return null;
    }
    if(!ML.map) return null;
    const id = nextId();
    const ll = data.ll && parseLL(data.ll) ? parseLL(data.ll) : (data.lat && data.lng ? {lat:data.lat,lng:data.lng} : ML.map.getCenter());
    const km = parseFloat(data.radius_km)||parseFloat(document.querySelector('input[name="radius_km"]')?.value)||25;
    const m = L.marker(ll, { draggable:true, icon: markerIcon(false) });
    const c = L.circle(ll, { radius: km*1000, color:'#2563eb', weight:2, opacity:0.9, fillColor:'#3b82f6', fillOpacity:0.08 });
  m.on('click', ()=>{ setActive(id); try{ m.openPopup(); }catch(e){} });
  m.on('mouseover', ()=>{ try{ m.openPopup(); }catch(e){} });
    m.on('drag', ()=>{
      const p = m.getLatLng(); setSuppress(true);
      try{ if(it && it.row){ const i = it.row.querySelector('[data-ll]'); if(i){ i.value = fmtLL(p.lat,p.lng); } } }catch(e){}
    c.setLatLng(p); setSuppress(false);
    scheduleMlReverse(id, p.lat, p.lng);
    });
  m.on('dragend', ()=>{ updatePopup(it); });
    m.on('popupopen', (ev)=>{
      try{
        const node = ev.popup.getElement(); if(!node) return;
        const go = node.querySelector('[data-ml-goto]'); const del = node.querySelector('[data-ml-del]');
        if(go){ go.addEventListener('click', (e)=>{ e.preventDefault(); setActive(id); if(it && it.row){ it.row.scrollIntoView({behavior:'smooth', block:'center'}); } }); }
        if(del){ del.addEventListener('click', (e)=>{ e.preventDefault(); removeItem(id); }); }
      }catch(e){}
    });
    m.addTo(ML.layer); c.addTo(ML.layer);
    const it = { id, marker:m, circle:c, row:null };
    ML.items.set(id, it);
    setActive(id); updateMlCounter(); updatePopup({ marker:m, row:null, circle:c }); fitAllPinsTh(); try{ sendTelemetry('ml_pin_add'); }catch(e){}
    return id;
  }
  function ensureRowForItem(id, data){
    const list = document.getElementById('multi-loc-list'); if(!list) return;
    // create row card
    const wrap = document.createElement('div'); wrap.className='card ml-row'; wrap.dataset.mlId = String(id); wrap.style.margin='0'; wrap.style.padding='8px';
    wrap.innerHTML = `
      <div style="display:grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap:8px; align-items:end">
        <div><label>LL</label><input type="text" data-ll value="${(data && data.ll)||''}" placeholder="24.638916,46.716010" title="إحداثيات الموقع"></div>
        <div><label>نصف القطر (كم)</label><input type="number" data-radius value="${(data && data.radius_km)||''}" placeholder="25" title="نصف قطر البحث"></div>
        <div style="position:relative"><label>المدينة</label><input type="text" data-city value="${(data && (data.city||''))||''}" placeholder="اختياري" title="اسم المدينة (اختياري)" autocomplete="off"><div class="suggest-menu" data-city-dd></div></div>
      </div>
      <div class="row" style="justify-content:flex-end; margin-top:6px"><button type="button" class="btn small" data-remove title="حذف هذا الموقع">حذف</button></div>
    `;
    // attach
  const it = ML.items.get(id); if(it){ it.row = wrap; }
  bindRowInputs(id, wrap);
  if(it){ updatePopup(it); }
    wrap.querySelector('[data-remove]').addEventListener('click', ()=>{ removeItem(id); });
    wrap.addEventListener('click', ()=>{ setActive(id); try{ it && it.marker && it.marker.openPopup(); }catch(e){} });
    wrap.addEventListener('mouseenter', ()=>{ setActive(id); });
    list.appendChild(wrap);
    updateMlCounter();
  }
  function syncItemsFromExistingRows(){
    const list = document.getElementById('multi-loc-list'); if(!list) return;
    const rows = Array.from(list.children);
    if(rows.length===0){
      // seed with current single LL
      const ll = document.querySelector('input[name="ll"]').value; const radius = document.querySelector('input[name="radius_km"]').value; const city = document.querySelector('input[name="city"]').value;
      const id = addItemFromData({ ll, radius_km: radius, city }); ensureRowForItem(id, { ll, radius_km: radius, city });
      return;
    }
    rows.forEach(row=>{
      const existing = row.getAttribute('data-ml-id');
      if(existing && ML.items.has(parseInt(existing,10))){
        // Already bound; just ensure listeners and popup
        const id = parseInt(existing,10); const it = ML.items.get(id); if(it){ it.row = row; bindRowInputs(id, row); updatePopup(it); }
        return;
      }
      const ll = row.querySelector('[data-ll]')?.value||'';
      const radius_km = row.querySelector('[data-radius]')?.value||'';
      const city = row.querySelector('[data-city]')?.value||'';
      const id = addItemFromData({ ll, radius_km, city }); ensureRowForItem(id, { ll, radius_km, city });
    });
  }
  // Defer map init until Leaflet (deferred in <head>) is ready to avoid L being undefined
  var __pickMapOnce = false;
  function __initPickMap(){ if(__pickMapOnce) return; __pickMapOnce = true;
  const llInput = document.querySelector('input[name="ll"]');
  const radInput = document.querySelector('input[name="radius_km"]');
  const cityInput = document.querySelector('input[name="city"]');
  const def = llInput && llInput.value ? llInput.value : '';
  let center = [24.638916, 46.716010], zoom=12; // default to Riyadh
  if(def && /^-?\d+(?:\.\d+)?,\s*-?\d+(?:\.\d+)?$/.test(def)){ const p=def.split(','); center=[parseFloat(p[0]),parseFloat(p[1])]; zoom=12; }
  if(!window.L){ console.warn('Leaflet not ready yet for pickmap'); return; }
  const map = L.map('pickmap').setView(center, zoom);
  // expose in ML context
  ML.map = map; ML.layer = L.layerGroup().addTo(map);
  // Resilient tile layer with fallbacks + diagnostics
  (function(){
    // status line under the map
    try{
      var mapBox = document.getElementById('pickmap');
      var status = document.createElement('div');
      status.id = 'pickmap-status';
      status.className = 'muted';
      status.style.margin = '6px 0 0';
      mapBox && mapBox.parentNode && mapBox.parentNode.insertBefore(status, mapBox.nextSibling);
      function setStatus(t){ if(status) status.textContent = t; }
      const sources = (function(){
        try{
          // Allow admin-configured tile sources via settings (JSON array of {url, att})
          var cfg = <?php 
            $ts = get_setting('tile_sources_json','');
            $tsj = '';
            if($ts){ $arr = json_decode($ts, true); if(is_array($arr) && count($arr)>0){ $tsj = json_encode($arr, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); } }
            echo $tsj ? $tsj : 'null';
          ?>;
          if(Array.isArray(cfg) && cfg.length>0){ return cfg; }
        }catch(e){}
        return [
          { url: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', att: '&copy; OpenStreetMap contributors' },
          { url: 'https://{s}.tile.openstreetmap.de/tiles/osmde/{z}/{x}/{y}.png', att: '&copy; OpenStreetMap contributors, tiles: openstreetmap.de' },
          { url: 'https://{s}.tile.openstreetmap.fr/osmfr/{z}/{x}/{y}.png', att: '&copy; OpenStreetMap contributors, tiles: osmfr' },
          { url: 'https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}.png', att: '&copy; OpenStreetMap contributors &copy; CARTO' }
        ];
      })();
      let idx = 0, failures = 0, currentLayer = null;
      function useSource(i){
        if(currentLayer){ try{ map.removeLayer(currentLayer); }catch(e){} currentLayer = null; }
        const s = sources[i];
        setStatus('يتم تحميل الخرائط من: '+s.url.replace('{s}','a'));
        currentLayer = L.tileLayer(s.url, { maxZoom: 19, attribution: s.att });
        currentLayer.on('load', function(){ setStatus('تم التحميل من المصدر '+(i+1)+'؛ يمكنك التكبير/السحب.'); });
        currentLayer.on('tileerror', function(){
          failures++;
          if(failures >= 3 && idx < sources.length-1){
            setStatus('المصدر الحالي محجوب غالبًا. الانتقال لمصدر بديل...');
            failures = 0; idx++; useSource(idx);
          }
        });
        currentLayer.addTo(map);
      }
      useSource(idx);
    }catch(e){ /* ignore diagnostics issues */ }
  })();
  let marker=null;
  let areaCircle=null;
  function fitToCircle(){
    try{
      if(!areaCircle) return;
      const bounds = areaCircle.getBounds();
      // Ensure layout is measured correctly before fitting
      try{ map.invalidateSize(false); }catch(e){}
      // Fit the circle fully with extra padding; cap max zoom to avoid over-zoom
      // Use rAF to let layout settle first
      (window.requestAnimationFrame||setTimeout)(function(){
        map.fitBounds(bounds, { padding: [40,40], maxZoom: 14, animate: false });
      }, 0);
    }catch(e){}
  }
  function updateCircle(latlng, fit=false){
    try{
      const km = parseFloat(radInput && radInput.value ? radInput.value : '<?php echo (int)get_setting('default_radius_km','25'); ?>');
      const meters = isFinite(km) && km>0 ? km*1000 : 1000;
      if(!areaCircle){
        areaCircle = L.circle(latlng, { radius: meters, color: '#0ea5e9', weight: 2, opacity: 0.9, fillColor: '#38bdf8', fillOpacity: 0.08 });
        areaCircle.addTo(map);
      } else {
        areaCircle.setLatLng(latlng); areaCircle.setRadius(meters);
      }
      if(fit){ fitToCircle(); }
    }catch(e){ /* ignore if Leaflet unavailable */ }
  }
  let revTimer = null;
    async function reverseCity(lat, lng, force=false){
      try{
  const res = await fetch('<?php echo linkTo('api/geo_point_city.php'); ?>', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body: JSON.stringify({lat:lat, lng:lng, strict_city:true, csrf:'<?php echo htmlspecialchars(csrf_token()); ?>'})});
        const j = await res.json();
        // Update city if forced by map action, or when not actively typing
        const active = document.activeElement===cityInput;
        if(j && j.ok && j.city && cityInput && (force || (!active && cityInput.value.trim()===''))){ cityInput.value = j.city; }
      }catch(e){}
    }
  function scheduleReverse(lat,lng,force=false){ if(revTimer){ clearTimeout(revTimer); } revTimer = setTimeout(()=>reverseCity(lat,lng,force), 500); }
  function setLL(latlng, forceCity=false, fit=false){
    if(!marker){
      marker=L.marker(latlng,{draggable:true}).addTo(map);
      marker.on('drag',()=>{ const ps=marker.getLatLng(); llInput.value=ps.lat.toFixed(6)+','+ps.lng.toFixed(6); updateCircle(ps, false); scheduleReverse(ps.lat, ps.lng, true); });
      marker.on('dragend',()=>{const ps=marker.getLatLng(); llInput.value=ps.lat.toFixed(6)+','+ps.lng.toFixed(6); updateCircle(ps, true); reverseCity(ps.lat, ps.lng, true);});
    } else { marker.setLatLng(latlng); }
    llInput.value=latlng.lat.toFixed(6)+','+latlng.lng.toFixed(6);
    updateCircle(latlng, fit);
    reverseCity(latlng.lat, latlng.lng, forceCity);
  }
  map.on('click', e => {
    if(!isMultiEnabled()){ setLL(e.latlng, true); }
    else if(ML && ML.placement){
      // Placement mode: add pin at click
      const km = parseFloat(radInput && radInput.value ? radInput.value : '<?php echo (int)get_setting('default_radius_km','25'); ?>') || 25;
      const id = addItemFromData({ lat: e.latlng.lat, lng: e.latlng.lng, radius_km: km, city: '' });
      if(id!==null){ ensureRowForItem(id, { ll: fmtLL(e.latlng.lat,e.latlng.lng), radius_km: km, city:'' }); fitAllPinsTh(); }
      else { toast(S.cannotAddMore, 'danger'); }
    }
  });
  // Toggle single marker visibility when multi-location is enabled
  function updateSingleVisibility(){
    try{
      const on = !isMultiEnabled();
      if(marker){ if(on && !map.hasLayer(marker)) marker.addTo(map); else if(!on && map.hasLayer(marker)) map.removeLayer(marker); }
      if(areaCircle){ if(on && !map.hasLayer(areaCircle)) areaCircle.addTo(map); else if(!on && map.hasLayer(areaCircle)) map.removeLayer(areaCircle); }
      // Toggle ML layer visibility as well (show only in ML mode)
      if(ML && ML.layer){
        const has = ML.map && ML.map.hasLayer(ML.layer);
        if(on && has){ // single mode: hide ML layer
          try{ ML.map.removeLayer(ML.layer); }catch(e){}
        } else if(!on && !has){ // ML mode: show ML layer
          try{ ML.layer.addTo(ML.map); }catch(e){}
        }
      }
      // Toggle single city box visibility to avoid confusion in ML mode
      try{
        var cityBox = document.getElementById('city-box'); if(cityBox){ cityBox.style.display = on ? '' : 'none'; }
      }catch(e){}
    }catch(e){}
  }
  if(def){ setLL({lat:center[0],lng:center[1]}, true, true); } else { setLL({lat:center[0],lng:center[1]}, true, true); }
  if(radInput){
    radInput.addEventListener('input', ()=>{ if(marker){ updateCircle(marker.getLatLng(), true); } });
    radInput.addEventListener('change', ()=>{ if(marker){ updateCircle(marker.getLatLng(), true); } });
  }
  // Observe multi-location toggle to hide/show single marker
  try{ var _t = document.getElementById('toggle-multi-loc'); if(_t){ _t.addEventListener('change', updateSingleVisibility); updateSingleVisibility(); } }catch(e){}
  // Keep circle fully visible when container resizes
  map.on('resize', function(){ fitToCircle(); });
  // Manual re-fit control
  try{ var refitBtn = document.getElementById('btn-refit-map'); if(refitBtn){ refitBtn.addEventListener('click', function(){ if(isMultiEnabled() && ML.items.size>0){ fitAllPins(); } else { fitToCircle(); } }); } }catch(e){}
  // Snap to city center when user types a valid SA city name
  if(cityInput){
    let cityTimer=null; const doLookup=async()=>{
      const name = cityInput.value.trim(); if(!name) return;
      try{
        const res = await fetch('<?php echo linkTo('api/geo_point_city.php'); ?>', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body: JSON.stringify({city_name:name, csrf:'<?php echo htmlspecialchars(csrf_token()); ?>'})});
        const j = await res.json(); if(j && j.ok && typeof j.lat==='number' && typeof j.lng==='number'){
          const latlng = {lat:j.lat, lng:j.lng}; setLL(latlng, false, true);
        }
      }catch(e){}
    };
      // Only resolve name->coords when user commits (change/blur) AND the value matches a suggestion we offered
      function nameMatchesSuggestion(){
        const current = cityInput.value.trim(); if(!current) return false;
        const items = Array.from(dd.querySelectorAll('a.suggest-item .suggest-name')).map(el=>el.textContent.trim());
        return items.includes(current);
      }
      cityInput.addEventListener('change', ()=>{ if(nameMatchesSuggestion()){ doLookup(); } });
      cityInput.addEventListener('blur', ()=>{ if(nameMatchesSuggestion()){ doLookup(); } });
    const dd = document.getElementById('city-suggest');
  function hideDD(){ dd.classList.remove('show'); dd.innerHTML=''; }
    async function suggest(){
      const q = cityInput.value.trim(); if(!q){ hideDD(); return; }
      try{
        const res = await fetch('<?php echo linkTo('api/geo_point_city.php'); ?>', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body: JSON.stringify({suggest:true, q:q, csrf:'<?php echo htmlspecialchars(csrf_token()); ?>'})});
        const j = await res.json(); if(!(j && j.ok && Array.isArray(j.suggestions))){ hideDD(); return; }
        if(j.suggestions.length===0){ hideDD(); return; }
  dd.innerHTML = j.suggestions.map(s=>`<a href="#" class="suggest-item" data-id="${s.id}" data-lat="${s.lat??''}" data-lng="${s.lng??''}"><span class="suggest-name">${s.name}</span> <span class="suggest-region muted">${(s.region||'')}</span></a>`).join('');
  dd.classList.add('show');
      }catch(e){ hideDD(); }
    }
  cityInput.addEventListener('input', ()=>{ if(cityTimer) clearTimeout(cityTimer); cityTimer=setTimeout(()=>{ suggest(); }, 250); });
      dd.addEventListener('click', (ev)=>{
      const a = ev.target.closest('a'); if(!a) return; ev.preventDefault();
      const nm = a.innerText.replace(/\([^)]*\)$/,'').trim(); cityInput.value = nm;
      const la = parseFloat(a.getAttribute('data-lat')); const lo = parseFloat(a.getAttribute('data-lng'));
        if(!isNaN(la) && !isNaN(lo)){ setLL({lat:la, lng:lo}, false, true); }
      hideDD();
    });
    document.addEventListener('click', (e)=>{ if(!dd.contains(e.target) && e.target!==cityInput){ hideDD(); } });
  }
  }
  if(window.L){ __initPickMap(); }
  else {
    // Attempt to load Leaflet dynamically if not yet available
    function ensureLeafletAssets(){
      try{
        // CSS fallback chain: local -> unpkg -> jsDelivr
        if(!document.querySelector('link[href*="leaflet.css"]')){
          var cssTried = 0;
          function addCss(){
            var l = document.createElement('link'); l.rel='stylesheet';
            if(cssTried===0) l.href='<?php echo linkTo('assets/vendor/leaflet/leaflet.css'); ?>';
            else if(cssTried===1) l.href='https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
            else l.href='https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css';
            l.onerror=function(){ cssTried++; if(cssTried<3) addCss(); };
            document.head.appendChild(l);
          }
          addCss();
        }
        // JS fallback chain: local -> unpkg -> jsDelivr
        if(!(window.L || document.querySelector('script[data-leaflet-loaded]'))){
          var jsTried = 0;
          function addJs(){
            var s = document.createElement('script');
            if(jsTried===0) s.src='<?php echo linkTo('assets/vendor/leaflet/leaflet.js'); ?>';
            else if(jsTried===1) s.src='https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
            else s.src='https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js';
            s.async = true; s.setAttribute('data-leaflet-loaded','1');
            s.onerror=function(){ jsTried++; if(jsTried<3) addJs(); };
            document.head.appendChild(s);
          }
          addJs();
        }
      }catch(e){}
    }
    ensureLeafletAssets();
    window.addEventListener('load', function(){ if(window.L) __initPickMap(); else ensureLeafletAssets(); });
    var __tries=0; var __t = setInterval(function(){ if(window.L){ clearInterval(__t); __initPickMap(); }
      else {
        if(++__tries===10) ensureLeafletAssets();
        if(__tries>60){ clearInterval(__t); try{ var box=document.getElementById('pickmap'); if(box){ box.innerHTML='<div class="alert danger" style="margin:8px">تعذر تحميل الخريطة — تحقق من الاتصال أو السماح للنطاقات unpkg.com و tile.openstreetmap.org</div>'; } }catch(e){} }
      }
    }, 100);
  }
  // Projected tasks UI
  try{
    const kwCounts = <?php echo json_encode($kwCounts, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
    const tplCounts = <?php echo json_encode($tplCounts, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;
    const cap = <?php echo (int)(get_setting('MAX_EXPANDED_TASKS','30') ?: 30); ?>;
    const elCat = document.querySelector('select[name="category_id"]');
    const elQ = document.querySelector('input[name="q"]');
    const elMulti = document.querySelector('input[name="multi_search"]');
    const elProj = document.getElementById('projected-tasks');
    function updateProjected(){
      const cat = parseInt(elCat && elCat.value ? elCat.value : '0', 10) || 0;
      const hasQ = !!(elQ && elQ.value.trim());
      const multi = !!(elMulti && elMulti.checked);
      let n = 0;
      if(hasQ) n += 1; // user query
      if(multi && cat>0){ n += 1; n += (kwCounts[cat]||0); n += (tplCounts[cat]||0); }
      if(!hasQ && n===0 && cat>0) n = 1; // fallback to one task from category if no q
      const capped = Math.min(n, cap);
      let msg = 'المهام المتوقعة: '+n;
      if(n>cap) msg += ' — سيتم القص إلى '+cap+' (MAX_EXPANDED_TASKS)';
      if(elProj){ elProj.textContent = msg; }
    }
    ['change','input'].forEach(ev=>{
      if(elCat) elCat.addEventListener(ev, updateProjected);
      if(elQ) elQ.addEventListener(ev, updateProjected);
      if(elMulti) elMulti.addEventListener(ev, updateProjected);
    });
    updateProjected();
  }catch(e){}
  // Multi-location UI
  try{
    const toggle = document.getElementById('toggle-multi-loc');
    const box = document.getElementById('multi-loc-box');
    const list = document.getElementById('multi-loc-list');
    const btnAdd = document.getElementById('btn-add-loc');
    const btnMapAdd = document.getElementById('btn-ml-add');
    const btnMapRemove = document.getElementById('btn-ml-remove');
    const mapControls = document.getElementById('ml-map-controls');
  const btnCreate = document.getElementById('btn-create-ml');
    const inputQ = document.querySelector('input[name="q"]');
    const selCat = document.querySelector('select[name="category_id"]');
    const chkMulti = document.querySelector('input[name="multi_search"]');
    const inputTarget = document.querySelector('input[name="target_count"]');
    const inputCity = document.querySelector('input[name="city"]');
    const inputLL = document.querySelector('input[name="ll"]');
    const inputRad = document.querySelector('input[name="radius_km"]');
    const result = document.getElementById('ml-result');
    function renderRow(data){
      // If map is ready, create a bound item and its row
      if(window.L && ML && ML.map){
        const id = addItemFromData(data||{});
        if(id!==null){ ensureRowForItem(id, data||{}); return id; }
      }
      // Fallback: create row only (no map available yet)
      const wrap = document.createElement('div'); wrap.className='card ml-row'; wrap.style.margin='0'; wrap.style.padding='8px';
      wrap.innerHTML = `
        <div style="display:grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap:8px; align-items:end">
          <div><label>LL</label><input type="text" data-ll value="${(data && data.ll)||''}" placeholder="24.638916,46.716010" title="إحداثيات الموقع"></div>
          <div><label>نصف القطر (كم)</label><input type="number" data-radius value="${(data && data.radius_km)||''}" placeholder="25" title="نصف قطر البحث"></div>
          <div><label>المدينة</label><input type="text" data-city value="${(data && (data.city||''))||''}" placeholder="اختياري" title="اسم المدينة (اختياري)"></div>
        </div>
        <div class="row" style="justify-content:flex-end; margin-top:6px"><button type="button" class="btn small" data-remove title="حذف هذا الموقع">حذف</button></div>
      `;
      wrap.querySelector('[data-remove]').addEventListener('click', ()=>{ wrap.remove(); });
      list.appendChild(wrap);
      return null;
    }
    function initOnce(){
      if(list.childElementCount===0){
        renderRow({ ll: (inputLL && inputLL.value)||'', radius_km: (inputRad && inputRad.value)||'', city: (inputCity && inputCity.value)||'' });
      }
      // When enabling with existing rows (fallback-created), bind them into map only once
      if(window.L && ML && ML.map && ML.items.size===0){ syncItemsFromExistingRows(); }
      const mc = document.getElementById('ml-map-controls'); if(mc) mc.style.display = 'flex';
      updateMlCounter();
    }
    if(toggle){ toggle.addEventListener('change', ()=>{ box.style.display = toggle.checked ? 'block' : 'none'; if(toggle.checked){ initOnce(); if(ML && ML.map && ML.layer && !ML.map.hasLayer(ML.layer)) ML.layer.addTo(ML.map); } else { const mc=document.getElementById('ml-map-controls'); if(mc) mc.style.display='none'; try{ if(ML && ML.map && ML.layer && ML.map.hasLayer(ML.layer)) ML.map.removeLayer(ML.layer); }catch(e){} } }); }
    if(btnAdd){ btnAdd.addEventListener('click', ()=>{ renderRow({ ll: '', radius_km: (inputRad && inputRad.value)||'', city: '' }); fitAllPins(); }); }
    if(btnMapAdd){ btnMapAdd.addEventListener('click', ()=>{ renderRow({ ll: (ML.map ? fmtLL(ML.map.getCenter().lat, ML.map.getCenter().lng) : ''), radius_km: (inputRad && inputRad.value)||'', city: (inputCity && inputCity.value)||'' }); fitAllPinsTh(); }); }
    if(btnMapRemove){ btnMapRemove.addEventListener('click', ()=>{ if(ML.activeId!=null){ removeItem(ML.activeId); } }); }
    const btnPlace = document.getElementById('btn-ml-place-toggle');
    function setPlacement(on){ ML.placement = !!on; try{ btnPlace && btnPlace.classList.toggle('active', ML.placement); toast(ML.placement?S.placementOn:S.placementOff); }catch(e){} }
    if(btnPlace){ btnPlace.addEventListener('click', ()=>{ setPlacement(!ML.placement); }); }
    // Keyboard shortcuts: P toggles placement; Esc disables it
    document.addEventListener('keydown', (ev)=>{
      if(!isMultiEnabled()) return;
      if(ev.key==='p' || ev.key==='P'){ ev.preventDefault(); setPlacement(!ML.placement); }
      else if(ev.key==='Escape'){ if(ML.placement){ ev.preventDefault(); setPlacement(false); } }
    });
    async function createML(){
      result.textContent = 'جارٍ الإنشاء…';
      const cat = parseInt(selCat && selCat.value ? selCat.value : '0', 10) || 0;
      if(!cat){ result.textContent='اختر تصنيفًا أولاً.'; return; }
      const locs = Array.from(list.children).map(row=>{
        return {
          ll: (row.querySelector('[data-ll]')?.value||'').trim(),
          radius_km: parseInt(row.querySelector('[data-radius]')?.value||'0', 10) || undefined,
          city: (row.querySelector('[data-city]')?.value||'').trim()
        };
      }).filter(x=>x.ll!=='');
      // Enforce max and alert politely
      if(locs.length > ML.max){
        result.textContent = 'تم بلوغ الحد الأقصى، لا يمكن إضافة المزيد.'; return;
      }
      if(locs.length===0){ result.textContent='أضف موقعًا واحدًا على الأقل.'; return; }
      const payload = {
        csrf: '<?php echo htmlspecialchars(csrf_token()); ?>',
        category_id: cat,
        base_query: (inputQ && inputQ.value)||'',
        multi_search: !!(chkMulti && chkMulti.checked),
        locations: locs
      };
      if(inputTarget && inputTarget.value){ payload.target_count = parseInt(inputTarget.value, 10) || undefined; }
      try{
        const res = await fetch('<?php echo linkTo('api/jobs_multi_create.php'); ?>', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)});
        const j = await res.json();
        if(!(j && j.ok)){ result.innerHTML = '<span class="badge danger">فشل الإنشاء</span>'; return; }
        const total = j.jobs_created_total||0; const gid = j.job_group_id||'—';
        result.innerHTML = '<span class="badge">تم إنشاء '+total+' مهمة ضمن المجموعة #'+gid+'</span>';
        const raw = JSON.stringify(j, null, 2);
        const d = document.createElement('details'); d.className='collapsible'; d.open = false;
        d.innerHTML = '<summary>تفاصيل الإنشاء</summary><div class="collapsible-body"><div class="row" style="justify-content:flex-end"><button type="button" class="btn small" data-copy data-copy-text="'+raw.replace(/["<>]/g, (s)=>({ '"':'&quot;','<':'&lt;','>':'&gt;' }[s]))+'">نسخ JSON</button></div><pre class="code-block">'+raw.replace(/[<>&]/g, (s)=>({ '<':'&lt;','>':'&gt;','&':'&amp;' }[s]))+'</pre></div>';
        // replace any existing detail
        const prev = result.querySelector('details'); if(prev){ prev.remove(); }
        result.appendChild(d);
      }catch(e){ result.innerHTML = '<span class="badge danger">خطأ بالشبكة</span>'; }
    }
    if(btnCreate){ btnCreate.addEventListener('click', createML); }
  }catch(e){}
  // Enhance category select into typeahead (progressive enhancement)
  try{
    const sel = document.querySelector('select[data-role="category-select"]');
    const box = document.getElementById('cat-ta');
    const input = document.getElementById('cat-ta-input');
    const menu = document.getElementById('cat-ta-menu');
    const chosen = document.getElementById('cat-ta-selected');
    if(sel && box && input && menu){
      // Show typeahead and hide select
      box.style.display='block'; sel.style.display='none';
      function setSelected(id,label){
        sel.value = (id||'');
        chosen.textContent = label ? ('المحدد: '+label+' (#'+id+')') : '';
        chosen.style.display = label ? 'block' : 'none';
        // trigger change for projections
        try{ sel.dispatchEvent(new Event('change',{bubbles:true})); }catch(e){}
      }
      // If select had an initial, show it
      if(sel.value){
        const opt = sel.options[sel.selectedIndex];
        setSelected(sel.value, opt ? opt.textContent.trim() : '');
      }
      let activeIndex = -1;
      function clearMenu(){ menu.classList.remove('show'); menu.innerHTML=''; activeIndex=-1; }
      function render(items){
        if(!Array.isArray(items) || items.length===0){ clearMenu(); return; }
        menu.innerHTML = items.map((it,idx)=>{
          const icon = (it.icon && it.icon.type==='fa') ? `<i class="fa ${it.icon.value}"></i>` : '';
          const path = (it.path||it.name||'');
          return `<a href="#" role="option" class="suggest-item" data-id="${it.id}" data-label="${path.replace(/\"/g,'&quot;')}">${icon} <span class="suggest-name">${path}</span> <span class="muted">#${it.id}</span></a>`;
        }).join('');
        menu.classList.add('show');
      }
      let t=null; async function search(){
        const q = input.value.trim(); if(q.length<2){ clearMenu(); return; }
        try{
          const res = await fetch('<?php echo linkTo('api/category_search.php'); ?>?q='+encodeURIComponent(q)+'&limit=15&active_only=1&csrf=<?php echo htmlspecialchars(csrf_token()); ?>', {credentials:'same-origin'});
          const j = await res.json(); render(j);
        }catch(e){ clearMenu(); }
      }
      input.addEventListener('input', ()=>{ if(t) clearTimeout(t); t=setTimeout(search, 150); });
      input.addEventListener('keydown', (ev)=>{
        const items = Array.from(menu.querySelectorAll('.suggest-item'));
        if(ev.key==='ArrowDown'){ ev.preventDefault(); if(items.length===0) return; activeIndex=(activeIndex+1)%items.length; items.forEach((a,i)=>a.classList.toggle('active', i===activeIndex)); }
        else if(ev.key==='ArrowUp'){ ev.preventDefault(); if(items.length===0) return; activeIndex=(activeIndex-1+items.length)%items.length; items.forEach((a,i)=>a.classList.toggle('active', i===activeIndex)); }
        else if(ev.key==='Enter'){ if(activeIndex>=0 && items[activeIndex]){ ev.preventDefault(); items[activeIndex].click(); } }
        else if(ev.key==='Escape'){ clearMenu(); }
      });
      menu.addEventListener('click', (ev)=>{
        const a = ev.target.closest('a.suggest-item'); if(!a) return; ev.preventDefault();
        const id = a.getAttribute('data-id'); const label = a.getAttribute('data-label');
        setSelected(id, label); input.value = label || ''; clearMenu();
      });
      document.addEventListener('click', (ev)=>{ if(!menu.contains(ev.target) && ev.target!==input){ clearMenu(); } });
      // Validate on submit
      try{
        const form = sel.closest('form'); if(form){
          form.addEventListener('submit', function(e){
            if(!sel.value){ e.preventDefault(); input.focus(); input.classList.add('invalid'); setTimeout(()=>input.classList.remove('invalid'), 800); }
          });
        }
      }catch(e){}
    }
  }catch(e){}
})();
</script>
<?php include __DIR__ . '/../layout_footer.php'; ?>
