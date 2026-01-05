<?php include __DIR__ . '/../layout_header.php'; $u=require_role('agent'); $pdo=db();
$q=trim($_GET['q']??''); $city=trim($_GET['city']??''); $country=trim($_GET['country']??''); $date_from=trim($_GET['date_from']??''); $date_to=trim($_GET['date_to']??''); $geo_region=trim($_GET['geo_region']??''); $geo_city_id=trim($_GET['geo_city_id']??''); $geo_district_id=trim($_GET['geo_district_id']??''); $tab=trim($_GET['tab']??'today'); $today=date('Y-m-d'); $page=max(1,intval($_GET['page']??1));
$per=100; if(function_exists('feature_enabled') && feature_enabled('pagination_enabled','0')){ $per = max(10, min(500, intval($_GET['per'] ?? 100))); }
$off=($page-1)*$per;
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['aid']) && isset($_POST['status'])){
  $ok=false; $msg='';
  if(!csrf_verify($_POST['csrf'] ?? '')){ $msg='CSRF'; }
  else {
    $aid=(int)$_POST['aid']; $status=$_POST['status']; $stmt=$pdo->prepare("UPDATE assignments SET status=? WHERE id=? AND agent_id=?"); $stmt->execute([$status,$aid,$u['id']]); $ok=true;
  }
  if(isset($_POST['ajax']) && $_POST['ajax']=='1'){
    header('Content-Type: application/json'); echo json_encode(['ok'=>$ok,'msg'=>$msg]); exit;
  }
}
$where=["a.agent_id=:aid"]; $params=[':aid'=>$u['id']]; if($tab==='today'){ $where[]="substr(l.created_at,1,10)=:d"; $params[':d']=$today; }
if($q!==''){ $where[]="(l.phone LIKE :q OR l.name LIKE :q)"; $params[':q']="%$q%"; }
if($city!==''){ $where[]="l.city LIKE :city"; $params[':city']="%$city%"; }
if($country!==''){ $where[]="l.country LIKE :country"; $params[':country']="%$country%"; }
if($tab==='prev'){
  if($date_from!==''){ $where[]="substr(l.created_at,1,10) >= :df"; $params[':df']=$date_from; }
  if($date_to!==''){ $where[]="substr(l.created_at,1,10) <= :dt"; $params[':dt']=$date_to; }
  if($geo_region!==''){ $where[]="l.geo_region_code = :gr"; $params[':gr']=$geo_region; }
  if($geo_city_id!==''){ $where[]="l.geo_city_id = :gci"; $params[':gci']=(int)$geo_city_id; }
  if($geo_district_id!==''){ $where[]="l.geo_district_id = :gdi"; $params[':gdi']=(int)$geo_district_id; }
}
$sql="SELECT a.id as aid,a.status,l.id,l.name,l.phone,l.city,l.country,l.created_at,l.lat,l.lon,l.geo_region_code,l.geo_city_id,l.geo_district_id,l.geo_confidence FROM assignments a JOIN leads l ON l.id=a.lead_id WHERE ".implode(' AND ',$where)." ORDER BY l.id DESC LIMIT :lim OFFSET :off";
$stmt=$pdo->prepare($sql); foreach($params as $k=>$v){ $stmt->bindValue($k,$v); } $stmt->bindValue(':lim',$per,PDO::PARAM_INT); $stmt->bindValue(':off',$off,PDO::PARAM_INT); $stmt->execute(); $rows=$stmt->fetchAll();
$cnt=$pdo->prepare("SELECT COUNT(*) c FROM assignments a JOIN leads l ON l.id=a.lead_id WHERE ".implode(' AND ',$where)); $cnt->execute($params); $total=(int)$cnt->fetch()['c']; $pages=max(1,(int)ceil($total/$per));
$base_url=(isset($_SERVER['HTTPS'])?'https':'http').'://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
?>
<div class="card">
  <h2>لوحة المندوب — <?php echo htmlspecialchars($u['name']); ?></h2>
  <?php if(isset($_GET['wcode'])): ?><p class="badge">نتيجة وشـيج: HTTP <?php echo intval($_GET['wcode']); ?></p><?php endif; ?>
  <?php if(isset($_GET['sent_ok'])): ?><p class="badge">إرسال جماعي — ناجح: <?php echo intval($_GET['sent_ok']); ?> | فشل: <?php echo intval($_GET['sent_fail'] ?? 0); ?></p><?php endif; ?>
  <div class="tabs"><a class="<?php echo $tab==='today'?'active':''; ?>" href="?tab=today">وارد اليوم</a><a class="<?php echo $tab==='prev'?'active':''; ?>" href="?tab=prev">الأيام السابقة</a></div>
  <form class="searchbar" method="get" data-persist id="agent-dash-filters">
    <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
    <input type="text" name="q" placeholder="بحث بالاسم أو الرقم" value="<?php echo htmlspecialchars($q); ?>">
    <?php if($tab==='prev'): ?>
      <input type="text" name="city" placeholder="المدينة" value="<?php echo htmlspecialchars($city); ?>">
      <input type="text" name="country" placeholder="الدولة" value="<?php echo htmlspecialchars($country); ?>">
      <input type="date" name="date_from" placeholder="من تاريخ" value="<?php echo htmlspecialchars($date_from); ?>">
      <input type="date" name="date_to" placeholder="إلى تاريخ" value="<?php echo htmlspecialchars($date_to); ?>">
      <input type="text" name="geo_region" placeholder="رمز المنطقة (SA)" value="<?php echo htmlspecialchars($geo_region); ?>">
      <input type="number" name="geo_city_id" placeholder="CityID" value="<?php echo htmlspecialchars($geo_city_id); ?>">
      <input type="number" name="geo_district_id" placeholder="DistrictID" value="<?php echo htmlspecialchars($geo_district_id); ?>">
    <?php endif; ?>
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
    if($tab==='prev'){
      if($city!=='') $chips[] = $mk('مدينة: '.$city,'city');
      if($country!=='') $chips[] = $mk('دولة: '.$country,'country');
      if($date_from!=='') $chips[] = $mk('من: '.$date_from,'date_from');
      if($date_to!=='') $chips[] = $mk('إلى: '.$date_to,'date_to');
      if($geo_region!=='') $chips[] = $mk('منطقة: '.$geo_region,'geo_region');
      if($geo_city_id!=='') $chips[] = $mk('مدينةID: '.$geo_city_id,'geo_city_id');
      if($geo_district_id!=='') $chips[] = $mk('حيID: '.$geo_district_id,'geo_district_id');
    }
    if(!empty($chips)) echo '<div class="chips" style="margin:6px 0; display:flex; gap:6px; flex-wrap:wrap">'.implode('',$chips).'</div>';
  ?>
  <div class="muted">الصفحة <?php echo $page; ?> من <?php echo $pages; ?> — إجمالي <?php echo $total; ?></div>
  <div class="row" style="gap:8px; margin:6px 0; align-items:center">
    <?php 
      $query = $_GET; 
      // reflect agent tab scope in export
      if(($query['tab'] ?? '')==='today'){ $query['today'] = '1'; } 
      unset($query['tab']); 
      $query['csrf'] = csrf_token(); 
      $exportUrl = linkTo('api/export_leads.php').'?'.http_build_query($query); 
    ?>
    <a class="btn" href="<?php echo htmlspecialchars($exportUrl); ?>"><i class="fa fa-download"></i> تصدير CSV</a>
    <?php $exportXX = linkTo('api/export_leads_xlsx.php').'?'.http_build_query($query); ?>
    <a class="btn" href="<?php echo htmlspecialchars($exportXX); ?>"><i class="fa fa-file-excel"></i> تصدير XLSX</a>
    <?php if($tab==='prev'): ?><a class="btn" href="<?php echo linkTo('admin/geo.php'); ?>" target="_blank" title="إدارة قاعدة السعودية">SA Geo</a><?php endif; ?>
  </div>
  <form method="post" action="<?php echo linkTo('actions/bulk_washeej.php'); ?>" onsubmit="return confirm('سيتم إرسال رسائل وشـيج للأرقام المحددة. متابعة؟');" data-loading>
    <?php echo csrf_input(); ?>
    <input type="hidden" name="back" value="<?php echo htmlspecialchars($base_url); ?>">
    <div style="margin:8px 0; display:flex; gap:8px; align-items:center">
  <button class="btn primary" disabled>إرسال وشـيج للمحدد</button>
      <button type="button" class="btn" data-copy-selected title="نسخ الأرقام المحددة">نسخ الأرقام المحددة</button>
      <button type="button" class="btn" data-select-none title="إلغاء تحديد الكل">إلغاء الكل</button>
      <button type="button" class="btn" data-select-invert title="عكس التحديد">عكس التحديد</button>
      <span class="muted">المحدد: <span class="kbd" data-selected-count>0</span></span>
    </div>
  <table data-dt="1" data-table-key="agent:dashboard:leads">
  <thead><tr>
    <th data-required="1"><input type="checkbox" data-select-all></th>
    <th data-required="1">#</th>
    <th data-default="1">الاسم</th>
    <th data-default="1">الهاتف</th>
    <th>الموقع</th>
    <th>إحداثيات</th>
    <th>Geo</th>
    <th data-default="1">تاريخ</th>
    <th>الحالة</th>
    <th>واتساب</th>
    <th data-required="1">إجراء</th>
  </tr></thead>
      <tbody>
        <?php foreach($rows as $r): ?>
        <tr>
          <td><input type="checkbox" name="lead_ids[]" value="<?php echo $r['id']; ?>" data-phone="<?php echo htmlspecialchars($r['phone']); ?>"></td>
          <td class="kbd"><?php echo $r['id']; ?></td>
          <td title="<?php echo htmlspecialchars($r['name']); ?>"><?php echo htmlspecialchars($r['name']); ?></td>
          <td title="<?php echo htmlspecialchars($r['phone']); ?>"><?php echo htmlspecialchars($r['phone']); ?> <button type="button" class="btn small" data-copy data-copy-text="<?php echo htmlspecialchars($r['phone']); ?>" title="نسخ الرقم"><i class="fa fa-copy"></i></button></td>
          <td title="<?php echo htmlspecialchars($r['city'].' • '.$r['country']); ?>"><?php echo htmlspecialchars($r['city'].' • '.$r['country']); ?></td>
          <td class="muted nowrap"><?php echo ($r['lat']!==null && $r['lon']!==null)? (htmlspecialchars(number_format((float)$r['lat'],6)).', '.htmlspecialchars(number_format((float)$r['lon'],6))) : '—'; ?></td>
          <td class="muted" title="<?php echo ($r['geo_city_id']||$r['geo_region_code'])? ('SA•'.htmlspecialchars($r['geo_region_code']).' c#'.htmlspecialchars((string)$r['geo_city_id']).' d#'.htmlspecialchars((string)$r['geo_district_id']).' · conf '.htmlspecialchars((string)$r['geo_confidence'])) : '—'; ?>"><?php echo ($r['geo_city_id']||$r['geo_region_code'])? ('SA•'.htmlspecialchars($r['geo_region_code']).' c#'.htmlspecialchars((string)$r['geo_city_id']).' d#'.htmlspecialchars((string)$r['geo_district_id']).' · conf '.htmlspecialchars((string)$r['geo_confidence'])) : '—'; ?></td>
          <td class="muted nowrap" title="<?php echo htmlspecialchars($r['created_at']); ?>"><?php echo $r['created_at']; ?></td>
          <td><span class="badge"><?php echo htmlspecialchars($r['status']); ?></span></td>
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
            <form method="post" class="grid-2" data-ajax>
              <?php echo csrf_input(); ?>
              <input type="hidden" name="aid" value="<?php echo $r['aid']; ?>">
              <input type="hidden" name="ajax" value="1">
              <select name="status">
                <option value="new" <?php echo $r['status']==='new'?'selected':''; ?>>جديد</option>
                <option value="attempted" <?php echo $r['status']==='attempted'?'selected':''; ?>>محاولة اتصال</option>
                <option value="connected" <?php echo $r['status']==='connected'?'selected':''; ?>>تم الاتصال</option>
                <option value="no_answer" <?php echo $r['status']==='no_answer'?'selected':''; ?>>لم يتم الرد</option>
                <option value="wrong_number" <?php echo $r['status']==='wrong_number'?'selected':''; ?>>رقم خاطئ</option>
                <option value="closed" <?php echo $r['status']==='closed'?'selected':''; ?>>مغلق</option>
              </select>
              <button class="btn">حفظ</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </form>
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
<?php include __DIR__ . '/../layout_footer.php'; ?>
