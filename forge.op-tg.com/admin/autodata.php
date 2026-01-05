<?php
require_once __DIR__ . '/../bootstrap.php';
$u = require_role('admin');
$pdo = db();
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/geo.php';

// Contract for this page:
// - Button 1: Seed categories (with common business categories + hierarchical structure)
// - Button 2: Seed keywords (مثلاً كلمات عربية وإنجليزية لكل تصنيف)
// - Button 3: Enqueue massive internal jobs across KSA cities x categories
// All actions are idempotent (INSERT OR IGNORE) and safe to re-run.

$action = $_POST['action'] ?? '';
$messages = [];

// Basic CSRF verification for POST actions
if($_SERVER['REQUEST_METHOD']==='POST'){
  $t = $_POST['csrf'] ?? '';
  if(!csrf_verify($t)){
    $messages[] = 'رمز الأمان غير صالح، أعد المحاولة.';
    $action = '';
  }
}

function seed_categories(PDO $pdo){
  $now = date('Y-m-d H:i:s');
  // موسّع: تغطية شاملة للأنشطة التجارية الشائعة في السعودية
  $cmap = [
    'مطاعم' => ['مشاوي','بيتزا','وجبات سريعة','مأكولات بحرية','مقاهي','شاورما','بروست','برغر','فطور'],
    'حلويات ومخابز' => ['حلويات','مخبز','كيك','دونات'],
    'بقالات وأسواق' => ['سوبرماركت','بقالة','خضار وفواكه','لحوم','أسماك','ألبان','مياه','محامص'],
    'صحة'   => ['مستشفيات','عيادات','أسنان','عيون','صيدليات','مختبرات','علاج طبيعي'],
    'تجميل' => ['صالون نسائي','صالون رجالي','سبا','مكياج','حلاقة'],
    'سيارات' => ['وكالات','ورش','قطع غيار','تشاليح','تأجير سيارات','مغسلة سيارات','إطارات'],
    'تعليم' => ['مدارس','جامعات','معاهد','دورات','روضة وحضانة'],
    'تقنية واتصالات' => ['محلات جوالات','خدمات حاسب','مزودي إنترنت','إلكترونيات'],
    'سياحة وضيافة' => ['فنادق','شقق مفروشة','منتجعات','شاليهات','سياحة وسفر'],
    'رياضة وترفيه' => ['أندية رياضية','صالات رياضية','ملاعب','حدائق','سينما'],
    'خدمات' => ['مكاتب استقدام','شحن ونقل','نظافة','صيانة عامة','خياطة'],
    'مقاولات وبناء' => ['مقاولات عامة','مواد بناء','كهرباء','سباكة','دهانات'],
    'عقارات' => ['مكاتب عقارية','تقييم عقاري','إدارة أملاك'],
    'مالية وتأمين' => ['بنوك','صرافة','تأمين'],
    'مهن حرة' => ['مكاتب محاماة','محاسبة','استشارات'],
    'تجزئة' => ['ملابس','أحذية','عطور','ساعات','نظارات','قرطاسية','مكتبات','ألعاب'],
  ];
  $created = 0; $parentsMade = 0; $subsMade = 0;
  $selParent = $pdo->prepare("SELECT id FROM categories WHERE name=? AND parent_id IS NULL LIMIT 1");
  $insParent = $pdo->prepare("INSERT INTO categories(name, parent_id, created_at) VALUES(?, NULL, datetime('now'))");
  $selSub = $pdo->prepare("SELECT id FROM categories WHERE name=? AND parent_id=? LIMIT 1");
  $insSub = $pdo->prepare("INSERT INTO categories(name, parent_id, created_at) VALUES(?, ?, datetime('now'))");
  foreach($cmap as $parent => $subs){
    $selParent->execute([$parent]); $pr = $selParent->fetch();
    if($pr){ $pid = (int)$pr['id']; }
    else { $insParent->execute([$parent]); $pid = (int)$pdo->lastInsertId(); $parentsMade++; }
    foreach($subs as $nm){
      $selSub->execute([$nm, $pid]); $sr = $selSub->fetch();
      if(!$sr){ $insSub->execute([$nm, $pid]); $subsMade++; }
    }
  }
  $created = $parentsMade + $subsMade;
  return ["تم إدراج/تأكيد التصنيفات: جذور جديدة=$parentsMade، فرعية جديدة=$subsMade"];
}

