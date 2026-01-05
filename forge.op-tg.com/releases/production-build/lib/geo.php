<?php
// Geo utilities: SA-first implementation with pluggable country bundles under storage/data/geo/<country>/
// Provides normalization, text/point classification, and unknown logging.

function geo_data_dir(string $country='SA'){
  $country = strtoupper($country);
  $root = __DIR__ . '/../storage/data';
  $paths = [
    $root . '/geo/' . strtolower($country), // preferred
    $root . '/' . strtolower($country),     // legacy fallback
  ];
  foreach($paths as $p){ if(is_dir($p)) return $p; }
  return $paths[0];
}

function geo_db_path(string $country='SA'){
  $dd = geo_data_dir($country);
  $c = strtolower($country);
  $cands = [ "$dd/{$c}_geo.db", __DIR__ . '/../storage/data/'.$c.'/'.$c.'_geo.db' ];
  foreach($cands as $p){ if(is_file($p)) return realpath($p); }
  return $cands[0];
}

function geo_db(string $country='SA'){
  static $cache = [];
  $key = strtoupper($country);
  if(isset($cache[$key]) && $cache[$key]) return $cache[$key];
  $p = geo_db_path($key);
  // Ensure parent directory exists (best-effort)
  $dir = dirname($p);
  if(!is_dir($dir)) {@mkdir($dir, 0777, true);} @chmod($dir, 0777);
  try{
    $pdo = new PDO('sqlite:'.$p);
    $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $cache[$key] = $pdo;
  }catch(Throwable $e){
    // Fallback to in-memory DB so UI does not crash; geo functions will behave as if no data
    try{
      $mem = new PDO('sqlite::memory:');
      $mem->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
      $mem->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
      // Create empty tables to satisfy queries
      $mem->exec("CREATE TABLE IF NOT EXISTS regions(id INTEGER PRIMARY KEY, code TEXT, name_ar TEXT, name_en TEXT, lat REAL, lon REAL);");
      $mem->exec("CREATE TABLE IF NOT EXISTS cities(id INTEGER PRIMARY KEY, region_code TEXT, name_ar TEXT, name_en TEXT, alt_names TEXT, lat REAL, lon REAL, population INTEGER, wikidata TEXT);");
      $mem->exec("CREATE TABLE IF NOT EXISTS districts(id INTEGER PRIMARY KEY, city_id INTEGER, name_ar TEXT, name_en TEXT, lat REAL, lon REAL);");
      return $cache[$key] = $mem;
    }catch(Throwable $_){
      // As a last resort, rethrow original error
      throw $e;
    }
  }
}

function geo_normalization_map(string $country='SA'){
  static $maps = [];
  $key = strtoupper($country);
  if(array_key_exists($key,$maps)) return $maps[$key];
  $file = geo_data_dir($key).'/normalization.json';
  if(is_file($file)){
    $j = json_decode((string)@file_get_contents($file), true);
    if(is_array($j)) return $maps[$key] = $j;
  }
  return $maps[$key] = ['synonyms'=>[]];
}

function geo_norm_ar(string $s){
  // Lowercase, remove diacritics and tatweel, normalize alef variants and yah/maqsura, trim common prefixes
  $s = trim($s);
  if($s==='') return $s;
  $s = mb_strtolower($s,'UTF-8');
  // Remove Arabic diacritics and tatweel
  $s = preg_replace('/[\x{0610}-\x{061A}\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}\x{0640}]/u','',$s);
  // Normalize alef variants and hamza
  $s = str_replace(['أ','إ','آ','ٱ'], 'ا', $s);
  // Normalize taa marbuta to ha alternative for matching and alif maqsura to ya
  $s = str_replace(['ة'], 'ه', $s);
  $s = str_replace(['ى'], 'ي', $s);
  // Remove common prefixes like حي/حارة/حى when at start
  $s = preg_replace('/^(حي|حاره|حارة|حى)\s+/u','', $s);
  // Collapse whitespace
  $s = preg_replace('/\s+/u',' ', $s);
  return trim($s);
}

function geo_apply_synonyms(string $name, string $country='SA'){
  $nm = geo_norm_ar($name);
  $map = geo_normalization_map($country);
  $syn = $map['synonyms'] ?? [];
  foreach($syn as $canon=>$alts){
    foreach($alts as $alt){ if(geo_norm_ar($alt) === $nm) return $canon; }
    if(geo_norm_ar($canon) === $nm) return $canon;
  }
  return $nm;
}

