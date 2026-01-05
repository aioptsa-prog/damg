<?php include __DIR__ . '/../layout_header.php'; require_once __DIR__ . '/../lib/providers.php'; require_once __DIR__ . '/../lib/system.php'; $u=require_role('agent'); $pdo=db();
$msg=null;$summary=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(system_is_globally_stopped() || system_is_in_pause_window()){ $msg='النظام متوقف مؤقتًا بقرار المدير. برجاء المحاولة لاحقًا.'; $_POST=[]; }
  if(!csrf_verify($_POST['csrf'] ?? '')){ $msg='CSRF فشل التحقق'; $_POST=[]; }
  $q = trim($_POST['q'] ?? '');
  $ll = trim($_POST['ll'] ?? get_setting('default_ll',''));
  $city_hint = trim($_POST['city'] ?? '');
  $radius_km = max(1, intval($_POST['radius_km'] ?? get_setting('default_radius_km','25')));
  $lang = get_setting('default_language','ar'); $region = get_setting('default_region','sa');
  $key = get_setting('google_api_key',''); $fsq = get_setting('foursquare_api_key','');
  $preview = isset($_POST['preview_only']); $internal = get_setting('internal_server_enabled','0')==='1';
  if($q===''){ $msg='أدخل الاستعلام'; }
  else if(!preg_match('/^-?\d+(?:\.\d+)?,\s*-?\d+(?:\.\d+)?$/', $ll)){ $msg='صيغة LL غير صحيحة. مثال: 24.7136,46.6753'; }
  else{
    // Validate lat/lng and radius; normalize LL
    $parts = array_map('trim', explode(',', $ll));
    $lat = (float)$parts[0]; $lng = (float)$parts[1];
    if($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180){
      $msg = 'إحداثيات خارج النطاق المسموح (lat: -90..90, lng: -180..180)';
    }
    $radius_km = max(1, min(100, (int)$radius_km));
    $ll = sprintf('%.6f,%.6f', $lat, $lng);
  }
  if(!$msg){
    if($internal){
      $target = isset($_POST['target_count']) && $_POST['target_count']!=='' ? max(1, intval($_POST['target_count'])) : null;
      $stmt=$pdo->prepare("INSERT INTO internal_jobs(requested_by_user_id,role,agent_id,query,ll,radius_km,lang,region,status,target_count,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?, 'queued', ?, datetime('now'), datetime('now'))");
      $stmt->execute([$u['id'],'agent',$u['id'],$q,$ll,$radius_km,$lang,$region,$target]);
      $job_id=$pdo->lastInsertId();
      // Best-effort: attach city_hint in payload_json if supported
      try{
        $cols = $pdo->query("PRAGMA table_info(internal_jobs)")->fetchAll(PDO::FETCH_ASSOC);
        $has = function($n) use ($cols){ foreach($cols as $c){ if(($c['name']??$c['Name']??'')===$n) return true; } return false; };
        if($has('payload_json')){
          $payload = ['query'=>$q,'center'=>$ll,'radius_km'=>$radius_km,'language'=>$lang,'region'=>$region];
          if($city_hint!==''){ $payload['city_hint']=$city_hint; }
          $pj = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
          $sqlU = $has('job_type') ? "UPDATE internal_jobs SET job_type='places_api_search', payload_json=:p WHERE id=:id" : "UPDATE internal_jobs SET payload_json=:p WHERE id=:id";
          $stU=$pdo->prepare($sqlU); $stU->execute([':p'=>$pj, ':id'=>$job_id]);
        }
      }catch(Throwable $e){}
      $msg='تمت إضافة مهمة إلى السيرفر الداخلي (#'.$job_id.')';
    } else {
  $opts=['q'=>$q,'ll'=>$ll,'radius_km'=>$radius_km,'lang'=>$lang,'region'=>$region,'google_key'=>$key,'foursquare_key'=>$fsq,'preview'=>$preview,'role'=>'agent','user_id'=>$u['id']];
      if($city_hint!==''){ $opts['city_hint'] = $city_hint; }
  if(isset($_POST['exhaustive'])){ $opts['exhaustive'] = true; }
  if(isset($_POST['ignore_tile_ttl'])){ $opts['ignore_tile_ttl'] = true; }
      $summary=orchestrate_fetch($opts); $msg=$preview?('معاينة: '.(int)$summary['preview']):('تمت إضافة '.(int)$summary['added'].' رقم');
    }
  }
}
?>
<div class="card">
  <h2>جلب (Providers) — خاص بالمندوب</h2>
  <?php if(system_is_globally_stopped() || system_is_in_pause_window()): ?>
    <p class="badge danger">النظام متوقف مؤقتًا. لا يمكن تنفيذ الجلب الآن.</p>
  <?php endif; ?>
  <?php if($msg): ?><p class="badge"><?php echo htmlspecialchars($msg); ?></p><?php endif; ?>
  <?php if($summary): ?><div class="card"><h3>تفاصيل العملية</h3><p class="muted">OSM: <?php echo intval($summary['by']['osm'] ?? 0); ?> • Foursquare: <?php echo intval($summary['by']['foursquare'] ?? 0); ?> • Mapbox: <?php echo intval($summary['by']['mapbox'] ?? 0); ?> • Radar: <?php echo intval($summary['by']['radar'] ?? 0); ?> • Google Preview IDs: <?php echo intval($summary['by']['google_preview'] ?? 0); ?> • أُضيف: <?php echo intval($summary['added']); ?> • متبقي من الكاب: <?php echo intval($summary['cap_remaining']); ?></p><?php if(!empty($summary['errors'])): ?><p class="badge danger">أخطاء: <?php echo htmlspecialchars(implode(',', $summary['errors'])); ?></p><?php endif; ?></div><?php endif; ?>
  <form method="post" class="grid-3">
    <?php echo csrf_input(); ?>
    <div><label>الاستعلام</label><input name="q" required placeholder="مثال: صالون نسائي الدمام"></div>
  <div style="position:relative"><label>اسم المدينة</label><input name="city" value="<?php echo htmlspecialchars(get_setting('default_city','الرياض')); ?>" placeholder="مثال: الرياض" title="يمكن تعبئتها تلقائياً من الخريطة" autocomplete="off"><div id="city-suggest" class="suggest-menu"></div></div>
    <div><label>LL (lat,lng)</label><input name="ll" value="<?php echo htmlspecialchars(get_setting('default_ll','24.638916,46.716010')); ?>" placeholder="24.638916,46.716010"></div>
    <div><label>نصف القطر (كم)</label><input type="number" name="radius_km" value="<?php echo htmlspecialchars(get_setting('default_radius_km','25')); ?>"></div>
    <div><label><input type="checkbox" name="preview_only" value="1"> معاينة بلا تكلفة (IDs Only)</label></div>
    <div style="display:flex;gap:12px;align-items:center">
      <label style="margin:0"><input type="checkbox" name="exhaustive" value="1" <?php echo get_setting('fetch_exhaustive','0')==='1'?'checked':''; ?>> مسح شامل (شبكة نقاط)</label>
      <label style="margin:0"><input type="checkbox" name="ignore_tile_ttl" value="1"> تجاهل فترة التحديث (TTL)</label>
    </div>
    <div class="badge">المتبقي اليوم من Google Details: <?php echo cap_remaining_google_details(); ?></div>
  <div style="grid-column:1/-1">
    <label>اختر الموقع على الخريطة</label>
    <div id="pickmap" style="height:360px;border-radius:12px;overflow:hidden;border:1px solid var(--border);margin:6px 0"></div>
    <div class="row" style="justify-content:flex-end;margin-top:4px">
      <button type="button" class="btn small" id="btn-refit-map" title="إعادة ضبط إطار الخريطة">إعادة ضبط الإطار</button>
    </div>
  </div>
  <?php if(get_setting('internal_server_enabled','0')==='1'): ?>
  <div><label>عدد الأرقام المطلوب الوصول إليها</label><input type="number" min="1" step="1" name="target_count" placeholder="مثال: 100"></div>
  <?php endif; ?>
    <div style="grid-column:1/-1"><button class="btn primary" <?php echo (system_is_globally_stopped()||system_is_in_pause_window())?'disabled':''; ?>>جلب الآن</button></div>
  </form>