function seed_keywords(PDO $pdo){
  $now = date('Y-m-d H:i:s');
  // كلمات لكل تصنيف (عربي/إنجليزي)
  $pairs = [
    'مطاعم' => ['مطعم','Restaurant','اكل','أكل','طعام','Food'],
    'مشاوي' => ['Grill','مشويات','كباب'],
    'بيتزا' => ['Pizza','بيتزا'],
    'مقاهي' => ['Cafe','Coffee','قهوة','كوفي'],
    'حلويات' => ['Sweets','حلويات','Cake','كيك','Pastry','مخبوزات'],
    'مخبز' => ['Bakery','مخبز'],
    'سوبرماركت' => ['Market','Supermarket','سوبرماركت'],
    'بقالة' => ['Grocery','Grocer','بقالة'],
    'خضار وفواكه' => ['Vegetables','Fruits','خضار','فواكه'],
    'لحوم' => ['Butcher','Meat','لحوم','جزارة'],
    'أسماك' => ['Fish','Seafood','أسماك'],
    'ألبان' => ['Dairy','Milk','ألبان'],
    'صيدليات' => ['Pharmacy','صيدلية','Pharmacies'],
    'مستشفيات' => ['Hospital','مستشفى','مستشفيات'],
    'عيادات' => ['Clinic','عيادة'],
    'أسنان' => ['Dentist','Dental','أسنان'],
    'عيون' => ['Eye','Ophthalmology','عيون'],
    'مختبرات' => ['Lab','Laboratory','مختبر'],
    'علاج طبيعي' => ['Physiotherapy','علاج طبيعي'],
    'صالون نسائي' => ['Salon','Beauty Salon','صالون','تصفيف'],
    'صالون رجالي' => ['Barber','Barbershop','حلاقة'],
    'سبا' => ['Spa','استرخاء'],
    'محلات جوالات' => ['Mobile','Cellphone','جوال','هواتف'],
    'خدمات حاسب' => ['Computer','IT Service','حاسب','كمبيوتر','IT'],
    'إلكترونيات' => ['Electronics','Store','الكترونيات'],
    'وكالات' => ['Agency','وكالة سيارات'],
    'ورش' => ['Workshop','Garage','ورشة','ميكانيكا'],
    'قطع غيار' => ['Spare Parts','قطع غيار'],
    'تأجير سيارات' => ['Car Rental','Rent a car','تأجير سيارات'],
    'مغسلة سيارات' => ['Car Wash','Carwash','مغسلة سيارات'],
    'إطارات' => ['Tires','Tyres','إطارات'],
    'مدارس' => ['School','مدرسة'],
    'جامعات' => ['University','جامعة'],
    'معاهد' => ['Institute','معهد'],
    'دورات' => ['Courses','دورات'],
    'روضة وحضانة' => ['Kindergarten','Nursery','روضة','حضانة'],
    'فنادق' => ['Hotel','Hotels','فندق'],
    'شقق مفروشة' => ['Furnished Apartments','شقق مفروشة'],
    'منتجعات' => ['Resort','منتجع'],
    'شاليهات' => ['Chalet','شاليه'],
    'سياحة وسفر' => ['Travel Agency','Tourism','سياحة','سفر'],
    'أندية رياضية' => ['Sports Club','Club','نادي'],
    'صالات رياضية' => ['Gym','Fitness','صالة رياضية'],
    'مكاتب استقدام' => ['Recruitment','استقدام','توظيف'],
    'شحن ونقل' => ['Shipping','Logistics','Transport','شحن','نقل'],
    'نظافة' => ['Cleaning','نظافة'],
    'صيانة عامة' => ['Maintenance','صيانة'],
    'خياطة' => ['Tailor','خياطة'],
    'مقاولات عامة' => ['Contracting','Construction','مقاولات'],
    'مواد بناء' => ['Building Materials','مواد بناء'],
    'كهرباء' => ['Electrical','كهرباء'],
    'سباكة' => ['Plumbing','سباكة'],
    'دهانات' => ['Paints','دهانات'],
    'مكاتب عقارية' => ['Real Estate','عقار','مكتب عقاري'],
    'تقييم عقاري' => ['Appraisal','Valuation','تقييم عقاري'],
    'إدارة أملاك' => ['Property Management','إدارة أملاك'],
    'بنوك' => ['Bank','Banks','بنك'],
    'صرافة' => ['Exchange','Currency Exchange','صرافة'],
    'تأمين' => ['Insurance','تأمين'],
    'مكاتب محاماة' => ['Law Firm','Lawyer','محاماة','محامي'],
    'محاسبة' => ['Accounting','محاسبة'],
    'استشارات' => ['Consulting','استشارات'],
    'ملابس' => ['Clothing','Fashion','ملابس'],
    'أحذية' => ['Shoes','Footwear','أحذية'],
    'عطور' => ['Perfume','Fragrance','عطور'],
    'ساعات' => ['Watches','ساعة','ساعات'],
    'نظارات' => ['Optics','Optician','نظارات'],
    'قرطاسية' => ['Stationery','قرطاسية'],
    'مكتبات' => ['Bookstore','Books','مكتبة'],
    'ألعاب' => ['Toys','ألعاب'],
  ];
  $selCat = $pdo->prepare("SELECT id FROM categories WHERE name=? LIMIT 1");
  $selKW = $pdo->prepare("SELECT 1 FROM category_keywords WHERE category_id=? AND keyword=? LIMIT 1");
  $insKw = $pdo->prepare("INSERT INTO category_keywords(category_id, keyword, created_at) VALUES(?,?, datetime('now'))");
  $added = 0; $miss = 0; $skipped = 0;
  foreach($pairs as $catName=>$kws){
    $selCat->execute([$catName]); $row = $selCat->fetch(); if(!$row){ $miss++; continue; }
    $cid = (int)$row['id'];
    foreach($kws as $kw){ $selKW->execute([$cid, $kw]); if(!$selKW->fetch()){ $insKw->execute([$cid, $kw]); $added++; } else { $skipped++; } }
  }
  return ["تم إضافة كلمات مفتاحية جديدة: $added", $skipped? ("تخطّي مكررات: ".$skipped): '', $miss?"تصنيفات غير موجودة: $miss":""];
}