function geo_sa_nearest_city(float $lat, float $lon){
  try{
    $stmt = geo_db('SA')->query("SELECT id, region_code, name_ar, name_en, lat, lon FROM cities WHERE lat IS NOT NULL AND lon IS NOT NULL");
    $min=null; $best=null;
    foreach($stmt as $row){
      $dlat = deg2rad($lat - (float)$row['lat']);
      $dlon = deg2rad($lon - (float)$row['lon']);
      $a = sin($dlat/2)**2 + cos(deg2rad($lat))*cos(deg2rad((float)$row['lat']))*sin($dlon/2)**2;
      $c = 2 * atan2(sqrt($a), sqrt(1-$a));
      $dist_km = 6371 * $c;
      if($min===null || $dist_km<$min){ $min=$dist_km; $best=$row; }
    }
    return [$best,$min];
  }catch(Throwable $e){ return [null,null]; }
}

function geo_sa_match_city_by_text(string $cityName){
  $q = geo_apply_synonyms($cityName,'SA');
  if($q==='') return null;
  static $cities = null; if($cities===null){
    try{ $cities = geo_db('SA')->query("SELECT id, region_code, name_ar, name_en, alt_names FROM cities")->fetchAll(); }
    catch(Throwable $e){ $cities = []; }
  }
  $best=null; $score=0.0;
  foreach($cities as $c){
    $cand = [ geo_norm_ar($c['name_ar']??''), geo_norm_ar($c['name_en']??'') ];
    if(!empty($c['alt_names'])){ foreach(preg_split('/[|,؛،]/u',(string)$c['alt_names']) as $an){ $cand[] = geo_norm_ar($an); } }
    foreach($cand as $nm){
      if($nm==='' ) continue;
      if($nm === $q){ $best = $c; $score=1.0; break 2; }
      // startswith / contains heuristics
      if(strpos($nm, $q)===0 && $score<0.9){ $best=$c; $score=0.9; }
      elseif(strpos($nm, $q)!==false && $score<0.7){ $best=$c; $score=0.7; }
    }
  }
  if(!$best) return null;
  return [$best, $score];
}

function geo_sa_match_district_by_text(int $cityId, string $districtName){
  $q = geo_norm_ar($districtName);
  if($q==='') return null;
  try{
    $stmt = geo_db('SA')->prepare("SELECT id, name_ar, name_en FROM districts WHERE city_id=?");
    $stmt->execute([$cityId]);
    $best=null; $score=0.0;
    foreach($stmt as $d){
      $cand = [ geo_norm_ar($d['name_ar']??''), geo_norm_ar($d['name_en']??'') ];
      foreach($cand as $nm){
        if($nm==='') continue;
        if($nm === $q){ $best = $d; $score=1.0; break 2; }
        if(strpos($nm, $q)===0 && $score<0.9){ $best=$d; $score=0.9; }
        elseif(strpos($nm, $q)!==false && $score<0.7){ $best=$d; $score=0.7; }
      }
    }
    if(!$best) return null;
    return [$best, $score];
  }catch(Throwable $e){ return null; }
}

function geo_classify_point(float $lat, float $lon, string $country='SA'){
  if(strtoupper($country)!=='SA'){ return ['country'=>strtoupper($country),'region_code'=>null,'city_id'=>null,'district_id'=>null,'confidence'=>0.0,'source'=>'point']; }
  [$city,$km] = geo_sa_nearest_city($lat,$lon);
  if(!$city){ return ['country'=>'SA','region_code'=>null,'city_id'=>null,'district_id'=>null,'confidence'=>0.0,'source'=>'point']; }
  // city confidence taper (200km → ~0)
  $confCity = max(0.0, min(1.0, 1.0 - ($km/200.0)));
  // Try nearest district within this city
  $db = geo_db('SA'); $distId = null; $confDist = 0.0;
  try{
    $st = $db->prepare("SELECT id, lat, lon FROM districts WHERE city_id=? AND lat IS NOT NULL AND lon IS NOT NULL");
    $st->execute([(int)$city['id']]);
    $minD=null; $best=null;
    foreach($st as $d){
      $dlat = deg2rad($lat - (float)$d['lat']);
      $dlon = deg2rad($lon - (float)$d['lon']);
      $a = sin($dlat/2)**2 + cos(deg2rad($lat))*cos(deg2rad((float)$d['lat']))*sin($dlon/2)**2;
      $c = 2 * atan2(sqrt($a), sqrt(1-$a));
      $kmD = 6371 * $c;
      if($minD===null || $kmD<$minD){ $minD=$kmD; $best=$d; }
    }
    if($best!==null){ $distId = (int)$best['id']; $confDist = max(0.0, min(1.0, 1.0 - (($minD??999)/20.0))); }
  }catch(Throwable $e){}
  return ['country'=>'SA','region_code'=>$city['region_code'],'city_id'=>(int)$city['id'],'district_id'=>$distId,'confidence'=>max($confCity,$confDist),'source'=>'point'];
}

