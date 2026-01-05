<?php
require_once __DIR__ . '/../bootstrap.php';

$u = current_user();
if(!$u){ http_response_code(403); echo 'forbidden'; exit; }

// Optional CSRF check via query param to mitigate CSRF in GET downloads
$csrf = $_GET['csrf'] ?? '';
if(!csrf_verify($csrf)){
  http_response_code(400); echo 'csrf'; exit;
}

// Fallback gracefully if ZipArchive is missing
if(!class_exists('ZipArchive')){
  // Redirect to legacy Excel XML export with same query string
  $qs = $_SERVER['QUERY_STRING'] ?? '';
  header('Location: '.linkTo('api/export_leads_excel.php').($qs?('?'.$qs):''));
  exit;
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

$max = (int)get_setting('export_max_rows','50000'); if($max<=0) $max=50000;

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
        ORDER BY l.id DESC LIMIT :lim";

$stmt = $pdo->prepare($sql); foreach($params as $k=>$v){ $stmt->bindValue($k,$v); } $stmt->bindValue(':lim',$max,PDO::PARAM_INT); $stmt->execute();
$rows = $stmt->fetchAll();

// Prepare XLSX parts
$headers = ['id','name','phone','city','country','created_at','rating','website','email','gmap_types','source_url','social','category_name','category_path'];
if($jobGroupId>0 && $leadsHasGroupCol){ $headers[] = 'job_group_id'; }
$headers = array_merge($headers, ['category_slug','agent_name','status','lat','lon','geo_country','geo_region','geo_city_id','geo_district_id','geo_confidence']);

function x($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

$sheetRows = [];
// Header row (style s=1)
$r = '<row r="1">';
$colIndex = 1;
foreach($headers as $h){ $r .= '<c r="'.chr(64+$colIndex).'1" t="inlineStr" s="1"><is><t>'.x($h).'</t></is></c>'; $colIndex++; }
$r .= '</row>';
$sheetRows[] = $r;

$rn = 2;
foreach($rows as $row){
  $cells = [
    $row['id'], $row['name'], $row['phone'], $row['city'], $row['country'], $row['created_at'],
    $row['rating'], $row['website'], $row['email'], $row['gmap_types'], $row['source_url'], (is_string($row['social'])?$row['social']:''),
    $row['category_name'], $row['category_path']
  ];
  if($jobGroupId>0 && $leadsHasGroupCol){ $cells[] = ($row['job_group_id'] ?? ''); }
  $cells = array_merge($cells, [ $row['category_slug'], $row['agent_name'], $row['status'], $row['lat'], $row['lon'], $row['geo_country'], $row['geo_region_code'], $row['geo_city_id'], $row['geo_district_id'], $row['geo_confidence'] ]);
  $cxml = '';
  $ci = 1;
  foreach($cells as $v){
    // Always write inlineStr to preserve Arabic and special formatting (phones etc.)
    $addr = chr(64+$ci).$rn;
    $cxml .= '<c r="'.$addr.'" t="inlineStr"><is><t>'.x($v).'</t></is></c>';
    $ci++;
  }
  $sheetRows[] = '<row r="'.$rn.'">'.$cxml.'</row>';
  $rn++;
}

// If truncated, append a note row
if(count($rows)>=$max){
  $sheetRows[] = '<row r="'.$rn.'"><c r="A'.$rn.'" t="inlineStr"><is><t>'.x('تم قطع النتائج إلى '.$max.' صفوف كحد أقصى للتصدير.').'</t></is></c></row>';
}

$sheetData = implode("\n", $sheetRows);

$sheetXml = '<?xml version="1.0" encoding="UTF-8"?>'
  .'\n<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
  .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
  .'\n  <sheetViews><sheetView workbookViewId="0" rightToLeft="1"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
  .'\n  <sheetFormatPr defaultColWidth="18" defaultRowHeight="15"/>'
  .'\n  <dimension ref="A1:'.('X'.max(1,$rn)).'"/>'
  .'\n  <cols>'
  .str_repeat('<col min="1" max="1" width="14" customWidth="1"/>',0)
  .''
  .'\n    <col min="1" max="1" width="8" customWidth="1"/>' // id
  .'\n    <col min="2" max="2" width="24" customWidth="1"/>' // name
  .'\n    <col min="3" max="3" width="20" customWidth="1"/>' // phone
  .'\n    <col min="4" max="4" width="18" customWidth="1"/>' // city
  .'\n    <col min="5" max="5" width="18" customWidth="1"/>' // country
  .'\n    <col min="6" max="6" width="20" customWidth="1"/>' // created_at
  .'\n    <col min="7" max="7" width="10" customWidth="1"/>' // rating
  .'\n    <col min="8" max="8" width="30" customWidth="1"/>' // website
  .'\n    <col min="9" max="9" width="26" customWidth="1"/>' // email
  .'\n    <col min="10" max="10" width="26" customWidth="1"/>' // gmap_types
  .'\n    <col min="11" max="11" width="36" customWidth="1"/>' // source_url
  .'\n    <col min="12" max="12" width="42" customWidth="1"/>' // social
  .'\n    <col min="13" max="13" width="22" customWidth="1"/>' // category_name
  .'\n    <col min="14" max="14" width="28" customWidth="1"/>' // category_path
  .'\n    <col min="15" max="15" width="18" customWidth="1"/>' // category_slug
  .'\n    <col min="16" max="16" width="22" customWidth="1"/>' // agent_name
  .'\n    <col min="17" max="17" width="16" customWidth="1"/>' // status
  .'\n    <col min="18" max="18" width="14" customWidth="1"/>' // lat
  .'\n    <col min="19" max="19" width="14" customWidth="1"/>' // lon
  .'\n    <col min="20" max="20" width="10" customWidth="1"/>' // geo_country
  .'\n    <col min="21" max="21" width="10" customWidth="1"/>' // geo_region
  .'\n    <col min="22" max="22" width="10" customWidth="1"/>' // geo_city_id
  .'\n    <col min="23" max="23" width="10" customWidth="1"/>' // geo_district_id
  .'\n    <col min="24" max="24" width="12" customWidth="1"/>' // geo_confidence
  .'\n  </cols>'
  .'\n  <sheetData>\n'.$sheetData.'\n  </sheetData>'
  .'\n</worksheet>';

$workbookXml = '<?xml version="1.0" encoding="UTF-8"?>'
  .'\n<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
  .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
  .'\n  <sheets>\n    <sheet name="Leads" sheetId="1" r:id="rId1"/>\n  </sheets>\n</workbook>';

$workbookRels = '<?xml version="1.0" encoding="UTF-8"?>'
  .'\n<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
  .'\n  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
  .'\n</Relationships>';

$rels = '<?xml version="1.0" encoding="UTF-8"?>'
  .'\n<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
  .'\n  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
  .'\n</Relationships>';

$contentTypes = '<?xml version="1.0" encoding="UTF-8"?>'
  .'\n<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
  .'\n  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
  .'\n  <Default Extension="xml" ContentType="application/xml"/>'
  .'\n  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
  .'\n  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
  .'\n  <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
  .'\n  <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
  .'\n</Types>';

$styles = '<?xml version="1.0" encoding="UTF-8"?>'
  .'\n<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
  .'\n  <fonts count="2">'
  .'\n    <font><name val="Calibri"/><family val="2"/><sz val="11"/></font>'
  .'\n    <font><b/><name val="Calibri"/><family val="2"/><sz val="11"/></font>'
  .'\n  </fonts>'
  .'\n  <fills count="2">'
  .'\n    <fill><patternFill patternType="none"/></fill>'
  .'\n    <fill><patternFill patternType="solid"><fgColor rgb="FF0B1A30"/><bgColor indexed="64"/></patternFill></fill>'
  .'\n  </fills>'
  .'\n  <borders count="1"><border/></borders>'
  .'\n  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
  .'\n  <cellXfs count="2">'
  .'\n    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
  .'\n    <xf numFmtId="0" fontId="1" fillId="1" borderId="0" xfId="0"/>'
  .'\n  </cellXfs>'
  .'\n</styleSheet>';

$appXml = '<?xml version="1.0" encoding="UTF-8"?>'
  .'\n<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"'
  .' xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
  .'\n  <Application>LeadsMembershipPRO</Application>'
  .'\n</Properties>';

$coreXml = '<?xml version="1.0" encoding="UTF-8"?>'
  .'\n<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
  .' xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
  .'\n  <dc:title>Leads Export</dc:title>'
  .'\n  <dc:creator>'.x($u['name'] ?? 'system').'</dc:creator>'
  .'\n  <cp:lastModifiedBy>'.x($u['name'] ?? 'system').'</cp:lastModifiedBy>'
  .'\n  <dcterms:created xsi:type="dcterms:W3CDTF">'.date('c').'</dcterms:created>'
  .'\n</cp:coreProperties>';

$tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'leads_xlsx_'.bin2hex(random_bytes(6)).'.xlsx';
$zip = new ZipArchive();
if($zip->open($tmp, ZipArchive::CREATE)!==true){ http_response_code(500); echo 'cannot create zip'; exit; }

$zip->addFromString('[Content_Types].xml', $contentTypes);
$zip->addFromString('_rels/.rels', $rels);
$zip->addFromString('docProps/app.xml', $appXml);
$zip->addFromString('docProps/core.xml', $coreXml);
$zip->addFromString('xl/workbook.xml', $workbookXml);
$zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
$zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
$zip->addFromString('xl/styles.xml', $styles);
$zip->close();

$fname = ($role==='admin'?'leads_all':'my_leads').'_'.date('Ymd_His').'.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="'.$fname.'"');
header('Content-Length: '.filesize($tmp));
readfile($tmp);
@unlink($tmp);
exit;