function ksa_cities(){
  // قائمة موسّعة بمدن المملكة مع إحداثيات دقيقة تقريبية (lat,lng)
  // المصدر التقريبي: خرائط عامة؛ يمكن تعديلها حسب الحاجة.
  return [
    ['name'=>'الرياض', 'lat'=>24.7136, 'lng'=>46.6753, 'radius_km'=>35],
    ['name'=>'جدة', 'lat'=>21.4858, 'lng'=>39.1925, 'radius_km'=>25],
    ['name'=>'مكة', 'lat'=>21.3891, 'lng'=>39.8579, 'radius_km'=>20],
    ['name'=>'المدينة المنورة', 'lat'=>24.5247, 'lng'=>39.5692, 'radius_km'=>20],
    ['name'=>'الدمام', 'lat'=>26.3927, 'lng'=>49.9777, 'radius_km'=>22],
    ['name'=>'الخبر', 'lat'=>26.2797, 'lng'=>50.2083, 'radius_km'=>18],
    ['name'=>'الظهران', 'lat'=>26.3022, 'lng'=>50.2086, 'radius_km'=>18],
    ['name'=>'الأحساء (الهفوف)', 'lat'=>25.3833, 'lng'=>49.5867, 'radius_km'=>20],
    ['name'=>'الطائف', 'lat'=>21.4373, 'lng'=>40.5127, 'radius_km'=>18],
    ['name'=>'بريدة', 'lat'=>26.3591, 'lng'=>43.9810, 'radius_km'=>18],
    ['name'=>'عنيزة', 'lat'=>26.0966, 'lng'=>43.9710, 'radius_km'=>15],
    ['name'=>'حائل', 'lat'=>27.5114, 'lng'=>41.7208, 'radius_km'=>18],
    ['name'=>'أبها', 'lat'=>18.2465, 'lng'=>42.5117, 'radius_km'=>16],
    ['name'=>'خميس مشيط', 'lat'=>18.3064, 'lng'=>42.7290, 'radius_km'=>16],
    ['name'=>'جازان', 'lat'=>16.8892, 'lng'=>42.5706, 'radius_km'=>15],
    ['name'=>'صبيا', 'lat'=>17.1495, 'lng'=>42.6254, 'radius_km'=>12],
    ['name'=>'نجران', 'lat'=>17.5650, 'lng'=>44.2236, 'radius_km'=>16],
    ['name'=>'ينبع', 'lat'=>24.0889, 'lng'=>38.0647, 'radius_km'=>15],
    ['name'=>'عرعر', 'lat'=>30.9753, 'lng'=>41.0381, 'radius_km'=>15],
    ['name'=>'تبوك', 'lat'=>28.3833, 'lng'=>36.5667, 'radius_km'=>18],
    ['name'=>'الجبيل', 'lat'=>27.0046, 'lng'=>49.6469, 'radius_km'=>18],
    ['name'=>'القطيف', 'lat'=>26.5578, 'lng'=>49.9983, 'radius_km'=>16],
    ['name'=>'القنفذة', 'lat'=>19.1163, 'lng'=>41.0785, 'radius_km'=>14],
    ['name'=>'بيشة', 'lat'=>20.0136, 'lng'=>42.6052, 'radius_km'=>16],
    ['name'=>'الباحة', 'lat'=>20.0129, 'lng'=>41.4677, 'radius_km'=>14],
    ['name'=>'رابغ', 'lat'=>22.7986, 'lng'=>39.0349, 'radius_km'=>14],
    ['name'=>'محايل عسير', 'lat'=>18.5496, 'lng'=>42.0505, 'radius_km'=>14],
    ['name'=>'الخفجي', 'lat'=>28.4391, 'lng'=>48.4916, 'radius_km'=>14],
    ['name'=>'المجمعة', 'lat'=>25.9103, 'lng'=>45.3439, 'radius_km'=>14],
    ['name'=>'الزلفي', 'lat'=>26.2990, 'lng'=>44.8150, 'radius_km'=>14],
    ['name'=>'حفر الباطن', 'lat'=>28.4328, 'lng'=>45.9708, 'radius_km'=>18],
    ['name'=>'الليث', 'lat'=>20.1515, 'lng'=>40.2663, 'radius_km'=>14],
    ['name'=>'أملج', 'lat'=>25.0657, 'lng'=>37.2685, 'radius_km'=>14],
    ['name'=>'الوجه', 'lat'=>26.2428, 'lng'=>36.4525, 'radius_km'=>14],
    ['name'=>'العلا', 'lat'=>26.6167, 'lng'=>37.9167, 'radius_km'=>16],
    ['name'=>'سكاكا', 'lat'=>29.9697, 'lng'=>40.2064, 'radius_km'=>15],
    ['name'=>'القريات', 'lat'=>31.3300, 'lng'=>37.3411, 'radius_km'=>14],
    ['name'=>'طبرجل', 'lat'=>30.4990, 'lng'=>38.2160, 'radius_km'=>14],
    ['name'=>'وادي الدواسر', 'lat'=>20.4600, 'lng'=>44.8300, 'radius_km'=>16],
    ['name'=>'شرورة', 'lat'=>17.4820, 'lng'=>47.1067, 'radius_km'=>14],
    ['name'=>'بلجرشي', 'lat'=>19.9038, 'lng'=>41.5604, 'radius_km'=>12],
    ['name'=>'النماص', 'lat'=>19.1191, 'lng'=>42.1270, 'radius_km'=>12],
    ['name'=>'سراة عبيدة', 'lat'=>18.0714, 'lng'=>43.1502, 'radius_km'=>12],
    ['name'=>'تيماء', 'lat'=>27.6144, 'lng'=>38.5522, 'radius_km'=>12],
    ['name'=>'الدوادمي', 'lat'=>24.5074, 'lng'=>44.3923, 'radius_km'=>14],
    ['name'=>'عفيف', 'lat'=>23.9076, 'lng'=>42.9156, 'radius_km'=>14],
    ['name'=>'القويعية', 'lat'=>24.0537, 'lng'=>45.2747, 'radius_km'=>14],
    ['name'=>'تمير', 'lat'=>25.7037, 'lng'=>45.8678, 'radius_km'=>12],
    ['name'=>'شقراء', 'lat'=>25.2582, 'lng'=>45.2484, 'radius_km'=>12],
    ['name'=>'الخرج', 'lat'=>24.1556, 'lng'=>47.3121, 'radius_km'=>16],
    ['name'=>'الرس', 'lat'=>25.8694, 'lng'=>43.4973, 'radius_km'=>14],
    ['name'=>'البدائع', 'lat'=>26.3271, 'lng'=>43.9366, 'radius_km'=>12],
    ['name'=>'الطريف', 'lat'=>31.6894, 'lng'=>38.6576, 'radius_km'=>12],
    ['name'=>'الليمانية (رأس تنورة)', 'lat'=>26.7083, 'lng'=>50.0614, 'radius_km'=>12],
    ['name'=>'الهنداوية (ينبع النخل)', 'lat'=>24.2550, 'lng'=>38.2880, 'radius_km'=>12],
  ];
}