function geo_classify_text(?string $cityName, ?string $districtName=null, string $country='SA'){
  $cityName = trim((string)$cityName);
  $districtName = trim((string)$districtName);
  if($cityName===''){ return ['country'=>strtoupper($country),'region_code'=>null,'city_id'=>null,'district_id'=>null,'confidence'=>0.0,'source'=>'text']; }
  if(strtoupper($country)!=='SA'){ return ['country'=>strtoupper($country),'region_code'=>null,'city_id'=>null,'district_id'=>null,'confidence'=>0.0,'source'=>'text']; }
  $match = geo_sa_match_city_by_text($cityName);
  if(!$match) return ['country'=>'SA','region_code'=>null,'city_id'=>null,'district_id'=>null,'confidence'=>0.0,'source'=>'text'];
  [$city, $sc] = $match;
  $districtId = null; $sd = 0.0;
  if($districtName!==''){
    $dm = geo_sa_match_district_by_text((int)$city['id'], $districtName);
    if($dm){ $districtId = (int)$dm[0]['id']; $sd = (float)$dm[1]; }
  }
  $conf = $districtId ? min((float)$sc, $sd) : (float)$sc;
  return ['country'=>'SA','region_code'=>$city['region_code'],'city_id'=>(int)$city['id'],'district_id'=>$districtId,'confidence'=>$conf,'source'=>'text'];
}

function geo_log_unknown(array $payload){
  $dir = __DIR__ . '/../storage/logs'; if(!is_dir($dir)) @mkdir($dir,0777,true);
  $file = $dir.'/geo_unknown.log';
  $line = '['.gmdate('c').'] '.json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  @file_put_contents($file, $line."\n", FILE_APPEND);
}

// Optional: suggested mapping from Arabic categories to Google Places types
function sa_category_to_google_types(string $cat){
  $cat = trim($cat);
  $map = [
    'مطاعم' => ['restaurant'],
    'مشاوي' => ['restaurant'],
    'بيتزا' => ['restaurant'],
    'مقاهي' => ['cafe'],
    'حلويات' => ['bakery'],
    'مخبز' => ['bakery'],
    'سوبرماركت' => ['supermarket','grocery_or_supermarket'],
    'بقالة' => ['grocery_or_supermarket'],
    'صيدليات' => ['pharmacy'],
    'مستشفيات' => ['hospital'],
    'عيادات' => ['doctor','health'],
    'أسنان' => ['dentist'],
    'محلات جوالات' => ['electronics_store'],
    'خدمات حاسب' => ['electronics_store'],
    'إلكترونيات' => ['electronics_store'],
    'ورش' => ['car_repair'],
    'قطع غيار' => ['car_parts'],
    'مغسلة سيارات' => ['car_wash'],
    'تأجير سيارات' => ['car_rental'],
    'إطارات' => ['car_repair'],
    'مدارس' => ['school'],
    'جامعات' => ['university'],
    'فنادق' => ['lodging'],
    'أندية رياضية' => ['gym'],
    'صالات رياضية' => ['gym'],
    'مكاتب محاماة' => ['lawyer'],
    'محاسبة' => ['accounting'],
    'بنوك' => ['bank'],
    'تأمين' => ['insurance_agency'],
    'عقارات' => ['real_estate_agency'],
    'مواد بناء' => ['hardware_store'],
    'مقاولات عامة' => ['general_contractor'],
    'ملابس' => ['clothing_store'],
    'أحذية' => ['shoe_store'],
    'عطور' => ['store'],
    'ساعات' => ['jewelry_store'],
    'نظارات' => ['store'],
    'مكتبات' => ['book_store'],
    'قرطاسية' => ['store'],
    'ألعاب' => ['toy_store']
  ];
  return $map[$cat] ?? [];
}

?>
