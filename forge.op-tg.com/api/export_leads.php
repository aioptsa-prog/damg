<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/categories.php';
$__UNIT = defined('UNIT_TEST') && UNIT_TEST;
if(!$__UNIT){ header('Content-Type: text/csv; charset=utf-8'); }

$u = current_user();
if(!$u){ http_response_code(403); echo 'forbidden'; if($__UNIT){ return; } else { exit; } }

// Optional CSRF check via query param to mitigate CSRF in GET downloads
$csrf = $_GET['csrf'] ?? '';
if(!csrf_verify($csrf)){
  http_response_code(400);
  echo 'csrf';
  if($__UNIT){ return; } else { exit; }
}

$pdo = db();
$role = $u['role'] ?? 'agent';

// Filters
$q = trim($_GET['q'] ?? '');
$city = trim($_GET['city'] ?? '');
$country = trim($_GET['country'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? ''); // YYYY-MM-DD
$dateTo = trim($_GET['date_to'] ?? '');     // YYYY-MM-DD
$onlyToday = isset($_GET['today']) && $_GET['today'] == '1';
// Geo filters
$geoRegion = trim($_GET['geo_region'] ?? '');
$geoCityId = trim($_GET['geo_city_id'] ?? '');
$geoDistrictId = trim($_GET['geo_district_id'] ?? '');
// Category filters
$filterCategoryId = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$includeDesc = isset($_GET['include_descendants']) && $_GET['include_descendants'] === '1';
// job_group filter (optional, only if column exists)
$jobGroupId = isset($_GET['job_group_id']) ? (int)$_GET['job_group_id'] : 0;
// Detect column existence once
$leadsHasGroupCol = false; try{ $cols = $pdo->query("PRAGMA table_info(leads)")->fetchAll(PDO::FETCH_ASSOC); foreach($cols as $c){ if(($c['name']??$c['Name']??'')==='job_group_id'){ $leadsHasGroupCol=true; break; } } }catch(Throwable $e){}

$where = ['1=1'];
$params = [];
if($q!==''){ $where[] = "(l.phone LIKE :q OR l.name LIKE :q)"; $params[':q'] = "%$q%"; }
if($city!==''){ $where[] = "l.city LIKE :city"; $params[':city'] = "%$city%"; }
if($country!==''){ $where[] = "l.country LIKE :country"; $params[':country'] = "%$country%"; }
if($dateFrom!==''){ $where[] = "substr(l.created_at,1,10) >= :df"; $params[':df'] = $dateFrom; }
if($dateTo!==''){ $where[] = "substr(l.created_at,1,10) <= :dt"; $params[':dt'] = $dateTo; }
if($onlyToday){ $where[] = "substr(l.created_at,1,10) = :tdy"; $params[':tdy'] = date('Y-m-d'); }
if($geoRegion!==''){ $where[] = "l.geo_region_code = :gr"; $params[':gr'] = $geoRegion; }
if($geoCityId!==''){ $where[] = "l.geo_city_id = :gci"; $params[':gci'] = (int)$geoCityId; }
if($geoDistrictId!==''){ $where[] = "l.geo_district_id = :gdi"; $params[':gdi'] = (int)$geoDistrictId; }
if($filterCategoryId>0){
  if($includeDesc){
    $ids = category_get_descendant_ids($filterCategoryId);
    if(empty($ids)){ $ids = [$filterCategoryId]; }
    $ph=[]; foreach($ids as $i=>$cid){ $k=":cid$i"; $ph[]=$k; $params[$k]=(int)$cid; }
    $where[] = 'l.category_id IN ('.implode(',', $ph).')';
  } else {
    $where[] = 'l.category_id = :cid_exact';
    $params[':cid_exact'] = $filterCategoryId;
  }
}
if($jobGroupId>0 && $leadsHasGroupCol){ $where[] = 'l.job_group_id = :jg'; $params[':jg'] = $jobGroupId; }

// Scope by role
if($role==='agent'){
  $where[] = 'a.agent_id = :agent_id';
  $params[':agent_id'] = $u['id'];
}

$sql = "SELECT l.id,l.name,l.phone,l.city,l.country,l.created_at,l.rating,l.website,l.email,l.gmap_types,l.source_url,l.social,l.category_id,l.lat,l.lon,l.geo_country,l.geo_region_code,l.geo_city_id,l.geo_district_id,l.geo_confidence" . ($jobGroupId>0 && $leadsHasGroupCol ? ", l.job_group_id" : "") . ",
  a.agent_id,a.status,u.name as agent_name,
  COALESCE(c.name, 'غير مُصنَّف (Legacy)') AS category_name,
  COALESCE(c.slug, 'legacy') AS category_slug,
  COALESCE(c.path, 'غير مُصنَّف (Legacy)') AS category_path
        FROM leads l 
        LEFT JOIN assignments a ON a.lead_id=l.id 
        LEFT JOIN users u ON u.id=a.agent_id 
        LEFT JOIN categories c ON c.id=l.category_id
        WHERE ".implode(' AND ',$where)." 
        ORDER BY l.id DESC ";

$max = (int)get_setting('export_max_rows','50000'); if($max<=0) $max=50000;
// Prepare and execute
$stmt = $pdo->prepare($sql." LIMIT :lim"); foreach($params as $k=>$v){ $stmt->bindValue($k,$v); } $stmt->bindValue(':lim',$max,PDO::PARAM_INT); $stmt->execute();

// Output headers
$fname = ($role==='admin'?'leads_all':'my_leads').'_'.date('Ymd_His').'.csv';
if(!$__UNIT){ header('Content-Disposition: attachment; filename="'.$fname.'"'); }

// Long-running exports: avoid timeouts and stream progressively
@set_time_limit(0);
@ini_set('output_buffering','off');
@ini_set('zlib.output_compression','0');
$out = fopen('php://output', 'w');
// Write UTF-8 BOM for Excel compatibility
fwrite($out, "\xEF\xBB\xBF");
// Excel delimiter hint (older Excels respect this)
fwrite($out, "sep=,\r\n");

// Header row
$headers = ['id','name','phone','city','country','created_at','rating','website','email','gmap_types','source_url','social','category_name','category_path','category_slug','agent_name','status','lat','lon','geo_country','geo_region','geo_city_id','geo_district_id','geo_confidence'];
if($jobGroupId>0 && $leadsHasGroupCol){
  // Insert job_group_id after category_path for readability
  $idx = array_search('category_path', $headers, true);
  if($idx!==false){ array_splice($headers, $idx+1, 0, ['job_group_id']); } else { $headers[] = 'job_group_id'; }
}
fputcsv($out, $headers);
// CSV injection guard: prefix risky values with apostrophe when needed (Excel)
$guard = function($v){
  if($v===null) return '';
  if(!is_string($v)) return $v;
  $s = (string)$v;
  if($s === '') return '';
  if(preg_match('/^[=+\-@]/', $s) === 1) return "'".$s;
  return $s;
};

$count = 0;
while(($r = $stmt->fetch(PDO::FETCH_ASSOC)) !== false){
  $social = $r['social'];
  if(is_string($social) && $social!==''){
    // keep JSON as is; Excel will render it as text
  } else { $social = ''; }
  $row = [
    $guard($r['id']), $guard($r['name']), $guard($r['phone']), $guard($r['city']), $guard($r['country']), $guard($r['created_at']),
    $guard($r['rating']), $guard($r['website']), $guard($r['email']), $guard($r['gmap_types']), $guard($r['source_url']), $guard($social),
    $guard($r['category_name']), $guard($r['category_path'])
  ];
  if($jobGroupId>0 && $leadsHasGroupCol){ $row[] = ($r['job_group_id'] ?? ''); }
  $row = array_merge($row, [ $guard($r['category_slug']), $guard($r['agent_name']), $guard($r['status']), $guard($r['lat']), $guard($r['lon']), $guard($r['geo_country']), $guard($r['geo_region_code']), $guard($r['geo_city_id']), $guard($r['geo_district_id']), $guard($r['geo_confidence']) ]);
  fputcsv($out, $row);
  $count++;
  if(($count % 2000)===0){ fflush($out); }
}

  // Tail note if truncated
  // Can't directly know total beyond LIMIT; if we reached cap, append note
  if($count>=$max){ fputcsv($out, []); fputcsv($out, ['NOTE','تم قطع النتائج إلى', $max, 'صفوف كحد أقصى للتصدير.']); }

fclose($out);
if($__UNIT){ return; } else { exit; }