function sa_cities_from_geo_db(?string $regionFilterCsv=null){
  // Read cities from storage/data/geo/sa/sa_geo.db if available; fallback to empty list
  $out = [];
  try{
    $g = geo_db('SA');
    $where = "WHERE lat IS NOT NULL AND lon IS NOT NULL";
    $args = [];
    $regionFilterCsv = trim((string)$regionFilterCsv);
    if($regionFilterCsv!==''){
      $codes = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/u', $regionFilterCsv))));
      if($codes){
        $in = implode(',', array_fill(0, count($codes), '?'));
        $where .= " AND region_code IN ($in)";
        $args = $codes;
      }
    }
    $stmt = $g->prepare("SELECT name_ar AS name, lat, lon AS lng, region_code FROM cities $where ORDER BY name_ar ASC");
    $stmt->execute($args);
    while($row=$stmt->fetch(PDO::FETCH_ASSOC)){
      $name = trim((string)$row['name']);
      if($name==='') continue;
      $lat = isset($row['lat'])? (float)$row['lat'] : null;
      $lng = isset($row['lng'])? (float)$row['lng'] : null;
      if($lat===null || $lng===null) continue;
      $out[] = [ 'name'=>$name, 'lat'=>$lat, 'lng'=>$lng, 'region_code'=>$row['region_code'] ?? null, 'radius_km'=>guess_city_radius_km($name) ];
    }
  } catch(Throwable $e){ /* ignore; fallback handled by caller */ }
  return $out;
}

function guess_city_radius_km($name){
  // تصنيف تقريبي حسب الحجم الحضري
  $big = ['الرياض','جدة'];
  $large = ['مكة','المدينة المنورة','الدمام','بريدة','حائل','أبها','خميس مشيط','تبوك','الجبيل','ينبع','الطائف','الأحساء (الهفوف)'];
  if(in_array($name,$big,true)) return 30;
  if(in_array($name,$large,true)) return 20;
  return 12; // افتراضي للمدن المتوسطة/الصغيرة
}

function ksa_bbox(){
  // تقريب تقريبي لحدود السعودية (lat: 16..32.5, lng: 34.5..55.7)
  return [16.0, 34.5, 32.5, 55.7]; // [minLat, minLng, maxLat, maxLng]
}

function deg_step_for_km_lat($km){ return max(0.01, $km/111.0); }
function deg_step_for_km_lng($km, $lat){ $den = 111.320 * max(0.15, cos(deg2rad($lat))); return max(0.01, $km/$den); }

function generate_grid_points($minLat,$minLng,$maxLat,$maxLng,$stepKm,$cap=2000){
  $points = [];
  for($lat=$minLat; $lat<=$maxLat+1e-9; $lat+=deg_step_for_km_lat($stepKm)){
    $dLng = deg_step_for_km_lng($stepKm, $lat);
    for($lng=$minLng; $lng<=$maxLng+1e-9; $lng+=$dLng){
      $points[] = [round($lat,5), round($lng,5)];
      if(count($points) >= $cap) return $points;
    }
  }
  return $points;
}

function fetch_categories(PDO $pdo){
  return $pdo->query("SELECT id,name,parent_id FROM categories ORDER BY COALESCE(parent_id,0), id")->fetchAll();
}
function fetch_keywords_map(PDO $pdo){
  $rows = $pdo->query("SELECT category_id, keyword FROM category_keywords ORDER BY id")->fetchAll();
  $map = [];
  foreach($rows as $r){ $map[(int)$r['category_id']][] = $r['keyword']; }
  return $map;
}

