<?php
// CLI helper to initialize Saudi geo DB and optionally fetch cities/districts via Overpass.
// Usage:
//   php tools/geo_init_sa.php init
//   php tools/geo_init_sa.php cities
//   php tools/geo_init_sa.php districts
require_once __DIR__ . '/../bootstrap.php';

if (php_sapi_name() !== 'cli') { fwrite(STDERR, "[ERR] CLI only\n"); exit(1); }

function sa_geo_db_cli(){
  $dir = __DIR__ . '/../storage/data/geo/sa';
  if(!is_dir($dir)) @mkdir($dir,0777,true);
  $dbPath = $dir . '/sa_geo.db';
  $pdo = new PDO('sqlite:'.$dbPath);
  $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  return [$pdo,$dbPath,$dir];
}

$cmd = $argv[1] ?? '';
if($cmd===''){ fwrite(STDERR, "Usage: php tools/geo_init_sa.php <init|cities|districts>\n"); exit(1);} 

try{
  if($cmd==='init'){
    list($pdo,$dbPath,$dir) = sa_geo_db_cli();
    // Defensive migration: if legacy 'regions' lacks 'code', back up and recreate new DB
    try{
      $cols = $pdo->query("PRAGMA table_info(regions)")->fetchAll(PDO::FETCH_ASSOC);
      $hasCode = false; foreach($cols as $c){ if(($c['name']??$c['Name']??'')==='code'){ $hasCode=true; break; } }
      if(!empty($cols) && !$hasCode){
        $bak = $dbPath.'.bak_'.date('Ymd_His');
        $pdo = null; @rename($dbPath, $bak);
        $pdo = new PDO('sqlite:'.$dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
      }
    }catch(Throwable $_){}
    $pdo->exec("CREATE TABLE IF NOT EXISTS regions(id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT UNIQUE NOT NULL, name_ar TEXT NOT NULL, name_en TEXT, lat REAL, lon REAL);");
    $pdo->exec("CREATE TABLE IF NOT EXISTS cities(id INTEGER PRIMARY KEY AUTOINCREMENT, region_code TEXT, name_ar TEXT, name_en TEXT, alt_names TEXT, lat REAL, lon REAL, population INTEGER, wikidata TEXT, FOREIGN KEY(region_code) REFERENCES regions(code));");
    $pdo->exec("CREATE TABLE IF NOT EXISTS districts(id INTEGER PRIMARY KEY AUTOINCREMENT, city_id INTEGER NOT NULL, name_ar TEXT, name_en TEXT, lat REAL, lon REAL, FOREIGN KEY(city_id) REFERENCES cities(id) ON DELETE CASCADE);");
  $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_regions_code ON regions(code);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cities_name_ar ON cities(name_ar);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cities_region_code ON cities(region_code);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_districts_city ON districts(city_id);");
    $regions = [
      ['RIY','الرياض','Riyadh',24.7136,46.6753],
      ['MEK','مكة المكرمة','Makkah',21.3891,39.8579],
      ['MED','المدينة المنورة','Madinah',24.5247,39.5692],
      ['QAS','القصيم','Al-Qassim',26.3587,43.9810],
      ['EPR','الشرقية','Eastern Province',26.3927,49.9777],
      ['ASR','عسير','Asir',18.2465,42.5117],
      ['TAB','تبوك','Tabuk',28.3838,36.5662],
      ['HAI','حائل','Hail',27.5219,41.6907],
      ['NBR','الحدود الشمالية','Northern Borders',30.9753,41.0386],
      ['JZN','جازان','Jazan',16.8890,42.5510],
      ['NAJ','نجران','Najran',17.5650,44.2280],
      ['BAH','الباحة','Al Bahah',20.0120,41.4670],
      ['JWF','الجوف','Al Jawf',29.9697,40.2064],
    ];
    $ins = $pdo->prepare("INSERT INTO regions(code,name_ar,name_en,lat,lon) VALUES(?,?,?,?,?) ON CONFLICT(code) DO UPDATE SET name_ar=excluded.name_ar,name_en=excluded.name_en,lat=excluded.lat,lon=excluded.lon");
    foreach($regions as $r){ $ins->execute($r); }
    $norm = [ 'synonyms'=> [ 'مكة المكرمة'=>['مكة'], 'المدينة المنورة'=>['المدينة'], 'الشرقية'=>['المنطقة الشرقية','الشرقيه'], 'جازان'=>['جيزان'], 'حائل'=>['حايل'], 'الجوف'=>['الجوْف'] ] ];
    @mkdir($dir,0777,true);
    file_put_contents($dir.'/normalization.json', json_encode($norm, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
    fwrite(STDOUT, "OK init: regions seeded at $dbPath\n");
    exit(0);
  }
  if($cmd==='cities'){
    list($pdo,$dbPath,$dir) = sa_geo_db_cli();
    $cnt = (int)$pdo->query("SELECT COUNT(*) c FROM regions")->fetch()['c'];
    if($cnt===0){ throw new RuntimeException('regions_missing'); }
    $regions = $pdo->query("SELECT code,name_ar,lat,lon FROM regions WHERE lat IS NOT NULL AND lon IS NOT NULL")->fetchAll();
    $nearest = function($lat,$lon) use ($regions){ $best=null;$min=null; foreach($regions as $r){ $dlat=deg2rad($lat-$r['lat']); $dlon=deg2rad($lon-$r['lon']); $a=sin($dlat/2)**2 + cos(deg2rad($lat))*cos(deg2rad($r['lat']))*sin($dlon/2)**2; $c=2*atan2(sqrt($a), sqrt(1-$a)); $km=6371*$c; if($min===null||$km<$min){ $min=$km; $best=$r['code']; } } return $best; };
    $q = "[out:json][timeout:60];area[\"ISO3166-1\"=\"SA\"][admin_level=2]->.sa;(node[\"place\"~\"city|town\"](area.sa);way[\"place\"~\"city|town\"](area.sa);relation[\"place\"~\"city|town\"](area.sa););out center;";
    $url = 'https://overpass-api.de/api/interpreter?data='.urlencode($q);
    $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>120,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_USERAGENT=>'NexusOps-Geo/1.0 (+https://op-tg.com)']); $raw=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    if($code!==200 || !$raw){ throw new RuntimeException('overpass_error'); }
    $resp=json_decode($raw,true); if(!$resp||empty($resp['elements'])){ throw new RuntimeException('no_elements'); }
    $ins = $pdo->prepare("INSERT INTO cities(region_code,name_ar,name_en,alt_names,lat,lon,population,wikidata) VALUES(?,?,?,?,?,?,?,?)");
    $upd = $pdo->prepare("UPDATE cities SET region_code=?, name_ar=?, name_en=?, alt_names=?, lat=?, lon=?, population=?, wikidata=? WHERE id=?");
    $pdo->beginTransaction(); $insC=0; $updC=0;
    foreach($resp['elements'] as $el){
      $tags=$el['tags']??[]; $name = $tags['name:ar'] ?? ($tags['name:en'] ?? ($tags['name'] ?? null)); if(!$name) continue;
      $name_ar=$tags['name:ar'] ?? $name; $name_en=$tags['name:en'] ?? ($tags['int_name'] ?? null);
      $lat = isset($el['lat']) ? (float)$el['lat'] : (isset($el['center']['lat']) ? (float)$el['center']['lat'] : null);
      $lon = isset($el['lon']) ? (float)$el['lon'] : (isset($el['center']['lon']) ? (float)$el['center']['lon'] : null);
      if($lat===null||$lon===null) continue;
      $rc = $nearest($lat,$lon) ?: null;
      $alt=[]; foreach(['alt_name','alt_name:ar','alt_name:en'] as $k){ if(!empty($tags[$k])) $alt[]=$tags[$k]; }
      $alt_names = $alt?implode('|',$alt):null; $pop = isset($tags['population'])?(int)$tags['population']:null; $wd=$tags['wikidata']??null;
      $ex = $pdo->prepare("SELECT id FROM cities WHERE name_ar=:ar AND ABS(lat-:lat)<0.005 AND ABS(lon-:lon)<0.005 LIMIT 1");
      $ex->execute([':ar'=>$name_ar, ':lat'=>$lat, ':lon'=>$lon]); $row=$ex->fetch();
      if($row){ $upd->execute([$rc,$name_ar,$name_en,$alt_names,$lat,$lon,$pop,$wd,$row['id']]); $updC++; }
      else { $ins->execute([$rc,$name_ar,$name_en,$alt_names,$lat,$lon,$pop,$wd]); $insC++; }
    }
    $pdo->commit();
    fwrite(STDOUT, "OK cities: +$insC, ~ $updC updates\n");
    exit(0);
  }
  if($cmd==='districts'){
    list($pdo,$dbPath,$dir) = sa_geo_db_cli();
    $cc = (int)$pdo->query("SELECT COUNT(*) c FROM cities")->fetch()['c'];
    if($cc===0){ throw new RuntimeException('cities_missing'); }
    $q = "[out:json][timeout:120];area[\"ISO3166-1\"=\"SA\"][admin_level=2]->.sa;(node[\"place\"~\"neighbourhood|suburb|quarter|district\"](area.sa);way[\"place\"~\"neighbourhood|suburb|quarter|district\"](area.sa);relation[\"place\"~\"neighbourhood|suburb|quarter|district\"](area.sa););out center;";
    $url='https://overpass-api.de/api/interpreter?data='.urlencode($q);
    $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>180,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_USERAGENT=>'NexusOps-Geo/1.0 (+https://op-tg.com)']); $raw=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    if($code!==200 || !$raw){ throw new RuntimeException('overpass_error'); }
    $resp=json_decode($raw,true); if(!$resp||empty($resp['elements'])){ throw new RuntimeException('no_elements'); }
    $cities = $pdo->query("SELECT id,name_ar,lat,lon FROM cities WHERE lat IS NOT NULL AND lon IS NOT NULL")->fetchAll();
    if(!$cities){ throw new RuntimeException('cities_no_coords'); }
    $nearestCity = function($lat,$lon) use($cities){ $best=null;$min=null; foreach($cities as $c){ $dlat=deg2rad($lat-$c['lat']); $dlon=deg2rad($lon-$c['lon']); $a=sin($dlat/2)**2 + cos(deg2rad($lat))*cos(deg2rad($c['lat']))*sin($dlon/2)**2; $cang=2*atan2(sqrt($a), sqrt(1-$a)); $km=6371*$cang; if($min===null||$km<$min){ $min=$km; $best=$c; } } return [$best,$min]; };
    $ins = $pdo->prepare("INSERT INTO districts(city_id,name_ar,name_en,lat,lon) VALUES(?,?,?,?,?)");
    $upd = $pdo->prepare("UPDATE districts SET city_id=?, name_ar=?, name_en=?, lat=?, lon=? WHERE id=?");
    $exByName = $pdo->prepare("SELECT d.id FROM districts d JOIN cities c ON c.id=d.city_id WHERE d.name_ar=:ar AND ABS(d.lat-:lat)<0.01 AND ABS(d.lon-:lon)<0.01 LIMIT 1");
    $pdo->beginTransaction(); $insC=0; $updC=0; $skipped=0;
    foreach($resp['elements'] as $el){
      $tags=$el['tags']??[];
      $name = $tags['name:ar'] ?? ($tags['name'] ?? ($tags['name:en'] ?? null)); if(!$name) { $skipped++; continue; }
      $name_ar = $tags['name:ar'] ?? $name; $name_en = $tags['name:en'] ?? null;
      $lat = isset($el['lat']) ? (float)$el['lat'] : (isset($el['center']['lat']) ? (float)$el['center']['lat'] : null);
      $lon = isset($el['lon']) ? (float)$el['lon'] : (isset($el['center']['lon']) ? (float)$el['center']['lon'] : null);
      if($lat===null||$lon===null){ $skipped++; continue; }
      [$city,$km] = $nearestCity($lat,$lon);
      if(!$city){ $skipped++; continue; }
      if($km>60){ $skipped++; continue; }
      $exByName->execute([':ar'=>$name_ar, ':lat'=>$lat, ':lon'=>$lon]); $row=$exByName->fetch();
      if($row){ $upd->execute([$city['id'],$name_ar,$name_en,$lat,$lon,$row['id']]); $updC++; }
      else { $ins->execute([$city['id'],$name_ar,$name_en,$lat,$lon]); $insC++; }
    }
    $pdo->commit();
    fwrite(STDOUT, "OK districts: +$insC, ~ $updC updates, skipped $skipped\n");
    exit(0);
  }
  throw new RuntimeException('bad_cmd');
}catch(Throwable $e){ fwrite(STDERR, "[ERR] ".$e->getMessage()."\n"); exit(1);} 
