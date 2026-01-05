<?php include __DIR__ . '/../layout_header.php'; $u=require_role('admin'); $pdo=db(); require_once __DIR__ . '/../lib/categories.php';
$q=trim($_GET['q']??''); $city=trim($_GET['city']??''); $country=trim($_GET['country']??''); $date_from=trim($_GET['date_from']??''); $date_to=trim($_GET['date_to']??'');
$geo_region=trim($_GET['geo_region']??''); $geo_city_id=trim($_GET['geo_city_id']??''); $geo_district_id=trim($_GET['geo_district_id']??'');
$job_group_id = isset($_GET['job_group_id']) ? (int)$_GET['job_group_id'] : 0;
$filter_category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$initialCatLabel = '';
if($filter_category_id>0){
  try{ $st=$pdo->prepare("SELECT COALESCE(path,name) AS label FROM categories WHERE id=?"); $st->execute([$filter_category_id]); $initialCatLabel = (string)($st->fetchColumn() ?: ''); }catch(Throwable $e){ $initialCatLabel=''; }
}
$filter_cat_desc = isset($_GET['include_descendants']) && $_GET['include_descendants']==='1';
$page=max(1,intval($_GET['page']??1));
$per = 100; if(function_exists('feature_enabled') && feature_enabled('pagination_enabled','0')){ $per = max(10, min(500, intval($_GET['per'] ?? 100))); }
$off=($page-1)*$per;
$where=['1=1']; $params=[];
if($q!==''){ $where[]="(l.phone LIKE :q OR l.name LIKE :q)"; $params[':q']="%$q%"; }
if($city!==''){ $where[]="l.city LIKE :city"; $params[':city']="%$city%"; }
if($country!==''){ $where[]="l.country LIKE :country"; $params[':country']="%$country%"; }
if($date_from!==''){ $where[]="substr(l.created_at,1,10) >= :df"; $params[':df']=$date_from; }
if($date_to!==''){ $where[]="substr(l.created_at,1,10) <= :dt"; $params[':dt']=$date_to; }
if($geo_region!==''){ $where[]="l.geo_region_code = :gr"; $params[':gr']=$geo_region; }
if($geo_city_id!==''){ $where[]="l.geo_city_id = :gci"; $params[':gci']=(int)$geo_city_id; }
if($geo_district_id!==''){ $where[]="l.geo_district_id = :gdi"; $params[':gdi']=(int)$geo_district_id; }
// Optional: filter by job_group_id when present (column may not exist on older installs)
if($job_group_id>0){
  // Best-effort: only add condition if column exists
  try{
    $cols = $pdo->query("PRAGMA table_info(leads)")->fetchAll(PDO::FETCH_ASSOC);
    $has = false; foreach($cols as $c){ if(($c['name']??$c['Name']??'')==='job_group_id'){ $has=true; break; } }
    if($has){ $where[] = 'l.job_group_id = :jg'; $params[':jg'] = $job_group_id; }
  }catch(Throwable $e){}
}
if($filter_category_id>0){
  if($filter_cat_desc){
    $ids = category_get_descendant_ids($filter_category_id);
    if(empty($ids)){ $ids = [$filter_category_id]; }
    // Build placeholders dynamically
    $ph = [];
    foreach($ids as $i=>$cid){ $k=":cid$i"; $ph[]=$k; $params[$k]=(int)$cid; }
    $where[] = 'l.category_id IN ('.implode(',', $ph).')';
  }else{
    $where[] = 'l.category_id = :cid_exact';
    $params[':cid_exact'] = $filter_category_id;
  }
}
$sql="SELECT l.id,l.name,l.phone,l.city,l.country,l.created_at,l.rating,l.website,l.email,l.gmap_types,l.source_url,l.social,l.category_id,l.lat,l.lon,l.geo_country,l.geo_region_code,l.geo_city_id,l.geo_district_id,l.geo_confidence,l.job_group_id,
              a.agent_id,a.status,u.name as agent_name,
              COALESCE(c.name, 'غير مُصنَّف (Legacy)') AS category_name,
              COALESCE(c.slug, 'legacy') AS category_slug,
              COALESCE(c.path, 'غير مُصنَّف (Legacy)') AS category_path
  FROM leads l LEFT JOIN assignments a ON a.lead_id=l.id LEFT JOIN users u ON u.id=a.agent_id
  LEFT JOIN categories c ON c.id=l.category_id
  WHERE ".implode(' AND ',$where)." ORDER BY l.id DESC LIMIT :lim OFFSET :off";