function plan_grid(PDO $pdo, $minLat,$minLng,$maxLat,$maxLng,$stepKm,$mode){
  $pts = generate_grid_points($minLat,$minLng,$maxLat,$maxLng,$stepKm, 100000); // only for counting; capped high
  $cats = fetch_categories($pdo);
  $kwMap = fetch_keywords_map($pdo);
  $catCount = count($cats);
  $kwTotal = 0; foreach($cats as $c){ $kwTotal += count($kwMap[(int)$c['id']] ?? []); }
  $perPoint = 0;
  if($mode==='categories') $perPoint = $catCount;
  elseif($mode==='keywords') $perPoint = max(1,$kwTotal);
  else /* cat_kw */ {
    foreach($cats as $c){ $kws = $kwMap[(int)$c['id']] ?? []; $perPoint += max(1,count($kws)); }
  }
  return [ 'points'=>count($pts), 'jobs'=>$perPoint * count($pts), 'cat_count'=>$catCount, 'kw_count'=>$kwTotal ];
}

function enqueue_grid(PDO $pdo, $minLat,$minLng,$maxLat,$maxLng,$stepKm,$radius,$lang,$region,$mode,$maxJobs){
  // allow big batches in one go (may still be limited by hosting)
  @ignore_user_abort(true); @set_time_limit(0); @ini_set('memory_limit','512M');
  $pts = generate_grid_points($minLat,$minLng,$maxLat,$maxLng,$stepKm, max(1,$maxJobs));
  $cats = fetch_categories($pdo); if(!$cats) return ['لا توجد تصنيفات'];
  $kwMap = fetch_keywords_map($pdo);
  $adminId = (int)($pdo->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetch()['id'] ?? 0);
  if(!$adminId) return ['لا يوجد مدير لإسناد المهام'];
  // Detect optional columns for richer jobs
  $cols = $pdo->query("PRAGMA table_info(internal_jobs)")->fetchAll(PDO::FETCH_ASSOC);
  $has = function($n) use ($cols){ foreach($cols as $c){ if(strtolower($c['name'])===strtolower($n)) return true; } return false; };
  $hasPayload = $has('job_type') && $has('payload_json');
  if($hasPayload){
    $insIfNew = $pdo->prepare(
      "INSERT INTO internal_jobs(requested_by_user_id, role, agent_id, query, ll, radius_km, lang, region, status, job_type, payload_json, created_at, updated_at)\n".
      "SELECT ?,?,?,?,?,?,?,?,'queued','places_api_search',?, datetime('now'), datetime('now')\n".
      "WHERE NOT EXISTS (SELECT 1 FROM internal_jobs WHERE query=? AND ll=? AND radius_km=? AND lang=? AND region=? AND status IN ('queued','processing'))"
    );
  } else {
    $insIfNew = $pdo->prepare(
      "INSERT INTO internal_jobs(requested_by_user_id, role, agent_id, query, ll, radius_km, lang, region, status, created_at, updated_at)\n".
      "SELECT ?,?,?,?,?,?,?,?,'queued', datetime('now'), datetime('now')\n".
      "WHERE NOT EXISTS (SELECT 1 FROM internal_jobs WHERE query=? AND ll=? AND radius_km=? AND lang=? AND region=? AND status IN ('queued','processing'))"
    );
  }
  $count = 0; $skipped = 0; $stop = false;
  $inTx = false; try{ $pdo->beginTransaction(); $inTx = true;
  foreach($pts as $p){ if($stop) break; 
    $ll = $p[0].','.$p[1];
    if($mode==='categories'){
      foreach($cats as $c){ $q = $c['name'];
        if($hasPayload){
          $types = sa_category_to_google_types($c['name']);
          $payload = [ 'keywords'=>[$q], 'types'=>$types, 'center'=>['lat'=>(float)$p[0],'lng'=>(float)$p[1]], 'radius_km'=>(float)$radius, 'language'=>$lang, 'region'=>strtoupper($region), 'max_results'=>(int)get_setting('places_max_results','80') ];
          $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
          $insIfNew->execute([$adminId,'admin', null, $q, $ll, $radius, $lang, $region, $payloadJson, $q,$ll,$radius,$lang,$region]);
        } else {
          $insIfNew->execute([$adminId,'admin', null, $q, $ll, $radius, $lang, $region, $q,$ll,$radius,$lang,$region]);
        }
        if($insIfNew->rowCount()>0){ $count++; } else { $skipped++; } if($count>=$maxJobs){ $stop=true; break; }
      }
    } elseif($mode==='keywords'){
      foreach($cats as $c){ foreach(($kwMap[(int)$c['id']] ?? ['*']) as $kw){ $q = $kw;
        if($hasPayload){
          $types = sa_category_to_google_types($c['name']);
          $payload = [ 'keywords'=>[$q], 'types'=>$types, 'center'=>['lat'=>(float)$p[0],'lng'=>(float)$p[1]], 'radius_km'=>(float)$radius, 'language'=>$lang, 'region'=>strtoupper($region), 'max_results'=>(int)get_setting('places_max_results','80') ];
          $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
          $insIfNew->execute([$adminId,'admin', null, $q, $ll, $radius, $lang, $region, $payloadJson, $q,$ll,$radius,$lang,$region]);
        } else {
          $insIfNew->execute([$adminId,'admin', null, $q, $ll, $radius, $lang, $region, $q,$ll,$radius,$lang,$region]);
        }
        if($insIfNew->rowCount()>0){ $count++; } else { $skipped++; } if($count>=$maxJobs){ $stop=true; break 2; } } }
    } else { // cat_kw: prefer keywords; if none, use category name
      foreach($cats as $c){ $kws=$kwMap[(int)$c['id']] ?? []; if(!$kws){ $kws = [$c['name']]; }
        foreach($kws as $kw){ $q = $c['name'].' '.$kw;
          if($hasPayload){
            $types = sa_category_to_google_types($c['name']);
            $payload = [ 'keywords'=>[$q], 'types'=>$types, 'center'=>['lat'=>(float)$p[0],'lng'=>(float)$p[1]], 'radius_km'=>(float)$radius, 'language'=>$lang, 'region'=>strtoupper($region), 'max_results'=>(int)get_setting('places_max_results','80') ];
            $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $insIfNew->execute([$adminId,'admin', null, $q, $ll, $radius, $lang, $region, $payloadJson, $q,$ll,$radius,$lang,$region]);
          } else {
            $insIfNew->execute([$adminId,'admin', null, $q, $ll, $radius, $lang, $region, $q,$ll,$radius,$lang,$region]);
          }
          if($insIfNew->rowCount()>0){ $count++; } else { $skipped++; } if($count>=$maxJobs){ $stop=true; break 2; }
        }
      }
    }
    if($count % 250 === 0){ usleep(10000); }
  }
  if($inTx) $pdo->commit();
  } catch(Throwable $e){ if($inTx) try{$pdo->rollBack();}catch(Throwable $e2){} return ['خطأ أثناء الإدراج: '.$e->getMessage()]; }
  return ["تم إدراج مهام شبكية بعدد: $count (نِقاط × استعلامات)", $skipped? ("تخطّي مكررات: ".$skipped): '' ];
}