</div>
<script nonce="<?php echo htmlspecialchars(csp_nonce()); ?>">
(function(){
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
  // Resilient tile layer with fallbacks + diagnostics
  (function(){
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
    }catch(e){}
  })();
  let marker=null;
  let areaCircle=null;
  function fitToCircle(){
    try{
      if(!areaCircle) return;
      const bounds = areaCircle.getBounds();
      try{ map.invalidateSize(false); }catch(e){}
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
    }catch(e){}
  }
  let revTimer = null;
  async function reverseCity(lat, lng, force=false){
    try{
  const res = await fetch('<?php echo linkTo('api/geo_point_city.php'); ?>', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body: JSON.stringify({lat:lat, lng:lng, strict_city:true, csrf:'<?php echo htmlspecialchars(csrf_token()); ?>'})});
      const j = await res.json();
      const active = document.activeElement===cityInput;
      if(j && j.ok && j.city && cityInput && (force || (!active && cityInput.value.trim()===''))){ cityInput.value = j.city; }
    }catch(e){}
  }
  function scheduleReverse(lat,lng,force=false){ if(revTimer){ clearTimeout(revTimer); } revTimer = setTimeout(()=>reverseCity(lat,lng,force), 500); }
  function setLL(latlng, forceCity=false, fit=false){ if(!marker){ marker=L.marker(latlng,{draggable:true}).addTo(map); marker.on('drag',()=>{const ps=marker.getLatLng(); llInput.value=ps.lat.toFixed(6)+','+ps.lng.toFixed(6); updateCircle(ps, false); scheduleReverse(ps.lat, ps.lng, true);}); marker.on('dragend',()=>{const ps=marker.getLatLng(); llInput.value=ps.lat.toFixed(6)+','+ps.lng.toFixed(6); updateCircle(ps, true); reverseCity(ps.lat, ps.lng, true);}); } else { marker.setLatLng(latlng); } llInput.value=latlng.lat.toFixed(6)+','+latlng.lng.toFixed(6); updateCircle(latlng, fit); reverseCity(latlng.lat, latlng.lng, forceCity); }
  map.on('click', e => setLL(e.latlng, true));
  if(def){ setLL({lat:center[0],lng:center[1]}, true, true); } else { setLL({lat:center[0],lng:center[1]}, true, true); }
  if(radInput){
    radInput.addEventListener('input', ()=>{ if(marker){ updateCircle(marker.getLatLng(), true); } });
    radInput.addEventListener('change', ()=>{ if(marker){ updateCircle(marker.getLatLng(), true); } });
  }
  map.on('resize', function(){ fitToCircle(); });
  try{ var refitBtn = document.getElementById('btn-refit-map'); if(refitBtn){ refitBtn.addEventListener('click', function(){ fitToCircle(); }); } }catch(e){}
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
})();
</script>
<?php include __DIR__ . '/../layout_footer.php'; ?>