$stmt=$pdo->prepare($sql); foreach($params as $k=>$v){ $stmt->bindValue($k,$v); } $stmt->bindValue(':lim',$per,PDO::PARAM_INT); $stmt->bindValue(':off',$off,PDO::PARAM_INT); $stmt->execute(); $rows=$stmt->fetchAll();
$cnt=$pdo->prepare("SELECT COUNT(*) c FROM leads l LEFT JOIN assignments a ON a.lead_id=l.id WHERE ".implode(' AND ',$where)); $cnt->execute($params); $total=(int)$cnt->fetch()['c']; $pages=max(1,(int)ceil($total/$per));
$base_url=(isset($_SERVER['HTTPS'])?'https':'http').'://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
?>
<div class="card">
  <h2>كل الأرقام (Admin)</h2>
  <?php
    // Lightweight status bar (today added/duplicates, jobs snapshot, workers online)
    try{
      $day = date('Y-m-d');
      $uc = $pdo->prepare("SELECT kind, count FROM usage_counters WHERE day=? AND kind IN ('ingest_added','ingest_duplicates')");
      $uc->execute([$day]); $m=[]; foreach($uc->fetchAll() as $r){ $m[$r['kind']]=(int)$r['count']; }
      $jobs = [
        'processing'=>(int)$pdo->query("SELECT COUNT(*) c FROM internal_jobs WHERE status='processing'")->fetch()['c'],
        'queued'=>(int)$pdo->query("SELECT COUNT(*) c FROM internal_jobs WHERE status='queued'")->fetch()['c'],
        'done_24h'=>(int)$pdo->query("SELECT COUNT(*) c FROM internal_jobs WHERE status='done' AND finished_at > datetime('now','-24 hours')")->fetch()['c'],
        'failed_24h'=>(int)$pdo->query("SELECT COUNT(*) c FROM internal_jobs WHERE status='failed' AND updated_at > datetime('now','-24 hours')")->fetch()['c'],
      ];
      $workersOnline = (int)$pdo->query("SELECT COUNT(*) c FROM internal_workers WHERE last_seen > datetime('now','-120 seconds')")->fetch()['c'];
      echo '<div class="muted" style="margin:6px 0; display:flex; gap:12px; flex-wrap:wrap">'
        .'<span>اليوم: مضافة <span class="kbd">'.($m['ingest_added']??0).'</span> · مكررة <span class="kbd">'.($m['ingest_duplicates']??0).'</span></span>'
        .'<span>Jobs: قيد المعالجة <span class="kbd">'.$jobs['processing'].'</span> · بالانتظار <span class="kbd">'.$jobs['queued'].'</span> · ناجحة 24س <span class="kbd">'.$jobs['done_24h'].'</span> · فاشلة 24س <span class="kbd">'.$jobs['failed_24h'].'</span></span>'
        .'<span>Workers online <span class="kbd">'.$workersOnline.'</span></span>'
        .'</div>';
    }catch(Throwable $e){ /* ignore status bar errors */ }
  ?>
  <?php if(isset($_GET['wcode'])): ?><p class="badge">نتيجة وشـيج: HTTP <?php echo intval($_GET['wcode']); ?></p><?php endif; ?>
  <?php if(isset($_GET['sent_ok'])): ?><p class="badge">إرسال جماعي — ناجح: <?php echo intval($_GET['sent_ok']); ?> | فشل: <?php echo intval($_GET['sent_fail'] ?? 0); ?></p><?php endif; ?>
  <form class="searchbar" method="get" data-persist id="admin-leads-filters">
    <input type="text" name="q" placeholder="بحث بالاسم أو الرقم" value="<?php echo htmlspecialchars($q); ?>">
    <input type="text" name="city" placeholder="المدينة" value="<?php echo htmlspecialchars($city); ?>">
    <input type="text" name="country" placeholder="الدولة" value="<?php echo htmlspecialchars($country); ?>">
  <input type="date" name="date_from" placeholder="من تاريخ" value="<?php echo htmlspecialchars($date_from); ?>">
    <input type="date" name="date_to" placeholder="إلى تاريخ" value="<?php echo htmlspecialchars($date_to); ?>">
  <input type="text" name="geo_region" placeholder="رمز المنطقة (SA)" value="<?php echo htmlspecialchars($geo_region); ?>" title="رمز المنطقة مثل: MD، RD، MQ (SA)">
  <input type="number" name="geo_city_id" placeholder="CityID" value="<?php echo htmlspecialchars($geo_city_id); ?>" title="معرّف المدينة">
  <input type="number" name="geo_district_id" placeholder="DistrictID" value="<?php echo htmlspecialchars($geo_district_id); ?>" title="معرّف الحي">
  <input type="number" name="job_group_id" placeholder="JobGroupID" value="<?php echo htmlspecialchars((string)$job_group_id); ?>" title="معرّف مجموعة المهام (إن وجد)">
    <input type="hidden" name="category_id" value="<?php echo htmlspecialchars((string)$filter_category_id); ?>">
    <span class="typeahead" style="position:relative">
      <input type="text" id="cat-filter-input" placeholder="تصنيف (اكتب للبحث)" autocomplete="off" value="<?php echo htmlspecialchars($initialCatLabel); ?>" title="اختر التصنيف من القائمة">
      <div id="cat-filter-menu" class="suggest-menu"></div>
    </span>
    <label title="تضمين الفئات الفرعية"><input type="checkbox" name="include_descendants" value="1" <?php echo $filter_cat_desc?'checked':''; ?>> مع التوابع</label>
    <button class="btn">تصفية</button>
    <?php if(function_exists('feature_enabled') && feature_enabled('ui_persist_filters','0')): ?>
      <button class="btn" data-persist-reset title="تفريغ الحقول المحفوظة">إعادة التعيين</button>
    <?php endif; ?>
  </form>
  <?php
    // Applied filters chips
    $chips = [];
    $params0 = $_GET;
    $mk = function($label,$key) use (&$params0){ $p=$params0; unset($p[$key]); $q = http_build_query($p); return '<a class="chip" href="?'.htmlspecialchars($q).'" title="إزالة">'.htmlspecialchars($label).'<span class="chip-x">×</span></a>'; };
    if($q!=='') $chips[] = $mk('بحث: '.$q,'q');
    if($city!=='') $chips[] = $mk('مدينة: '.$city,'city');
  if($country!=='') $chips[] = $mk('دولة: '.$country,'country');
  if($geo_region!=='') $chips[] = $mk('منطقة: '.$geo_region,'geo_region');
  if($geo_city_id!=='') $chips[] = $mk('مدينةID: '.$geo_city_id,'geo_city_id');
  if($geo_district_id!=='') $chips[] = $mk('حيID: '.$geo_district_id,'geo_district_id');
    if($filter_category_id>0){ $chips[] = $mk('فئة: #'.$filter_category_id.($filter_cat_desc?' +التوابع':''),'category_id'); if($filter_cat_desc){ /* remove by clearing both */ } }
    if($date_from!=='') $chips[] = $mk('من: '.$date_from,'date_from');
    if($date_to!=='') $chips[] = $mk('إلى: '.$date_to,'date_to');
  if($job_group_id>0){ $chips[] = $mk('مجموعة: #'.$job_group_id,'job_group_id'); }
  if(!empty($chips)) echo '<div class="chips" style="margin:6px 0; display:flex; gap:6px; flex-wrap:wrap">'.implode('',$chips).'</div>';
  ?>
  <script nonce="<?php echo htmlspecialchars(csp_nonce()); ?>">
    (function(){
      try{
        const hidden = document.querySelector('form#admin-leads-filters input[name="category_id"]');
        const input = document.getElementById('cat-filter-input');
        const menu = document.getElementById('cat-filter-menu');
        if(!hidden || !input || !menu) return;
        let activeIndex = -1; let t=null;
        function clearMenu(){ menu.classList.remove('show'); menu.innerHTML=''; activeIndex=-1; }
        function render(items){
          if(!Array.isArray(items) || items.length===0){ clearMenu(); return; }
          menu.innerHTML = items.map(it=>{
            const icon = (it.icon && it.icon.type==='fa') ? `<i class="fa ${it.icon.value}"></i>` : '';
            const path = (it.path||it.name||'');
            return `<a href="#" class="suggest-item" data-id="${it.id}" data-label="${path.replace(/\"/g,'&quot;')}">${icon} <span class="suggest-name">${path}</span> <span class="muted">#${it.id}</span></a>`;
          }).join('');
          menu.classList.add('show');
        }
        async function search(){
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
          hidden.value = id || '';
          input.value = label || '';
          clearMenu();
        });
        document.addEventListener('click', (ev)=>{ if(!menu.contains(ev.target) && ev.target!==input){ clearMenu(); } });
      }catch(e){}
    })();
  </script>
  <div class="muted">الصفحة <?php echo $page; ?> من <?php echo $pages; ?> — إجمالي <?php echo $total; ?></div>
  <?php if($total>0): ?>
  <div class="row" style="gap:8px; margin:6px 0; align-items:center">
    <?php $query = $_GET; $query['csrf'] = csrf_token(); $exportUrl = linkTo('api/export_leads.php').'?'.http_build_query($query); ?>
    <a class="btn" href="<?php echo htmlspecialchars($exportUrl); ?>"><i class="fa fa-download"></i> تصدير CSV</a>
    <?php $exportX = linkTo('api/export_leads_excel.php').'?'.http_build_query($query); ?>
    <a class="btn" href="<?php echo htmlspecialchars($exportX); ?>"><i class="fa fa-file-excel-o"></i> تصدير Excel</a>
    <?php $exportXX = linkTo('api/export_leads_xlsx.php').'?'.http_build_query($query); ?>
    <a class="btn" href="<?php echo htmlspecialchars($exportXX); ?>"><i class="fa fa-file-excel"></i> تصدير XLSX</a>
    <?php $qPhones = array_merge($query, ['dedupe'=>1]); $exportPhonesCsv = linkTo('api/export_phones.php').'?'.http_build_query(array_merge($qPhones, ['format'=>'csv'])); ?>
    <a class="btn" href="<?php echo htmlspecialchars($exportPhonesCsv); ?>" title="أرقام فقط — عمود واحد"><i class="fa fa-list-ol"></i> تصدير أرقام فقط (CSV)</a>
    <?php $exportPhonesTxt = linkTo('api/export_phones.php').'?'.http_build_query(array_merge($qPhones, ['format'=>'txt'])); ?>
    <a class="btn" href="<?php echo htmlspecialchars($exportPhonesTxt); ?>" title="سطر لكل رقم"><i class="fa fa-file-text"></i> تصدير أرقام فقط (TXT)</a>
  <a class="btn" href="<?php echo linkTo('admin/geo.php'); ?>" target="_blank" rel="noopener" title="إدارة قاعدة السعودية">SA Geo</a>
  </div>
  <form method="post" action="<?php echo linkTo('actions/bulk_washeej.php'); ?>" onsubmit="return confirm('سيتم إرسال رسائل وشـيج للأرقام المحددة. متابعة؟');" data-loading data-bulk-threshold="200">
    <?php echo csrf_input(); ?>
    <input type="hidden" name="back" value="<?php echo htmlspecialchars($base_url); ?>">
    <div style="margin:8px 0; display:flex; gap:8px; align-items:center">
      <button class="btn primary" disabled>إرسال وشـيج للمحدد</button>
      <button type="button" class="btn" data-copy-selected title="نسخ الأرقام المحددة">نسخ الأرقام المحددة</button>
      <button type="button" class="btn" data-select-none title="إلغاء تحديد الكل">إلغاء الكل</button>
      <button type="button" class="btn" data-select-invert title="عكس التحديد">عكس التحديد</button>
      <span class="muted">المحدد: <span class="kbd" data-selected-count>0</span></span>
    </div>
  <table data-dt="1" data-table-key="admin:leads" data-sticky-first="1">
  <thead><tr>
    <th data-required="1"><input type="checkbox" data-select-all></th>
    <th data-required="1">#</th>
    <th data-default="1">الاسم</th>
    <th data-default="1">الهاتف</th>
    <th>التقييم</th>
    <th>الموقع</th>
    <th>إحداثيات</th>
    <th>Geo</th>
    <th>روابط</th>
  <th>تصنيف</th>
  <th>مسار الفئة</th>
    <th>مجموعة</th>
    <th data-default="1">تاريخ</th>
    <th>المندوب</th>
    <th>الحالة</th>
    <th data-required="1">فردي</th>
    <th>شرح التصنيف</th>
  </tr></thead>
      <tbody>
        <?php foreach($rows as $r): ?>
          <tr>
            <td><input type="checkbox" name="lead_ids[]" value="<?php echo $r['id']; ?>" data-phone="<?php echo htmlspecialchars($r['phone']); ?>"></td>
            <td class="kbd"><?php echo $r['id']; ?></td>
            <td title="<?php echo htmlspecialchars($r['name']); ?>"><?php echo htmlspecialchars($r['name']); ?></td>
            <td title="<?php echo htmlspecialchars($r['phone']); ?>"><?php echo htmlspecialchars($r['phone']); ?></td>
            <td><?php echo $r['rating']!==null ? htmlspecialchars($r['rating']) : '—'; ?></td>
            <td title="<?php echo htmlspecialchars($r['city'].' • '.$r['country']); ?>"><?php echo htmlspecialchars($r['city'].' • '.$r['country']); ?></td>
            <td class="muted nowrap"><?php echo ($r['lat']!==null && $r['lon']!==null)? (htmlspecialchars(number_format((float)$r['lat'],6)).', '.htmlspecialchars(number_format((float)$r['lon'],6))) : '—'; ?></td>
            <td class="muted" title="<?php echo ($r['geo_city_id']||$r['geo_region_code'])? ('SA•'.htmlspecialchars($r['geo_region_code']).' c#'.htmlspecialchars((string)$r['geo_city_id']).' d#'.htmlspecialchars((string)$r['geo_district_id']).' · conf '.htmlspecialchars((string)$r['geo_confidence'])) : '—'; ?>"><?php echo ($r['geo_city_id']||$r['geo_region_code'])? ('SA•'.htmlspecialchars($r['geo_region_code']).' c#'.htmlspecialchars((string)$r['geo_city_id']).' d#'.htmlspecialchars((string)$r['geo_district_id']).' · conf '.htmlspecialchars((string)$r['geo_confidence'])) : '—'; ?></td>
            <td>
              <?php if(!empty($r['website'])): ?><a class="btn small" target="_blank" rel="noopener" href="<?php echo htmlspecialchars($r['website']); ?>">Website</a><?php endif; ?>
              <?php if(!empty($r['source_url'])): ?><a class="btn small" target="_blank" rel="noopener" href="<?php echo htmlspecialchars($r['source_url']); ?>">Maps</a><?php endif; ?>
              <?php if(!empty($r['email'])): ?><span class="badge">Email</span><?php endif; ?>
              <?php if(!empty($r['social'])): $soc=json_decode($r['social'],true); if(is_array($soc)): ?>
                <?php foreach(['facebook','instagram','twitter','snapchat','tiktok','linkedin'] as $k): if(!empty($soc[$k])): ?>
                  <a class="btn small" target="_blank" rel="noopener" href="<?php echo htmlspecialchars($soc[$k]); ?>" title="<?php echo htmlspecialchars(ucfirst($k)); ?>"><i class="fa fa-<?php echo $k==='twitter'?'twitter':'external-link'; ?>"></i> <?php echo htmlspecialchars(ucfirst($k)); ?></a>
                <?php endif; endforeach; ?>
              <?php endif; endif; ?>
            </td>
            <td><?php echo htmlspecialchars($r['category_name']); ?></td>
            <td class="muted" title="#<?php echo (int)$r['category_id']; ?>"><?php echo htmlspecialchars($r['category_path']); ?></td>
            <td class="muted nowrap"><?php echo isset($r['job_group_id']) && $r['job_group_id'] ? ('#'.(int)$r['job_group_id']) : '—'; ?></td>
            <td class="muted nowrap" title="<?php echo htmlspecialchars($r['created_at']); ?>"><?php echo $r['created_at']; ?></td>
            <td><?php echo $r['agent_name'] ? htmlspecialchars($r['agent_name']) : '—'; ?></td>
            <td><span class="badge"><?php echo htmlspecialchars($r['status'] ?? '—'); ?></span></td>
            <td>
              <form method="post" action="<?php echo linkTo('actions/send_washeej.php'); ?>">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="lead_id" value="<?php echo $r['id']; ?>">
                <input type="hidden" name="phone" value="<?php echo htmlspecialchars($r['phone']); ?>">
                <input type="hidden" name="name" value="<?php echo htmlspecialchars($r['name']); ?>">
                <input type="hidden" name="back" value="<?php echo htmlspecialchars($base_url); ?>">
                <button class="btn">إرسال</button>
              </form>
            </td>
            <td>
              <button type="button" class="btn small" onclick="explainCls(<?php echo (int)$r['id']; ?>)"><i class="fa fa-info-circle"></i></button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </form>
  <?php else: ?>
    <div class="empty mt-3">
      <i class="fa-solid fa-address-book"></i>
      <h3>لا توجد أرقام مطابقة</h3>
      <p>جرّب تعديل معايير البحث في الأعلى أو إزالة بعض المرشحات.</p>
    </div>
  <?php endif; ?>
  <?php if($pages>1): ?>
  <div class="searchbar">
    <a class="btn" href="?<?php echo http_build_query(array_merge($_GET,['page'=>1])); ?>">الأولى</a>
    <?php if($page>1): ?><a class="btn" href="?<?php echo http_build_query(array_merge($_GET,['page'=>$page-1])); ?>">السابق</a><?php endif; ?>
    <?php if($page<$pages): ?><a class="btn" href="?<?php echo http_build_query(array_merge($_GET,['page'=>$page+1])); ?>">التالي</a><?php endif; ?>
    <a class="btn" href="?<?php echo http_build_query(array_merge($_GET,['page'=>$pages])); ?>">الأخيرة</a>
    <?php if(function_exists('feature_enabled') && feature_enabled('pagination_enabled','0')): ?>
      <span class="muted"> | انتقال سريع</span>
      <form method="get" style="display:inline-flex; gap:6px; align-items:center">
        <?php foreach($_GET as $k=>$v){ if($k==='page'||$k==='per') continue; ?><input type="hidden" name="<?php echo htmlspecialchars($k); ?>" value="<?php echo htmlspecialchars(is_array($v)?'':$v); ?>"><?php } ?>
        <label>صفحة <input type="number" name="page" min="1" max="<?php echo $pages; ?>" value="<?php echo $page; ?>" style="width:80px"></label>
        <label>حجم الصفحة
          <select name="per">
            <?php foreach([25,50,100,200,500] as $pp): ?><option value="<?php echo $pp; ?>" <?php echo ($per===$pp)?'selected':''; ?>><?php echo $pp; ?></option><?php endforeach; ?>
          </select>
        </label>
        <button class="btn">اذهب</button>
        <span class="muted" style="margin-inline-start:8px">
          <?php $from=($off+1); $to=min($total, $off+$per); echo $from.'–'.$to.' من '.$total; ?>
        </span>
      </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<div id="explain-modal" class="modal-backdrop" style="display:none;align-items:center; justify-content:center;">
  <div class="modal" style="min-width:360px; max-width:640px;">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; color:var(--text)">
      <strong>شرح التصنيف</strong>
      <button class="btn outline" onclick="document.getElementById('explain-modal').style.display='none'">إغلاق</button>
    </div>
    <div id="explain-body" class="muted">...</div>
  </div>
  <script nonce="<?php echo htmlspecialchars(csp_nonce()); ?>">
  async function explainCls(id){
    const modal = document.getElementById('explain-modal');
    const body = document.getElementById('explain-body');
    body.textContent = 'جارٍ التحميل...'; modal.style.display='flex'; modal.classList.add('show');
    try{
      const res = await fetch('<?php echo linkTo('api/classify_explain.php'); ?>', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({id, csrf: '<?php echo htmlspecialchars(csrf_token()); ?>'})});
      const j = await res.json();
      if(!j.ok) throw new Error('failed');
      let html = '';
      html += '<div>الفئة المقترحة: <strong>'+(j.category_name||'—')+'</strong> (#'+(j.category_id||'—')+')</div>';
      html += '<div>النتيجة: '+j.score+' — العتبة: '+j.threshold+'</div>';
      if(Array.isArray(j.matched) && j.matched.length){
        html += '<div style="margin-top:8px">القواعد/الكلمات المتطابقة:</div>';
        html += '<ul>';
        for(const m of j.matched){
          if(m.kind==='kw-name' || m.kind==='kw-types'){
            html += `<li>${m.kind} — kw: <code>${(m.kw||'')}</code> — w=${m.w}</li>`;
          }else{
            html += `<li>rule — target=<code>${(m.target||'')}</code>, mode=<code>${(m.mode||'')}</code>, w=${m.w}, pattern=<code>${(m.p||'')}</code></li>`;
          }
        }
        html += '</ul>';
      }else{
        html += '<div class="muted">لا توجد مطابقات مفسِّرة.</div>';
      }
      body.innerHTML = html;
    }catch(e){ body.textContent = 'خطأ أثناء جلب التفسير'; }
  }
  </script>
</div>
<?php include __DIR__ . '/../layout_footer.php'; ?>
