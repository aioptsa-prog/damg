<?php
require_once __DIR__ . '/../bootstrap.php';

$u = current_user();
if(!$u){ http_response_code(403); echo 'forbidden'; exit; }

// Optional CSRF check via query param to mitigate CSRF in GET downloads
$csrf = $_GET['csrf'] ?? '';
if(!csrf_verify($csrf)){
  http_response_code(400); echo 'csrf'; exit;
}

$pdo = db();
$role = $u['role'] ?? 'agent';

@set_time_limit(0);
@ini_set('output_buffering','off');
@ini_set('zlib.output_compression','0');

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
// job_group filter
$jobGroupId = isset($_GET['job_group_id']) ? (int)$_GET['job_group_id'] : 0;
// Detect leads.job_group_id column existence
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
$stmt = $pdo->prepare($sql." LIMIT :lim"); foreach($params as $k=>$v){ $stmt->bindValue($k,$v); } $stmt->bindValue(':lim',$max,PDO::PARAM_INT); $stmt->execute();

$fname = ($role==='admin'?'leads_all':'my_leads').'_'.date('Ymd_His').'.xls';
header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$fname.'"');

function x($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
echo "<Workbook xmlns=\"urn:schemas-microsoft-com:office:spreadsheet\" xmlns:o=\"urn:schemas-microsoft-com:office:office\" xmlns:x=\"urn:schemas-microsoft-com:office:excel\" xmlns:ss=\"urn:schemas-microsoft-com:office:spreadsheet\" xmlns:html=\"http://www.w3.org/TR/REC-html40\">\n";
// Suggest Arabic locale and RTL display where supported
echo "  <Styles>\n";
echo "    <Style ss:ID=\"rtl\">\n";
echo "      <Alignment ss:Horizontal=\"Right\" ss:ReadingOrder=\"2\"/>\n"; // 2 = Right-to-Left
echo "    </Style>\n";
echo "  </Styles>\n";
echo "  <Worksheet ss:Name=\"Leads\">\n";
echo "    <Table ss:DefaultColumnWidth=\"120\">\n";
// write header row with rtl style

// Header row
$headers = ['id','name','phone','city','country','created_at','rating','website','email','gmap_types','source_url','social','category_name','category_path'];
if($jobGroupId>0 && $leadsHasGroupCol){ $headers[] = 'job_group_id'; }
$headers = array_merge($headers, ['category_slug','agent_name','status','lat','lon','geo_country','geo_region','geo_city_id','geo_district_id','geo_confidence']);
echo "      <Row ss:StyleID=\"rtl\">\n";
foreach($headers as $h){ echo "        <Cell ss:StyleID=\"rtl\"><Data ss:Type=\"String\">".x($h)."</Data></Cell>\n"; }
echo "      </Row>\n";

$i=0;
while(($r = $stmt->fetch(PDO::FETCH_ASSOC)) !== false){
  echo "      <Row ss:StyleID=\"rtl\">\n";
  $vals = [
    $r['id'], $r['name'], $r['phone'], $r['city'], $r['country'], $r['created_at'],
    $r['rating'], $r['website'], $r['email'], $r['gmap_types'], $r['source_url'], (is_string($r['social'])?$r['social']:''),
    $r['category_name'], $r['category_path']
  ];
  if($jobGroupId>0 && $leadsHasGroupCol){ $vals[] = ($r['job_group_id'] ?? ''); }
  $vals = array_merge($vals, [ $r['category_slug'], $r['agent_name'], $r['status'], $r['lat'], $r['lon'], $r['geo_country'], $r['geo_region_code'], $r['geo_city_id'], $r['geo_district_id'], $r['geo_confidence'] ]);
  foreach($vals as $v){
    $type = is_numeric($v) && (string)(int)$v === (string)$v ? 'Number' : 'String';
    // Phones/codes: force String to preserve leading zeros and +
    if($type==='Number' && preg_match('/^\+|^0/', (string)$v)) $type = 'String';
  echo "        <Cell ss:StyleID=\"rtl\"><Data ss:Type=\"$type\">".x($v)."</Data></Cell>\n";
  }
  echo "      </Row>\n";
  $i++; if(($i % 1500)===0){ @flush(); }
}

echo "    </Table>\n";
// If we reached export cap, append a note (best-effort: check last loop count)
if($i>=$max){
  echo "    <Table>\n";
  echo "      <Row ss:StyleID=\"rtl\"><Cell ss:StyleID=\"rtl\"><Data ss:Type=\"String\">تم قطع النتائج إلى $max صفوف كحد أقصى للتصدير.</Data></Cell></Row>\n";
  echo "    </Table>\n";
}
echo "  </Worksheet>\n";
echo "</Workbook>\n";
exit;
