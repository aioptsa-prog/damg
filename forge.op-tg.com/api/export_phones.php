<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/categories.php';

$__UNIT = defined('UNIT_TEST') && UNIT_TEST;

$u = current_user();
if(!$u){ http_response_code(403); echo 'forbidden'; if($__UNIT){ return; } else { exit; } }

// CSRF guard for GET download
$csrf = $_GET['csrf'] ?? '';
if(!csrf_verify($csrf)){
  http_response_code(400);
  echo 'csrf';
  if($__UNIT){ return; } else { exit; }
}

$pdo = db();
$role = $u['role'] ?? 'agent';

// Filters (align with export_leads.php)
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
// Dedup and format
$dedupe = !isset($_GET['dedupe']) || $_GET['dedupe'] === '' ? 1 : (int)$_GET['dedupe'];
$format = strtolower(trim($_GET['format'] ?? ($_GET['txt'] ?? '') ? 'txt' : 'csv'));
if($format !== 'txt' && $format !== 'csv'){ $format = 'csv'; }

// Detect job_group column existence
$leadsHasGroupCol = false; try{ $cols = $pdo->query("PRAGMA table_info(leads)")->fetchAll(PDO::FETCH_ASSOC); foreach($cols as $c){ if(($c['name']??$c['Name']??'')==='job_group_id'){ $leadsHasGroupCol=true; break; } } }catch(Throwable $e){}

$where = ['1=1', "TRIM(COALESCE(l.phone,'')) <> ''"];
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

// Base SQL
$select = $dedupe ? 'SELECT DISTINCT TRIM(l.phone) AS phone' : 'SELECT TRIM(l.phone) AS phone';
$join = ($role==='agent') ? ' LEFT JOIN assignments a ON a.lead_id=l.id ' : ' ';
$sql = $select . "\n  FROM leads l" . $join . "\n  WHERE " . implode(' AND ', $where) . "\n";

// Optional LIMIT cap (0 = no cap)
$maxDefault = '0';
$max = (int)get_setting('export_phones_max_rows', $maxDefault);
if($max > 0){ $sql .= ' LIMIT :lim'; }

// Headers
$fname = ($role==='admin'?'phones_all':'my_phones').'_' . date('Ymd_His') . ($format==='txt'?'.txt':'.csv');
if(!$__UNIT){
  if($format==='txt'){
    header('Content-Type: text/plain; charset=utf-8');
  } else {
    header('Content-Type: text/csv; charset=utf-8');
  }
  header('Content-Disposition: attachment; filename="'.$fname.'"');
}

@set_time_limit(0);
@ini_set('output_buffering','off');
@ini_set('zlib.output_compression','0');
$stmt = $pdo->prepare($sql);
foreach($params as $k=>$v){ $stmt->bindValue($k,$v); }
if($max>0){ $stmt->bindValue(':lim',$max,PDO::PARAM_INT); }
$stmt->execute();

// Stream output
if($format==='csv'){
  $out = fopen('php://output', 'w');
  // UTF-8 BOM for Excel compatibility
  fwrite($out, "\xEF\xBB\xBF");
  // Excel delimiter hint
  fwrite($out, "sep=,\r\n");
  // Header
  fputcsv($out, ['phone']);
  $count=0;
  $guard = function($v){ if($v===null) return ''; $s=(string)$v; if($s==='') return ''; if(preg_match('/^[=+\-@]/',$s)) return "'".$s; return $s; };
  while(($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false){
    $ph = trim((string)($row['phone'] ?? ''));
    if($ph==='') continue;
    fputcsv($out, [$guard($ph)]);
    $count++;
    if(($count % 3000)===0){ fflush($out); }
  }
  if($max>0 && $count>=$max){ fputcsv($out, []); fputcsv($out, ['NOTE','تم قطع النتائج إلى', $max, 'رقم كحد أقصى للتصدير.']); }
  fclose($out);
} else { // txt
  $out = fopen('php://output', 'w');
  $count=0;
  while(($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false){
    $ph = trim((string)($row['phone'] ?? ''));
    if($ph==='') continue;
    fwrite($out, $ph."\r\n");
    $count++;
  }
  if($max>0 && $count>=$max){ fwrite($out, "# NOTE: تم قطع النتائج إلى {$max} رقم كحد أقصى للتصدير.\r\n"); }
  fclose($out);
}

if($__UNIT){ return; } else { exit; }