function enqueue_jobs(PDO $pdo){
  @ignore_user_abort(true); @set_time_limit(0); @ini_set('memory_limit','512M');
  $adminId = (int)$pdo->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetch()['id'];
  if(!$adminId){ return ['لا يوجد مدير لإسناد المهام']; }
  $cats = $pdo->query("SELECT name FROM categories ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
  if(!$cats){ return ['يجب إدراج التصنيفات أولاً']; }
  // Choose city source: geo DB (preferred) or built-in list
  $useGeo = isset($_POST['use_geo_db']) && $_POST['use_geo_db']=='1';
  $regionFilter = $_POST['region_filter'] ?? '';
  $cities = $useGeo? sa_cities_from_geo_db($regionFilter) : ksa_cities();
  $lang = get_setting('default_language','ar');
  $region = get_setting('default_region','sa');
  $radius = max(5, (int)get_setting('default_radius_km','25'));
  // Detect optional columns locally
  $cols2 = $pdo->query("PRAGMA table_info(internal_jobs)")->fetchAll(PDO::FETCH_ASSOC);
  $has2 = function($n) use ($cols2){ foreach($cols2 as $c){ if(strtolower($c['name'])===strtolower($n)) return true; } return false; };
  $hasPayload2 = $has2('job_type') && $has2('payload_json');
  if($hasPayload2){
    $insIfNew = $pdo->prepare(
      "INSERT INTO internal_jobs(requested_by_user_id, role, agent_id, query, ll, radius_km, lang, region, status, job_type, payload_json, created_at, updated_at)\n".
      "SELECT ?,?,?,?,?,?,?,?,'queued','places_api_search',?, datetime('now'), datetime('now')\n".
      "WHERE NOT EXISTS (SELECT 1 FROM internal_jobs WHERE query=? AND ll=? AND radius_km=? AND lang=? AND region=? AND status IN ('queued','processing'))"
    );
  } else {
    $insIfNew = $pdo->prepare(
      "INSERT INTO internal_jobs(requested_by_user_id, role, agent_id, query, ll, radius_km, lang, region, status, created_at, updated_at)\n".
      "SELECT ?,?,?,?,?,?,?,?,'queued', datetime('now'), datetime('now')\n".
      "WHERE NOT EXISTS (SELECT 1 FROM internal_jobs WHERE query=? AND ll=? AND radius_km=? AND lang=? AND region=? AND status IN ('queued','processing'))"
    );
  }
  $count = 0; $skipped = 0; $inTx = false; try{ $pdo->beginTransaction(); $inTx = true;
  foreach($cities as $city){
    $name = is_array($city)? ($city['name'] ?? '') : (string)$city;
    $lat = is_array($city)? ($city['lat'] ?? null) : null;
    $lng = is_array($city)? ($city['lng'] ?? null) : null;
    $llCity = ($lat!==null && $lng!==null) ? ($lat.','.$lng) : get_setting('default_ll','24.7136,46.6753');
    $cityRadius = (int)($city['radius_km'] ?? 0); if($cityRadius<=0) $cityRadius = (int)guess_city_radius_km($name); if($cityRadius<=0) $cityRadius = $radius;
    foreach($cats as $cat){
      $q = $cat.' '.$name;
      if($hasPayload2){
        $types = sa_category_to_google_types($cat);
        $payload = [
          'keywords' => [$q],
          'types' => $types,
          'center' => ['lat'=>(float)$lat, 'lng'=>(float)$lng],
          'radius_km' => (float)$cityRadius,
          'language' => $lang,
          'region' => strtoupper($region),
          'max_results' => (int)get_setting('places_max_results','80')
        ];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $insIfNew->execute([$adminId,'admin', null, $q, $llCity, $cityRadius, $lang, $region, $payloadJson, $q,$llCity,$cityRadius,$lang,$region]);
      } else {
        $insIfNew->execute([$adminId,'admin', null, $q, $llCity, $cityRadius, $lang, $region, $q,$llCity,$cityRadius,$lang,$region]);
      }
      if($insIfNew->rowCount()>0){ $count++; } else { $skipped++; }
      if($count % 250 === 0){ usleep(10000); }
    }
  }
  if($inTx) $pdo->commit();
  } catch(Throwable $e){ if($inTx) try{$pdo->rollBack();}catch(Throwable $e2){} return ['خطأ أثناء الإدراج: '.$e->getMessage()]; }
  return ["تم إدراج مهام داخلية بعدد: $count (تصنيفات × مدن)", $skipped? ("تخطّي مكررات: ".$skipped): '' ];
}

if($action==='seed_categories'){
  $messages = array_merge($messages, seed_categories($pdo));
}
if($action==='seed_keywords'){
  $messages = array_merge($messages, seed_keywords($pdo));
}
if($action==='enqueue_jobs'){
  $messages = array_merge($messages, enqueue_jobs($pdo));
}
if($action==='plan_grid' || $action==='enqueue_grid'){
  $bb = ksa_bbox();
  $minLat = (float)($_POST['min_lat'] ?? $bb[0]);
  $minLng = (float)($_POST['min_lng'] ?? $bb[1]);
  $maxLat = (float)($_POST['max_lat'] ?? $bb[2]);
  $maxLng = (float)($_POST['max_lng'] ?? $bb[3]);
  $stepKm = max(5, (float)($_POST['step_km'] ?? 50)); if($stepKm < 5) $stepKm = 5; if($stepKm > 200) $stepKm = 200;
  $radius = max(5, (int)($_POST['radius_km'] ?? (int)get_setting('default_radius_km','25')));
  $lang = $_POST['lang'] ?? get_setting('default_language','ar');
  $region = $_POST['region'] ?? get_setting('default_region','sa');
  $mode = in_array($_POST['mode'] ?? 'cat_kw', ['categories','keywords','cat_kw'], true)? ($_POST['mode'] ?? 'cat_kw') : 'cat_kw';
  $maxJobs = max(1, (int)($_POST['max_jobs'] ?? 5000)); if($maxJobs > 200000) $maxJobs = 200000;
  if($action==='plan_grid'){
    $plan = plan_grid($pdo, $minLat,$minLng,$maxLat,$maxLng,$stepKm,$mode);
    $messages[] = "المعاينة: نقاط= {$plan['points']}، تصنيفات= {$plan['cat_count']}, كلمات= {$plan['kw_count']}, المهام المتوقعة ≈ {$plan['jobs']}";
  } else {
    $messages = array_merge($messages, enqueue_grid($pdo, $minLat,$minLng,$maxLat,$maxLng,$stepKm,$radius,$lang,$region,$mode,$maxJobs));
  }
}

include __DIR__ . '/../layout_header.php';
?>
<div class="card">
  <h2>البيانات التلقائية</h2>
  <p class="muted">هذه الصفحة تجهّز النظام تلقائيًا بتصنيفات، كلمات مفتاحية، ومهام بحث واسعة تغطي مدن المملكة والأنشطة التجارية.</p>
  <?php
    // إحصاءات سريعة
    $totalLeads = (int)($pdo->query("SELECT COUNT(*) c FROM leads")->fetch()['c'] ?? 0);
    $q = (int)($pdo->query("SELECT COUNT(*) c FROM internal_jobs WHERE status='queued'")->fetch()['c'] ?? 0);
    $p = (int)($pdo->query("SELECT COUNT(*) c FROM internal_jobs WHERE status='processing'")->fetch()['c'] ?? 0);
    $d = (int)($pdo->query("SELECT COUNT(*) c FROM internal_jobs WHERE status='done'")->fetch()['c'] ?? 0);
  ?>
  <div class="stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin:10px 0">
    <div class="stat"><div class="stat-n"><?php echo number_format($totalLeads); ?></div><div class="stat-l">إجمالي الأرقام (Leads)</div></div>
    <div class="stat"><div class="stat-n"><?php echo number_format($q); ?></div><div class="stat-l">مهام بانتظار المعالجة</div></div>
    <div class="stat"><div class="stat-n"><?php echo number_format($p); ?></div><div class="stat-l">مهام قيد المعالجة</div></div>
    <div class="stat"><div class="stat-n"><?php echo number_format($d); ?></div><div class="stat-l">مهام منتهية</div></div>
  </div>
  <?php if($messages): ?>
    <div class="alert alert-success"><?php echo implode('<br>', array_map('htmlspecialchars', array_filter($messages))); ?></div>
  <?php endif; ?>
  <form method="post" style="display:grid; gap:14px; grid-template-columns: repeat(auto-fit,minmax(260px,1fr)); align-items:start">
    <?php echo csrf_input(); ?>
    <button class="btn" name="action" value="seed_categories" type="submit"><i class="fa-solid fa-sitemap"></i> إدراج/تأكيد التصنيفات (رئيسية + فرعية)</button>
    <button class="btn" name="action" value="seed_keywords" type="submit"><i class="fa-solid fa-key"></i> إدراج الكلمات المفتاحية للتصنيف</button>
    <div class="card" style="padding:10px">
      <div style="font-weight:600; margin-bottom:6px">خيارات المدن</div>
      <label style="display:block;margin:4px 0"><input type="checkbox" name="use_geo_db" value="1" <?php echo isset($_POST['use_geo_db'])? (($_POST['use_geo_db']=='1')?'checked':'') : 'checked'; ?>> استخدام قاعدة بيانات المدن السعودية (مستحسن)</label>
      <div style="display:flex; gap:8px; align-items:end">
        <div style="flex:1">
          <label>تصفية بالمناطق (اختياري، رموز مفصولة بفواصل)</label>
          <input name="region_filter" placeholder="مثال: 01,02,03" value="<?php echo htmlspecialchars($_POST['region_filter'] ?? ''); ?>">
        </div>
        <div>
          <button class="btn" name="action" value="enqueue_jobs" type="submit"><i class="fa-solid fa-list-check"></i> حقن مهام بحث ضخمة (مدن × أنشطة)</button>
        </div>
      </div>
      <div class="muted" style="margin-top:6px">عند التفعيل، تُستخدم إحداثيات المدن من قاعدة البيانات الجغرافية بدلاً من القائمة المضمّنة هنا.</div>
    </div>
  </form>
  <hr>
  <h3 style="margin:10px 0">تغطية شبكية شاملة داخل السعودية</h3>
  <p class="muted">وللحصول على أكبر قدر من البيانات، يمكنك توليد شبكة نقاط تغطي المملكة، ثم إنشاء مهام بحث عند كل نقطة. يمكنك المعاينة أولاً ثم التنفيذ مع سقف أقصى لعدد المهام.
  يمكنك كذلك استخدام قاعدة بيانات المدن السعودية لضمان دقة الإحداثيات للمدن الرئيسية. راجع تبويب "خيارات المدن" بالأعلى.</p>
  <?php $bb=ksa_bbox(); ?>
  <form method="post" style="display:grid; gap:10px; grid-template-columns: repeat(auto-fit,minmax(220px,1fr)); align-items:end">
    <?php echo csrf_input(); ?>
    <div><label>حد أدنى Lat</label><input name="min_lat" type="number" step="0.01" value="<?php echo htmlspecialchars($_POST['min_lat'] ?? $bb[0]); ?>"></div>
    <div><label>حد أدنى Lng</label><input name="min_lng" type="number" step="0.01" value="<?php echo htmlspecialchars($_POST['min_lng'] ?? $bb[1]); ?>"></div>
    <div><label>حد أعلى Lat</label><input name="max_lat" type="number" step="0.01" value="<?php echo htmlspecialchars($_POST['max_lat'] ?? $bb[2]); ?>"></div>
    <div><label>حد أعلى Lng</label><input name="max_lng" type="number" step="0.01" value="<?php echo htmlspecialchars($_POST['max_lng'] ?? $bb[3]); ?>"></div>
    <div><label>المسافة بين النقاط (كم)</label><input name="step_km" type="number" step="1" min="5" value="<?php echo htmlspecialchars($_POST['step_km'] ?? 50); ?>"></div>
    <div><label>نصف القطر للبحث (كم)</label><input name="radius_km" type="number" min="5" value="<?php echo htmlspecialchars($_POST['radius_km'] ?? (int)get_setting('default_radius_km','25')); ?>"></div>
    <div><label>اللغة</label><input name="lang" value="<?php echo htmlspecialchars($_POST['lang'] ?? get_setting('default_language','ar')); ?>"></div>
    <div><label>المنطقة</label><input name="region" value="<?php echo htmlspecialchars($_POST['region'] ?? get_setting('default_region','sa')); ?>"></div>
    <div><label>طريقة تكوين الاستعلام</label>
      <select name="mode">
        <?php $m=$_POST['mode'] ?? 'cat_kw'; ?>
        <option value="cat_kw" <?php echo $m==='cat_kw'?'selected':''; ?>>تصنيف + كلمات مفتاحية</option>
        <option value="categories" <?php echo $m==='categories'?'selected':''; ?>>تصنيفات فقط</option>
        <option value="keywords" <?php echo $m==='keywords'?'selected':''; ?>>كلمات مفتاحية فقط</option>
      </select>
    </div>
    <div><label>الحد الأقصى للمهام</label><input name="max_jobs" type="number" min="100" value="<?php echo htmlspecialchars($_POST['max_jobs'] ?? 5000); ?>"></div>
    <div style="display:flex;gap:8px">
      <button class="btn" name="action" value="plan_grid" type="submit"><i class="fa-solid fa-magnifying-glass-chart"></i> معاينة</button>
      <button class="btn" name="action" value="enqueue_grid" type="submit"><i class="fa-solid fa-layer-group"></i> إنشاء مهام شبكية</button>
    </div>
  </form>
  <div class="muted" style="margin-top:10px">
    - جميع العمليات آمنة لإعادة التنفيذ (Idempotent) ولن تكرر البيانات بشكل مضر.<br>
    - لضبط المدن بدقة، يمكنك تعديل الدالة ksa_cities() داخل هذه الصفحة.<br>
    - لتغطية أوسع، استخدم الشبكة مع مسافة مناسبة (مثلاً 50 كم) وراعي السقف الأقصى لتفادي إنشاء ملايين المهام مرة واحدة.
  </div>
</div>
<?php include __DIR__ . '/../layout_footer.php'; ?>
